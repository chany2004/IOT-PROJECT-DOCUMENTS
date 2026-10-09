<?php

namespace App\Jobs;

use App\Models\WatcherSmsNotification;
use App\Services\WatcherSmsService;
use App\Support\PhilippineMobile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\{DB, Http};

class SendWatcherSms implements ShouldQueue
{
    use Queueable;

    // Semaphore has no documented idempotency key. Never automatically repeat
    // an uncertain POST; it may already have been accepted and billed.
    public $tries = 1;
    public $timeout = 20;

    public function __construct(public int $notificationId) {}

    public function handle(WatcherSmsService $service): void
    {
        $record = DB::transaction(function () use ($service) {
            $record = WatcherSmsNotification::with('event.patient')->lockForUpdate()->find($this->notificationId);
            if (!$record || $record->status !== 'pending') {
                return null;
            }
            if (config('watcher.channel') !== 'semaphore') {
                $record->update(['status' => 'cancelled', 'status_reason' => 'SMS sending is disabled. Watcher notifications now use Telegram.']);
                return null;
            }
            $event = $record->event;
            if (!$event || $event->is_simulated || $event->event_type !== 'possible_fall'
                || $event->caregiver_outcome !== 'confirmed_fall' || $event->status === 'false_alarm') {
                $record->update(['status' => 'cancelled', 'status_reason' => 'This incident no longer has a confirmed fall outcome.']);
                return null;
            }
            $reason = $service->blockedReason($event->patient);
            if (!$reason && PhilippineMobile::normalize($event->patient->emergency_contact_phone) !== $record->contact_phone) {
                $reason = 'The watcher number changed before sending. Review the current contact and retry.';
            }
            if ($reason) {
                $record->update(['status' => 'blocked', 'status_reason' => $reason]);
                return null;
            }
            $record->update(['status' => 'processing', 'attempted_at' => now(), 'status_reason' => null]);
            return $record;
        });
        if (!$record) {
            return;
        }

        $payload = [
            'apikey' => config('sms.semaphore_api_key'),
            'number' => ltrim($record->contact_phone, '+'),
            'message' => $record->message,
        ];
        if ($sender = trim((string) config('sms.semaphore_sender_name'))) {
            $payload['sendername'] = $sender;
        }

        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(3)->timeout(10)
                ->post('https://api.semaphore.co/api/v4/messages', $payload);
        } catch (ConnectionException $exception) {
            $record->update(['status' => 'unknown', 'status_reason' => 'The provider connection timed out. Check the Semaphore account before sending another SMS.']);
            return;
        }

        if (!$response->successful()) {
            $rejected = $response->clientError() && $response->status() !== 408;
            $record->update([
                'status' => $rejected ? 'failed' : 'unknown',
                'status_reason' => $rejected
                    ? 'The SMS provider rejected this request (HTTP '.$response->status().'). Check credentials, sender name and credits before retrying.'
                    : 'The provider did not confirm this submission. Check the Semaphore account before sending another SMS.',
            ]);
            return;
        }

        $item = $response->json('0');
        $providerStatus = strtolower((string) data_get($item, 'status', ''));
        $messageId = data_get($item, 'message_id');
        $recipient = PhilippineMobile::normalize((string) data_get($item, 'recipient', ''));
        if (!is_array($item) || !is_scalar($messageId) || (string) $messageId === ''
            || $recipient !== $record->contact_phone
            || !in_array($providerStatus, ['queued', 'pending', 'sent', 'failed', 'refunded'], true)) {
            $record->update(['status' => 'unknown', 'status_reason' => 'The provider response could not be verified. Check the Semaphore account before sending another SMS.']);
            return;
        }

        $accepted = in_array($providerStatus, ['queued', 'pending', 'sent'], true);
        $record->update([
            'status' => $accepted ? 'submitted' : 'failed',
            'provider_message_id' => (string) $messageId,
            'provider_status' => $providerStatus,
            'submitted_at' => $accepted ? now() : null,
            'status_reason' => $accepted
                ? 'Accepted by Semaphore. Handset delivery is not confirmed by this application.'
                : 'The SMS was rejected by the network. Check the Semaphore account before retrying.',
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        WatcherSmsNotification::whereKey($this->notificationId)->where('status', 'processing')->update([
            'status' => 'unknown',
            'status_reason' => 'Sending stopped before the result was recorded. Check the Semaphore account before sending another SMS.',
        ]);
    }
}
