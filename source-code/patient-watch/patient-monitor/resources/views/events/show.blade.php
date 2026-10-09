@extends('layouts.app')
@section('title', 'Event review')
@section('content')
@php
    $isManualEmergency = $event->event_type === 'manual_emergency';
    $isClosed = in_array($event->status, ['resolved', 'false_alarm']);
    $hasCameraImage = $event->camera?->hasDisplayableImage() ?? false;
    $cameraFrameFailed = $event->camera?->isFrameAcquisitionFailure() ?? false;
    $cameraDisplayStatus = $event->camera?->status === 'captured' && !$hasCameraImage
        ? 'incomplete' : ($cameraFrameFailed ? 'unavailable' : ($event->camera?->status ?? 'not_requested'));
    $assessment = match($event->verification_status) {
        'verifying' => ['title' => 'Camera verification in progress', 'copy' => 'Review the patient while the camera checks this sensor event.', 'class' => 'verifying'],
        'likely_fall' => ['title' => 'Likely fall — immediate response', 'copy' => 'Check the patient, review the evidence, and record the final caregiver outcome.', 'class' => 'danger'],
        'unlikely_fall' => ['title' => 'Camera indicates no fall', 'copy' => 'Review the image and check the patient before closing this incident as a false alarm.', 'class' => 'clear'],
        'inconclusive' => ['title' => 'Camera assessment needs review', 'copy' => 'The camera could not rule out a fall. Check the patient and record your findings.', 'class' => 'warning'],
        default => ['title' => 'Manual emergency — immediate response', 'copy' => 'Check the patient immediately. The snapshot supports your review; it does not determine the emergency.', 'class' => 'danger'],
    };
    if ($isClosed) {
        $assessment = ['title' => 'Incident closed — response recorded',
            'copy' => 'The evidence and caregiver response are preserved in this incident record.', 'class' => 'clear'];
    }
@endphp
<article class="event-review">
    <nav class="review-navigation" aria-label="Event navigation">
        <a href="{{ route('events.index') }}" class="review-back"><span aria-hidden="true">&larr;</span> All events</a>
        <a href="#event-response-history" class="review-history-link">Response history <span aria-hidden="true">&darr;</span></a>
    </nav>
    <header class="review-page-heading">
        <div>
            <span class="review-eyebrow">INCIDENT REVIEW <span aria-hidden="true">/</span> #{{ substr($event->public_id, 0, 8) }}</span>
            <h1>{{ $event->assessmentLabel() }}</h1>
            <p>{{ $event->patient->full_name }} <span aria-hidden="true">&middot;</span> {{ $event->room ? 'Room '.$event->room->number : 'Room not assigned at receipt' }}</p>
        </div>
        <div class="review-status-group"><span>Response status</span><x-badge :value="$event->status"/></div>
    </header>
    <section class="review-assessment review-assessment-{{ $assessment['class'] }}" aria-labelledby="review-assessment-title">
        <span class="review-assessment-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m12 3 10 17H2L12 3Z"/><path d="M12 9v4m0 3h.01"/></svg></span>
        <div><h2 id="review-assessment-title">{{ $assessment['title'] }}</h2><p>{{ $assessment['copy'] }}</p></div>
        <x-badge :value="$event->verification_status"/>
    </section>
    <div class="review-layout">
        <div class="review-column">
            @include('events.partials.review-evidence')
            @include('events.partials.review-history')
        </div>
        <div class="review-column">
            @include('events.partials.review-details')
            @include('events.partials.review-response')
            @if(config('watcher.channel') === 'semaphore')
                @include('events.partials.review-watcher-sms')
            @else
                @include('events.partials.review-watcher-telegram')
            @endif
        </div>
    </div>
</article>
@endsection
