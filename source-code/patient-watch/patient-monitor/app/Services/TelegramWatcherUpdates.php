<?php

namespace App\Services;

use App\Exceptions\TelegramPollingException;
use App\Models\{Patient, WatcherTelegramLink};
use Illuminate\Support\Facades\{Cache, DB};

class TelegramWatcherUpdates
{
    public function __construct(private TelegramBotClient $client, private WatcherTelegramService $service) {}

    public function pollOnce(int $timeout = 5): int
    {
        if (!$this->client->isConfigured()) return 0;
        $botId = $this->client->botId();
        // A separate poller never competes with the camera or send queues.
        $lock = Cache::lock('telegram-watcher-poll:'.$botId, 150);
        if (!$lock->get()) return 0;
        try {
            DB::table('telegram_bot_cursors')->insertOrIgnore(['bot_id' => $botId, 'update_offset' => 0]);
            $cursor = DB::table('telegram_bot_cursors')->where('bot_id', $botId)->first();
            $offset = (int) $cursor->update_offset;
            // Telegram can randomize the next ID after a week without updates.
            // Old updates expire after 24 hours, so a six-day stale cursor may
            // safely start at zero instead of skipping a lower, new update ID.
            if ($offset && (!$cursor->last_update_at || \Carbon\Carbon::parse($cursor->last_update_at)->lt(now()->subDays(6)))) {
                $offset = 0;
            }
            $pollTimeout = max(0, min(20, $timeout));
            try {
                $response = $this->client->request('getUpdates', [
                    'offset' => $offset, 'timeout' => $pollTimeout, 'limit' => 10,
                    'allowed_updates' => ['message'],
                ], max(15, $pollTimeout + 15));
                $body = $response->json();
            } catch (\Throwable $exception) {
                // Keep only safe diagnostics, never the token-bearing URL/message.
                $transportError = null;
                for ($cause = $exception; $cause; $cause = $cause->getPrevious()) {
                    if (method_exists($cause, 'getHandlerContext')) {
                        $context = $cause->getHandlerContext();
                        if (is_int($context['errno'] ?? null)) $transportError = $context['errno'];
                        break;
                    }
                }
                throw new TelegramPollingException(null, class_basename($exception), $transportError);
            }
            if (!$response->successful() || data_get($body, 'ok') !== true || !is_array(data_get($body, 'result'))) {
                throw new TelegramPollingException($response->status());
            }
            $updates = $body['result'];
            usort($updates, fn ($a, $b) => (int) data_get($a, 'update_id', -1) <=> (int) data_get($b, 'update_id', -1));
            $count = 0;
            foreach ($updates as $update) {
                $id = data_get($update, 'update_id');
                if (!is_int($id) || $id < $offset) continue;
                $reply = $this->handleUpdate($update);
                DB::table('telegram_bot_cursors')->where('bot_id', $botId)->update(['update_offset' => $id + 1, 'last_update_at' => now()]);
                $offset = $id + 1;
                $count++;
                if ($reply !== null) {
                    try {
                        // Replies contain no patient details, even for invalid links.
                        $this->client->sendMessage((string) data_get($update, 'message.chat.id'), $reply);
                    } catch (\Throwable $exception) {
                        // Registration is already committed. Never log token-bearing URLs.
                    }
                }
            }
            return $count;
        } finally {
            $lock->release();
        }
    }

    public function handleUpdate(array $update): ?string
    {
        $message = $update['message'] ?? null;
        $chatId = (string) data_get($message, 'chat.id', '');
        if (!$this->client->isConfigured() || !is_array($message)
            || data_get($message, 'chat.type') !== 'private'
            || !preg_match('/^[1-9][0-9]{0,19}$/D', $chatId)
            || (string) data_get($message, 'from.id') !== $chatId
            || data_get($message, 'from.is_bot') !== false) return null;

        $text = trim((string) ($message['text'] ?? ''));
        if ($text === '/stop') {
            $patientIds = Patient::where('watcher_telegram_chat_id', $chatId)
                ->where('watcher_telegram_bot_id', $this->client->botId())->pluck('id');
            foreach ($patientIds as $patientId) {
                $this->service->withConnectionLock($patientId, fn () => DB::transaction(function () use ($patientId, $chatId) {
                    $patient = Patient::whereKey($patientId)->lockForUpdate()->first();
                    // A /stop from the previous account must not disconnect a
                    // replacement that connected while this update was waiting.
                    if (!$patient || $patient->watcher_telegram_chat_id !== $chatId
                        || $patient->watcher_telegram_bot_id !== $this->client->botId()) return;
                    $this->service->disconnect($patient);
                    $patient->update(['watcher_telegram_enabled' => false]);
                }));
            }
            return 'Patient Watch alerts stopped for this Telegram account. Contact the care team if you want to connect again.';
        }
        if ($text === '/start' || $text === '/help') {
            return 'Patient Watch: ask the care team for your private Connect watcher link, then open it and press Start. Alerts are sent only after care staff confirm a fall. Use /stop to disconnect.';
        }
        if (!preg_match('/^\/start pw_([A-Za-z0-9]{48})$/D', $text, $matches)) return null;
        $hash = hash('sha256', $matches[1]);
        $candidate = WatcherTelegramLink::where('token_hash', $hash)->first();
        if (!$candidate) return 'This connection link is invalid or expired. Ask the care team for a new private link.';
        $connected = $this->service->withConnectionLock($candidate->patient_id, fn () => DB::transaction(function () use ($candidate, $hash, $chatId, $message) {
            // Always lock patient before link, matching profile and connect edits.
            $patient = Patient::whereKey($candidate->patient_id)->lockForUpdate()->first();
            $link = WatcherTelegramLink::whereKey($candidate->id)->where('token_hash', $hash)->lockForUpdate()->first();
            if (!$patient || !$patient->is_active || !$patient->watcher_telegram_enabled
                || !$patient->emergency_contact_name || !$link || $link->consumed_at
                || $link->expires_at->isPast() || $link->bot_id !== $this->client->botId()
                || ($patient->watcher_telegram_linked_at && $patient->watcher_telegram_bot_id === $this->client->botId())) return false;
            if ($link->excluded_chat_hash && hash_equals($link->excluded_chat_hash,
                $this->service->recipientFingerprint($this->client->botId(), $chatId))) {
                return 'previous_account';
            }
            $patient->update(['watcher_telegram_chat_id' => $chatId, 'watcher_telegram_bot_id' => $this->client->botId(),
                'watcher_telegram_username' => mb_substr((string) data_get($message, 'from.username', ''), 0, 64) ?: null,
                'watcher_telegram_linked_at' => now()]);
            $link->update(['consumed_at' => now()]);
            return true;
        }));
        if ($connected === 'previous_account') {
            return 'This is still the previous Telegram account. Switch to the NEW account and send the same private Start command. The link remains valid until it expires.';
        }
        return $connected === true
            ? 'Connected to Patient Watch. You will receive an alert here after care staff confirm a fall. Keep Telegram notifications enabled. Use /stop to disconnect.'
            : 'This connection link is invalid or expired. Ask the care team for a new private link.';
    }
}
