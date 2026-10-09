<section class="review-card review-history" id="event-response-history" aria-labelledby="review-history-title">
    <header class="review-card-header">
        <div class="review-section-heading">
            <span class="review-section-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
            <div><span class="review-eyebrow">ACTIVITY LOG</span><h2 id="review-history-title">Response history</h2></div>
        </div>
        <span class="review-history-count">{{ $event->updates->count() }} {{ $event->updates->count() === 1 ? 'response' : 'responses' }}</span>
    </header>
    <ol class="review-timeline">
        <li>
            <span class="review-timeline-marker" aria-hidden="true"></span>
            <div class="review-timeline-heading"><h3>{{ $isManualEmergency ? 'Manual emergency received' : 'Sensor candidate received' }}</h3><time datetime="{{ $event->received_at?->toIso8601String() }}"><x-time :value="$event->received_at"/></time></div>
            <p>Saved from <span class="mono">{{ $event->device->code }}</span>. {{ $isManualEmergency ? 'Caregiver response requested immediately.' : 'A sensor candidate alone is not a confirmed fall.' }}</p>
        </li>
        @if($event->verified_at)
            <li><span class="review-timeline-marker" aria-hidden="true"></span><div class="review-timeline-heading"><h3>{{ $event->assessmentLabel() }}</h3><span><x-time :value="$event->verified_at"/></span></div><p>{{ $event->verification_reason }}</p></li>
        @endif
        @foreach($event->updates as $update)
            <li><span class="review-timeline-marker is-response" aria-hidden="true"></span><div class="review-timeline-heading"><h3>{{ $update->user->name }}</h3><x-badge :value="$update->to_status"/></div><p class="preserve-lines">{{ $update->note }}</p><span class="review-timeline-time"><x-time :value="$update->created_at"/></span></li>
        @endforeach
    </ol>
</section>
