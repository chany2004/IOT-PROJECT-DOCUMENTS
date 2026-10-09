<?php

namespace App\Services;

use App\Jobs\SendWatcherSms;
use App\Models\{FallEvent, Patient, User, WatcherSmsNotification};
use App\Support\PhilippineMobile;
use Illuminate\Support\Str;

class WatcherSmsService
{
    public function isConfigured(): bool
    {
        return (bool) config('sms.enabled') && trim((string) config('sms.semaphore_api_key')) !== '';
    }

    public function blockedReason(?Patient $patient): ?string
    {
        if (!$patient || !$patient->emergency_contact_name || !PhilippineMobile::normalize($patient->emergency_contact_phone)) {
            return 'Add the watcher name and a valid Philippine mobile number to the patient profile.';
        }
        if (!$patient->emergency_sms_enabled) {
            return 'Watcher SMS notifications are disabled in the patient profile.';
        }
        if (!$this->isConfigured()) {
            return 'SMS service is not configured. Contact the administrator to enable it.';
        }

        return null;
    }

    // Called while the incident is locked in a database transaction. The unique
    // event key makes repeated confirmations produce a single notification.
    public function queueForConfirmedFall(FallEvent $event, User $confirmer): void
    {
        if ($event->is_simulated || $event->event_type !== 'possible_fall'
            || $event->caregiver_outcome !== 'confirmed_fall' || $event->status === 'false_alarm'
            || $event->watcherSms()->exists()) {
            return;
        }

        $this->prepare($event, $confirmer);
    }

    public function prepare(FallEvent $event, User $confirmer, ?WatcherSmsNotification $record = null): WatcherSmsNotification
    {
        $event->load(['patient', 'room']);
        $reason = $this->blockedReason($event->patient);
        $record ??= new WatcherSmsNotification(['fall_event_id' => $event->id, 'confirmed_by' => $confirmer->id]);
        $record->fill([
            'contact_name' => $event->patient?->emergency_contact_name,
            'contact_phone' => PhilippineMobile::normalize($event->patient?->emergency_contact_phone),
            'message' => $this->message($event),
            'status' => $reason ? 'blocked' : 'pending',
            'status_reason' => $reason,
        ])->save();
        if (!$reason) {
            SendWatcherSms::dispatch($record->id)->onQueue('notifications')->afterCommit();
        }

        return $record;
    }

    private function message(FallEvent $event): string
    {
        $name = Str::limit(preg_replace('/\s+/', ' ', $event->patient->full_name), 60);
        $room = $event->room ? ' in Room '.Str::limit(preg_replace('/\s+/', ' ', $event->room->number), 20) : '';
        $time = $event->detected_at->copy()->timezone(config('monitor.display_timezone', 'Asia/Manila'))->format('M d, g:i A');

        return "Patient Watch: A fall for {$name}{$room}, detected {$time} (PH time), was confirmed by care staff. Please contact the care team now.";
    }
}
