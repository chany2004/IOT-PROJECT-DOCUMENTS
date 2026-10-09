<section class="review-card review-evidence" aria-labelledby="review-evidence-title">
    <header class="review-card-header">
        <div class="review-section-heading">
            <span class="review-section-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 6h4l2-3h4l2 3h4v14H4V6Z"/><circle cx="12" cy="12" r="3.5"/></svg></span>
            <div><span class="review-eyebrow">VISUAL EVIDENCE</span><h2 id="review-evidence-title">Camera evidence</h2></div>
        </div>
        <x-badge :value="$cameraDisplayStatus"/>
    </header>
    <div class="review-evidence-body">
        @if($hasCameraImage)
            <figure class="review-photo">
                <button class="review-photo-frame" type="button" data-camera-image-viewer data-camera-image-title="{{ $event->patient->full_name }} - camera image" aria-label="View full camera image for {{ $event->patient->full_name }}">
                    <img class="camera-image" src="{{ route('events.image', $event) }}" alt="Camera snapshot for this patient event">
                    <span class="review-photo-action"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3H3v5m13-5h5v5M3 16v5h5m13-5v5h-5"/></svg> View full image</span>
                </button>
                <figcaption><span class="review-capture-dot" aria-hidden="true"></span><span>Captured <x-time :value="$event->camera->captured_at"/></span><span class="review-timezone">UTC+8</span></figcaption>
            </figure>
        @else
            <div @class(['review-camera-empty', 'is-pending' => $cameraDisplayStatus === 'pending']) role="status">
                <span class="review-empty-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 6h4l2-3h4l2 3h4v14H4V6Z"/><circle cx="12" cy="12" r="3.5"/></svg></span>
                <h3>{{ match($cameraDisplayStatus) { 'pending' => 'Capturing snapshot…', 'incomplete' => 'Incomplete camera image', 'unavailable' => 'Camera unavailable', 'timeout' => 'Snapshot unavailable', default => 'No snapshot available' } }}</h3>
                <p>{{ $cameraDisplayStatus === 'incomplete' ? 'The complete image did not arrive. Check the patient and review a new capture after checking the camera connection.' : ($event->camera?->result ?? 'No camera snapshot was requested for this alert.') }}</p>
            </div>
        @endif
        <div class="review-evidence-meta">
            <div><span>Camera device</span><strong class="mono">{{ $event->camera?->device?->code ?? 'Not assigned' }}</strong></div>
            <div><span>Automated verdict</span><strong>{{ $event->camera?->verdict ? ucfirst(str_replace('_', ' ', $event->camera->verdict)) : ($isManualEmergency ? 'Not required' : 'Not available') }}</strong></div>
            <div><span>Pose model score</span><strong>{{ $event->camera?->confidence !== null ? number_format((float) $event->camera->confidence * 100, 1).'%' : ($isManualEmergency ? 'Not applicable' : 'Not available') }}</strong></div>
        </div>
        @if($hasCameraImage && $event->camera?->result)<p class="review-camera-result">{{ $event->camera->result }}</p>@endif
        <p class="review-evidence-note"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 8 3v6c0 4-4 7-8 9-4-2-8-5-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/></svg> Supporting evidence only. The caregiver's in-person check is the final decision.</p>
    </div>
</section>
