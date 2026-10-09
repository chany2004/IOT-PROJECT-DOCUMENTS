<section class="review-card review-details" aria-labelledby="review-details-title">
    <header class="review-card-header">
        <div class="review-section-heading">
            <span class="review-section-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 3H5v18h14V3h-3M9 2h6v4H9V2ZM8 11h8m-8 4h5"/></svg></span>
            <div><span class="review-eyebrow">INCIDENT INFORMATION</span><h2 id="review-details-title">Event details</h2></div>
        </div>
    </header>
    <div class="review-patient">
        <span class="review-patient-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($event->patient->full_name, 0, 1)) }}</span>
        <div><span>Patient</span><a href="{{ route('patients.show', $event->patient) }}">{{ $event->patient->full_name }}</a><p>{{ $event->room ? 'Room '.$event->room->number : 'Room unassigned' }}</p></div>
        <a class="review-patient-link" href="{{ route('patients.show', $event->patient) }}" aria-label="Open patient record for {{ $event->patient->full_name }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6"/></svg></a>
    </div>
    <div class="review-details-body">
        <dl class="review-timing">
            <div><dt>Detected</dt><dd><x-time :value="$event->detected_at"/></dd></div>
            <div><dt>Received</dt><dd><x-time :value="$event->received_at"/></dd></div>
        </dl>
        <dl class="review-detail-list">
            <div><dt>Main sensor</dt><dd class="mono">{{ $event->device->code }}</dd></div>
            <div><dt>Camera assessment</dt><dd>{{ $event->assessmentLabel() }}</dd></div>
            <div><dt>Caregiver outcome</dt><dd>{{ $event->caregiver_outcome ? ucfirst(str_replace('_', ' ', $event->caregiver_outcome)) : 'Not recorded' }}</dd></div>
            <div><dt>Acknowledged by</dt><dd>{{ $event->acknowledger?->name ?? 'Awaiting response' }}</dd></div>
        </dl>
        <div class="review-sensor-heading"><h3>Sensor readings</h3><span>{{ $isManualEmergency ? 'Manual alert' : 'At detection' }}</span></div>
        <dl class="review-sensor-values">
            <div><dt>Acceleration magnitude</dt><dd>{{ $event->reading ? number_format($event->reading->acceleration_g, 3) : '—' }} @if($event->reading)<small>g</small>@endif</dd></div>
            <div><dt>Angular velocity magnitude</dt><dd>{{ $event->reading ? number_format($event->reading->gyro_rad_s, 3) : '—' }} @if($event->reading)<small>rad/s</small>@endif</dd></div>
        </dl>
        @unless($event->reading)<p class="review-sensor-help">{{ $isManualEmergency ? 'Motion readings are not required for a manual emergency.' : 'No motion readings were recorded for this event.' }}</p>@endunless
        <div class="review-record-id"><span>Event identifier</span><code>{{ $event->public_id }}</code></div>
    </div>
</section>
