@extends('layouts.app')
@section('title', 'Alerts & history')
@section('content')
@php
    $filterKeys = ['q', 'patient_id', 'type', 'status', 'assessment', 'from', 'to'];
    $activeCount = collect(request()->only($filterKeys))->filter(fn ($value) => filled($value))->count();
    $advancedCount = collect(request()->only(['patient_id', 'type', 'status', 'assessment', 'from', 'to']))->filter(fn ($value) => filled($value))->count();
    $patientFilter = $patients->firstWhere('id', request('patient_id'));
    $activeLabels = [
        'q' => filled(request('q')) ? 'Search: '.request('q') : null,
        'patient_id' => filled(request('patient_id')) ? 'Patient: '.($patientFilter?->full_name ?? 'Selected patient') : null,
        'type' => filled(request('type')) ? 'Type: '.ucfirst(str_replace('_', ' ', request('type'))) : null,
        'status' => filled(request('status')) ? 'Status: '.ucfirst(str_replace('_', ' ', request('status'))) : null,
        'assessment' => filled(request('assessment')) ? 'Assessment: '.ucfirst(str_replace('_', ' ', request('assessment'))) : null,
        'from' => filled(request('from')) ? 'From: '.request('from') : null,
        'to' => filled(request('to')) ? 'Through: '.request('to') : null,
    ];
@endphp
<div class="event-history">
    <div class="page-heading event-page-heading">
        <div><div class="eyebrow">INCIDENT REGISTER</div><h1>Alerts & history</h1><p>Find an event, review the evidence, and follow its response history.</p></div>
    </div>

    <form class="event-query-form" method="get" action="{{ route('events.index') }}" role="search" aria-label="Search and filter alert history">
        <section class="panel event-search-panel" aria-label="Search alert history">
            <div class="event-filter-intro">
                <div><span class="eyebrow">FIND AN INCIDENT</span><h2>Search your alert records</h2></div>
                <span class="event-filter-total">{{ number_format($events->total()) }} {{ $events->total() === 1 ? 'record' : 'records' }}</span>
            </div>
            <label class="event-search-label" for="event-search">Patient, incident ID, or device</label>
            <div class="event-search-field">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"></circle><path d="m16 16 4 4"></path></svg>
                <input id="event-search" type="search" name="q" value="{{ request('q') }}" maxlength="100" placeholder="Search by name, patient ID, incident ID, or device" aria-describedby="event-search-help">
                @if(filled(request('q')))<a class="event-search-clear" href="{{ route('events.index', request()->except(['q', 'page'])) }}" aria-label="Clear search">&times;</a>@endif
                <button type="submit" class="button event-search-submit">Search alerts <span aria-hidden="true">&rarr;</span></button>
            </div>
            <div class="event-search-meta">
                <p id="event-search-help">Searches all records, not just this page.</p>
                <span>Press Enter to search</span>
            </div>
        </section>

        <section class="panel event-filter-panel" aria-labelledby="event-filter-title">
            <div class="event-filter-heading">
                <span class="event-filter-summary-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 7h16M7 12h10M10 17h4"></path></svg></span>
                <div><h2 id="event-filter-title">Filter alert records</h2><p>Refine by patient, event, status, camera assessment, or date.</p></div>
                @if($advancedCount > 0)<span class="event-filter-count">{{ $advancedCount }} active</span>@endif
            </div>
            <div class="event-advanced-body">
                <div class="event-filter-fields">
                    <label>Patient<select name="patient_id"><option value="">All assigned patients</option>@foreach($patients as $patient)<option value="{{ $patient->id }}" @selected(request('patient_id') == $patient->id)>{{ $patient->full_name }}</option>@endforeach</select></label>
                    <label>Event type<select name="type"><option value="">All types</option>@foreach(['possible_fall', 'manual_emergency'] as $type)<option @selected(request('type') === $type) value="{{ $type }}">{{ ucfirst(str_replace('_', ' ', $type)) }}</option>@endforeach</select></label>
                    <label>Status<select name="status"><option value="">All statuses</option>@foreach(['pending', 'acknowledged', 'resolved', 'false_alarm'] as $status)<option @selected(request('status') === $status) value="{{ $status }}">{{ ucfirst(str_replace('_', ' ', $status)) }}</option>@endforeach</select></label>
                    <label>Camera assessment<select name="assessment"><option value="">All assessments</option>@foreach(['verifying', 'likely_fall', 'unlikely_fall', 'inconclusive', 'not_required'] as $assessment)<option @selected(request('assessment') === $assessment) value="{{ $assessment }}">{{ ucfirst(str_replace('_', ' ', $assessment)) }}</option>@endforeach</select></label>
                    <label>From<input type="date" name="from" value="{{ request('from') }}"></label>
                    <label>Through<input type="date" name="to" value="{{ request('to') }}" @if(filled(request('from'))) min="{{ request('from') }}" @endif></label>
                </div>
                <div class="event-filter-actions"><span>Dates use Philippine time (UTC+8).</span><div><a href="{{ route('events.index') }}" class="button subtle">Clear all</a><button class="button" type="submit">Apply filters</button></div></div>
            </div>
        </section>
    </form>

    @if($activeCount > 0)
        <div class="event-active-filters" aria-label="Active filters">
            <strong>Showing filtered results</strong>
            @foreach($activeLabels as $key => $label)
                @if($label)<a href="{{ route('events.index', request()->except([$key, 'page'])) }}" aria-label="Remove {{ $label }} filter">{{ $label }} <span aria-hidden="true">&times;</span></a>@endif
            @endforeach
            <a class="event-clear-all" href="{{ route('events.index') }}">Clear all</a>
        </div>
    @endif

    <section class="panel event-records-panel" aria-labelledby="event-records-title">
        <div class="panel-heading"><div><span class="eyebrow">ALERT LOG</span><h2 id="event-records-title">Event records</h2><p>Open a record to review its evidence and response.</p></div><span class="event-record-count">{{ number_format($events->total()) }} {{ $events->total() === 1 ? 'result' : 'results' }}</span></div>
        @include('partials.event-table')
        <div class="pagination"><span>Page {{ $events->currentPage() }} of {{ $events->lastPage() }}</span><div>@if($events->previousPageUrl())<a class="button subtle small" href="{{ $events->previousPageUrl() }}">Previous</a>@endif @if($events->nextPageUrl())<a class="button subtle small" href="{{ $events->nextPageUrl() }}">Next</a>@endif</div></div>
    </section>
</div>
@endsection
