<?php

namespace App\Jobs;

use App\Models\WatcherTelegramNotification;
use App\Services\{TelegramBotClient, WatcherTelegramService};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class SendWatcherTelegram implements ShouldQueue
{
    use Queueable;

    // Telegram sendMessage has no idempotency key. Do not retry uncertain sends.
    public $tries = 1;
    public $timeout = 20;

    public function __construct(public int $notificationId) {}

    public function handle(WatcherTelegramService $service, TelegramBotClient $client): void
    {
        $patientId = WatcherTelegramNotification::find($this->notificationId)?->event?->patient_id;
        $service->withConnectionLock($patientId, fn () => $this->sendForCurrentWatcher($service, $client));
    }

    private function sendForCurrentWatcher(WatcherTelegramService $service, TelegramBotClient $client): void
    {
        $record = DB::transaction(function () use ($service, $client) {
            $record = WatcherTelegramNotification::with('event.patient')->lockForUpdate()->find($this->notificationId);
            if (!$record || $record->status !== 'pending') return null;
            $event = $record->event;
            if (!$event || !$service->canNotify($event)) {
                $record->update(['status' => 'cancelled', 'status_reason' => 'This incident no longer has a confirmed fall outcome.']);
                return null;
            }
            $reason = $service->blockedReason($event->patient);
            if (!$reason && ($event->patient->watcher_telegram_chat_id !== $record->chat_id
                || $record->bot_id !== $client->botId())) {
                $reason = 'The watcher connection changed before sending. Review the current watcher and retry.';
            }
            if ($reason) {
                $record->update(['status' => 'blocked', 'status_reason' => $reason]);
                return null;
            }
            $record->update(['status' => 'processing', 'attempted_at' => now(), 'status_reason' => null]);
            return $record;
        });
        if (!$record) return;

        try {
            $response = $client->sendMessage($record->chat_id, $record->message);
            $body = $response->json();
        } catch (\Throwable $exception) {
            // Do not persist the exception text: it can contain the bot token.
            $record->update(['status' => 'unknown', 'status_reason' => 'Telegram did not confirm the result. Check the watcher chat before sending another alert.']);
            return;
        }
        if (is_array($body) && ($body['ok'] ?? null) === false && $response->status() < 500
            && $response->status() !== 408 && is_int($body['error_code'] ?? null)) {
            $record->update(['status' => 'failed', 'status_reason' => 'Telegram rejected the request (code '.$body['error_code'].'). Check bot setup and ask the watcher to unblock or restart the bot before retrying.']);
            return;
        }
        $messageId = data_get($body, 'result.message_id');
        if (!$response->successful() || data_get($body, 'ok') !== true
            || !is_int($messageId) || $messageId < 1
            || (string) data_get($body, 'result.chat.id') !== $record->chat_id
            || data_get($body, 'result.chat.type') !== 'private') {
            $record->update(['status' => 'unknown', 'status_reason' => 'The Telegram response could not be verified. Check the watcher chat before sending another alert.']);
            return;
        }
        $record->update([
            'status' => 'submitted', 'telegram_message_id' => (string) $messageId, 'submitted_at' => now(),
            'status_reason' => 'Telegram accepted this message. This does not confirm the watcher received a phone notification or read it.',
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        WatcherTelegramNotification::whereKey($this->notificationId)->where('status', 'processing')->update([
            'status' => 'unknown', 'status_reason' => 'Sending stopped before the result was recorded. Check the watcher chat before sending another alert.',
        ]);
    }
}
