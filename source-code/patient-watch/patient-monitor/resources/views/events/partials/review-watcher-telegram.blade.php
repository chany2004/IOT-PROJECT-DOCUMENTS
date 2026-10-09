@php
    $watcherTelegram = $event->watcherTelegram;
    $telegramService = app(\App\Services\WatcherTelegramService::class);
    $currentBlockReason = $telegramService->blockedReason($event->patient);
    $canRetryTelegram = $watcherTelegram?->canRetry() && $telegramService->canNotify($event) && !$currentBlockReason;
    $readyToSend = $watcherTelegram?->status === 'blocked' && $canRetryTelegram;
@endphp
<section class="review-card" aria-labelledby="review-watcher-telegram-title">
    <header class="review-card-header">
        <div class="review-section-heading">
            <span class="review-section-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m3 11 18-8-6 18-4-7-8-3Zm8 3 10-11"/></svg></span>
            <div><span class="review-eyebrow">WATCHER FOLLOW-UP</span><h2 id="review-watcher-telegram-title">Telegram notification</h2></div>
        </div>
    </header>
    <div class="review-details-body">
        <dl class="review-detail-list">
            <div><dt>Watcher</dt><dd>{{ $watcherTelegram?->contact_name ?? $event->patient->emergency_contact_name ?? 'Not provided' }}</dd></div>
            <div><dt>Notification status</dt><dd>{{ $readyToSend ? 'Ready to send' : ($watcherTelegram?->statusLabel() ?? 'Waiting for fall confirmation') }}</dd></div>
            @if($watcherTelegram)
                <div><dt>Confirmed by</dt><dd>{{ $watcherTelegram->confirmer?->name ?? 'Care staff' }}</dd></div>
                @if($watcherTelegram->submitted_at)<div><dt>Submitted</dt><dd><x-time :value="$watcherTelegram->submitted_at"/></dd></div>@endif
                @if($watcherTelegram->telegram_message_id)<div><dt>Message reference</dt><dd class="mono">{{ $watcherTelegram->telegram_message_id }}</dd></div>@endif
            @endif
        </dl>
        @if($readyToSend)
            <p class="review-sensor-help" role="status">The watcher is now connected. Retry the notification below to send this saved confirmed-fall alert. New confirmed responses notify automatically when saved.</p>
        @elseif($watcherTelegram?->status === 'blocked' && $currentBlockReason)
            <p class="review-sensor-help" role="status">{{ $currentBlockReason }}</p>
        @elseif($watcherTelegram?->status_reason)
            <p class="review-sensor-help" role="status">{{ $watcherTelegram->status_reason }}</p>
        @elseif($event->status === 'false_alarm')
            <p class="review-sensor-help">No Telegram alert is sent for a false alarm.</p>
        @else
            <p class="review-sensor-help">After checking the patient, select Fall confirmed and save your response. Sensor detection and camera assessment alone do not notify the watcher.</p>
        @endif
        @if($canRetryTelegram)
            <form method="post" action="{{ route('events.watcher-telegram.retry', $event) }}" class="form-actions">
                @csrf
                <button class="button subtle small" type="submit">Retry Telegram notification</button>
            </form>
        @endif
        <a class="text-link" href="{{ route('patients.show', $event->patient) }}#watcher-telegram">Review watcher connection &rarr;</a>
        @if($watcherTelegram && in_array($watcherTelegram->status, ['pending', 'processing'], true))
            <p class="review-sensor-help">Refresh this incident to see the latest notification status.</p>
        @endif
    </div>
</section>
