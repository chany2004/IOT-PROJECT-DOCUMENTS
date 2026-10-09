@unless($isManualEmergency)
    @php
        $watcherSms = $event->watcherSms;
        $smsService = app(\App\Services\WatcherSmsService::class);
    @endphp
    <section class="review-card review-watcher-sms" aria-labelledby="review-watcher-sms-title">
        <header class="review-card-header">
            <div class="review-section-heading">
                <span class="review-section-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 4h16v12H9l-5 4V4Zm4 5h8m-8 3h5"/></svg></span>
                <div><span class="review-eyebrow">EMERGENCY CONTACT</span><h2 id="review-watcher-sms-title">Watcher SMS notification</h2></div>
            </div>
        </header>
        <div class="review-details-body">
            <dl class="review-detail-list">
                <div><dt>Watcher</dt><dd>{{ $watcherSms?->contact_name ?? $event->patient->emergency_contact_name ?? 'Not provided' }}</dd></div>
                <div><dt>Mobile number</dt><dd>{{ $watcherSms?->contact_phone ?? $event->patient->emergency_contact_phone ?? 'Not provided' }}</dd></div>
                <div><dt>SMS status</dt><dd>{{ $watcherSms?->statusLabel() ?? 'Waiting for fall confirmation' }}</dd></div>
                @if($watcherSms)
                    <div><dt>Confirmed by</dt><dd>{{ $watcherSms->confirmer?->name ?? 'Care staff' }}</dd></div>
                    @if($watcherSms->submitted_at)<div><dt>Submitted</dt><dd><x-time :value="$watcherSms->submitted_at"/></dd></div>@endif
                    @if($watcherSms->provider_message_id)<div><dt>Provider reference</dt><dd class="mono">{{ $watcherSms->provider_message_id }}</dd></div>@endif
                @endif
            </dl>
            @if($watcherSms?->status_reason)
                <p class="review-sensor-help" role="status">{{ $watcherSms->status_reason }}</p>
            @elseif($event->status === 'false_alarm')
                <p class="review-sensor-help">No SMS is sent for a false alarm.</p>
            @else
                <p class="review-sensor-help">After checking the patient, select Fall confirmed in your response and save. A sensor alert or camera assessment alone does not notify the watcher.</p>
            @endif
            @if($watcherSms?->canRetry() && $event->caregiver_outcome === 'confirmed_fall' && $event->status !== 'false_alarm' && !$smsService->blockedReason($event->patient))
                <form method="post" action="{{ route('events.watcher-sms.retry', $event) }}" class="form-actions">
                    @csrf
                    <button class="button subtle small" type="submit">Retry watcher SMS</button>
                </form>
            @endif
            @if(auth()->user()->isAdmin() && (!$event->patient->emergency_contact_phone || !$event->patient->emergency_sms_enabled))
                <a class="text-link" href="{{ route('patients.edit', $event->patient) }}">Set up watcher contact &rarr;</a>
            @endif
            @if($watcherSms && in_array($watcherSms->status, ['pending', 'processing'], true))
                <p class="review-sensor-help">Refresh this incident to see the latest SMS status.</p>
            @endif
        </div>
    </section>
@endunless
