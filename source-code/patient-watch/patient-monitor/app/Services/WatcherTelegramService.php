<?php

namespace App\Services;

use App\Jobs\SendWatcherTelegram;
use App\Models\{FallEvent, Patient, User, WatcherTelegramLink, WatcherTelegramNotification};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WatcherTelegramService
{
    public function __construct(private TelegramBotClient $client) {}

    public function withConnectionLock(?int $patientId, callable $callback): mixed
    {
        if (!$patientId) return $callback();
        // Session-level, per-patient lock spans the short claim transaction and
        // Telegram request without holding patient rows or delaying sensor inserts.
        $connection = DB::connection();
        $key = 'patient-watch:telegram-recipient:'.$patientId;
        $connection->select('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$key], false);
        try {
            return $callback();
        } finally {
            $connection->select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$key], false);
        }
    }

    public function recipientFingerprint(string $botId, string $chatId): string
    {
        return hash('sha256', $botId.':'.$chatId);
    }

    // Call inside withConnectionLock and a transaction with the patient locked.
    public function disconnect(Patient $patient, bool $replacingAccount = false, ?User $actor = null): void
    {
        $link = WatcherTelegramLink::where('patient_id', $patient->id)->first();
        $botId = $patient->watcher_telegram_bot_id;
        $excludedHash = $patient->watcher_telegram_chat_id
            ? ($replacingAccount && $botId ? $this->recipientFingerprint($botId, $patient->watcher_telegram_chat_id) : null)
            : $link?->excluded_chat_hash;
        // Imported connections may have no invitation row yet. Persist a
        // consumed placeholder under the actual staff actor, not an arbitrary user.
        if ($excludedHash && !$link) {
            if (!$actor) throw new \LogicException('Replacing an imported watcher requires the authenticated staff actor.');
            $link = WatcherTelegramLink::create(['patient_id' => $patient->id, 'created_by' => $actor->id,
                'token_hash' => hash('sha256', Str::random(64)), 'bot_id' => $botId,
                'expires_at' => now(), 'consumed_at' => now(), 'excluded_chat_hash' => $excludedHash]);
        }
        $patient->update(['watcher_telegram_chat_id' => null, 'watcher_telegram_bot_id' => null,
            'watcher_telegram_username' => null, 'watcher_telegram_linked_at' => null]);
        WatcherTelegramNotification::whereHas('event', fn ($query) => $query->where('patient_id', $patient->id))
            ->where('status', 'pending')->update(['status' => 'blocked',
                'status_reason' => 'The watcher connection changed. Connect the current watcher and review this alert before retrying.']);
        $link?->update(['consumed_at' => now(), 'excluded_chat_hash' => $excludedHash]);
    }

    public function isConnected(Patient $patient): bool
    {
        return $this->client->isConfigured() && $patient->watcher_telegram_linked_at
            && preg_match('/^[1-9][0-9]{0,19}$/D', (string) $patient->watcher_telegram_chat_id)
            && $patient->watcher_telegram_bot_id === $this->client->botId();
    }

    public function blockedReason(?Patient $patient): ?string
    {
        if (!$patient || !$patient->is_active || !$patient->emergency_contact_name) {
            return 'Add the watcher name to an active patient profile.';
        }
        if (!$patient->watcher_telegram_enabled) {
            return 'Telegram alerts are disabled for this watcher.';
        }
        if (!$this->client->isConfigured()) {
            return 'Telegram bot is not configured. Contact the administrator to complete setup.';
        }
        if (!$this->isConnected($patient)) {
            return 'Connect the watcher in the patient profile, then ask them to open the private Telegram link and press Start.';
        }
        return null;
    }

    public function canNotify(FallEvent $event): bool
    {
        return !$event->is_simulated
            && in_array($event->event_type, ['possible_fall', 'manual_emergency'], true)
            && $event->caregiver_outcome === 'confirmed_fall'
            && $event->status !== 'false_alarm';
    }

    public function queueForConfirmedFall(FallEvent $event, User $confirmer): ?WatcherTelegramNotification
    {
        if (!$this->canNotify($event)) return null;

        $record = $event->watcherTelegram()->lockForUpdate()->first();
        // A blocked notification was never submitted. Saving again after setup
        // may queue it; pending, sent and uncertain alerts must not be duplicated.
        if ($record && $record->status !== 'blocked') return $record;

        return $this->prepare($event, $confirmer, $record);
    }

    public function prepare(FallEvent $event, User $confirmer, ?WatcherTelegramNotification $record = null): WatcherTelegramNotification
    {
        $event->load(['patient', 'room']);
        $reason = $this->blockedReason($event->patient);
        $record ??= new WatcherTelegramNotification(['fall_event_id' => $event->id, 'confirmed_by' => $confirmer->id]);
        $name = Str::limit(preg_replace('/\s+/', ' ', $event->patient?->full_name ?? 'Patient'), 100);
        $room = $event->room ? "\nLocation: Room ".Str::limit($event->room->number, 32) : '';
        $time = $event->detected_at->copy()->timezone(config('monitor.display_timezone', 'Asia/Manila'))->format('M d, Y g:i A');
        $record->fill([
            'contact_name' => $event->patient?->emergency_contact_name,
            'chat_id' => $event->patient?->watcher_telegram_chat_id,
            'bot_id' => $event->patient?->watcher_telegram_bot_id,
            'message' => "PATIENT WATCH - CONFIRMED FALL\nPatient: {$name}{$room}\nDetected: {$time} (PH time)\nAn administrator or caregiver has checked the patient and confirmed a fall. Please contact the care team now.",
            'status' => $reason ? 'blocked' : 'pending', 'status_reason' => $reason,
        ])->save();
        if (!$reason) SendWatcherTelegram::dispatch($record->id)->onQueue('notifications')->afterCommit();
        return $record;
    }
}
