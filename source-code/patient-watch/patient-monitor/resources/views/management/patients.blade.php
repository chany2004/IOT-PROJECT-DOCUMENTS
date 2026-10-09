@extends('layouts.app')
@section('title', 'Patients')
@section('content')
<div class="page-heading">
    <div><div class="eyebrow">PATIENT DIRECTORY</div><h1>People in your care</h1><p>Patient information, room assignments, and connected monitoring devices.</p></div>
    @if(auth()->user()->isAdmin())
        <div class="page-actions"><a class="button subtle small" href="{{ route('users.index') }}">Manage caregivers</a><a id="add-patient-trigger" class="button" href="{{ route('patients.index', ['create' => 1]) }}#create-patient" aria-controls="create-patient" aria-haspopup="dialog">+ Add patient</a></div>
    @endif
</div>
@if(auth()->user()->isAdmin())
<dialog id="create-patient" class="patient-modal" aria-labelledby="create-patient-title" aria-describedby="create-patient-description" @if(old('_patient_form') === 'create' || request()->boolean('create')) open @endif>
    <header class="patient-modal-header">
        <span class="patient-modal-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="7.5" r="3"></circle><path d="M5.5 20a6.5 6.5 0 0 1 13 0M19 5v6M16 8h6"></path></svg></span>
        <div><h2 id="create-patient-title">Add a patient</h2><p id="create-patient-description">Create the profile, then connect care and monitoring hardware.</p></div>
        <form id="patient-modal-close-form" method="dialog" class="patient-modal-close-form"><button class="patient-modal-close" type="submit" aria-label="Close Add patient"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5 5l10 10M15 5 5 15"></path></svg></button></form>
    </header>
    <form method="post" action="{{ route('patients.store') }}" class="patient-modal-form">
        @csrf
        <input type="hidden" name="_patient_form" value="create">
        <div class="patient-modal-body">
            @if(old('_patient_form') === 'create' && $errors->any())
                <div class="patient-modal-errors" role="alert" tabindex="-1"><strong>Please check the patient details.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            @include('management.partials.patient-fields', ['patient' => $newPatient])
        </div>
        <div class="patient-modal-footer"><span>The assigned caregiver will see this patient after saving.</span><div><button class="button subtle" type="submit" form="patient-modal-close-form">Cancel</button><button id="patient-modal-submit" class="button" type="submit">Create patient</button></div></div>
    </form>
    <div id="patient-modal-loading" class="patient-modal-loading" role="status" aria-live="polite" hidden>
        <div class="patient-modal-loading-content">
            <svg class="patient-modal-ecg" viewBox="0 0 1100 500" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                <path class="patient-modal-ecg-base" d="M20 250H235l42 39 36-84 38 45 72-101 88 148 99-255 83 430 66-292 55 85 43-50 32 35H1080"/>
                <path class="patient-modal-ecg-trace" pathLength="100" d="M20 250H235l42 39 36-84 38 45 72-101 88 148 99-255 83 430 66-292 55 85 43-50 32 35H1080"/>
            </svg>
            <strong>Creating patient</strong>
            <p>Saving patient details securely...</p>
        </div>
    </div>
</dialog>
@endif
<form class="panel patient-directory-search event-search-row" method="get" action="{{ route('patients.index') }}" role="search" aria-label="Search patient directory">
    <label for="patient-directory-search">Search patients</label>
    <div class="event-search-field">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"></circle><path d="m16 16 4 4"></path></svg>
        <input id="patient-directory-search" type="search" name="q" value="{{ request('q') }}" maxlength="100" placeholder="Patient name, patient ID, room, caregiver, or device" aria-describedby="patient-directory-search-help">
        @if(filled(request('q')))<a class="event-search-clear" href="{{ route('patients.index') }}" aria-label="Clear patient search">&times;</a>@endif
        <button type="submit" class="button event-search-submit">Search</button>
    </div>
    <p id="patient-directory-search-help">Find patients across the directory. Press Enter to search.</p>
</form>
<section class="panel table-wrap patient-directory-panel"><div class="panel-heading"><div><span class="eyebrow">CARE DIRECTORY</span><h2>Patient assignments</h2><p>See who receives alerts, where they are cared for, and which hardware is connected.</p></div><span class="patient-directory-count">{{ $patients->total() }} {{ filled(request('q')) ? 'results' : 'total' }}</span></div><table class="patient-directory-table"><thead><tr><th>Patient</th><th>Room</th><th>Caregiver</th><th>Devices assigned</th><th>Status</th><th class="directory-action-heading"><span class="sr-only">Action</span></th></tr></thead><tbody>
@forelse($patients as $patient)
@php
    $patientName = $patient->full_name;
    $caregiverName = $patient->caregiver?->name ?? 'Unassigned';
    $patientInitial = strtoupper(substr($patientName, 0, 1));
    $caregiverInitial = $patient->caregiver ? strtoupper(substr($caregiverName, 0, 1)) : '—';
    $deviceCount = $patient->currentAssignments->count();
@endphp
<tr>
    <td class="directory-patient-column"><div class="directory-person"><span class="directory-avatar" aria-hidden="true">{{ $patientInitial }}</span><div><a class="strong-link" href="{{ route('patients.show', $patient) }}">{{ $patientName }}</a><small class="directory-code mono">{{ $patient->code }}</small></div></div></td>
    <td><span class="directory-room"><span class="directory-room-mark" aria-hidden="true">⌂</span><span><strong>{{ $patient->room?->number ?? 'Unassigned' }}</strong><small>{{ $patient->room ? 'Assigned room' : 'Needs a room' }}</small></span></span></td>
    <td><span class="directory-caregiver"><span class="directory-caregiver-avatar" aria-hidden="true">{{ $caregiverInitial }}</span><span>{{ $caregiverName }}</span></span></td>
    <td><span class="directory-device-count"><strong>{{ $deviceCount }}</strong><span>/ 2 devices</span></span></td>
    <td><x-badge :value="$patient->is_active ? 'active' : 'inactive'"/></td>
    <td class="directory-action"><a class="text-link directory-open-link" href="{{ route('patients.show', $patient) }}"><span>Open record</span><span aria-hidden="true">&rarr;</span></a></td>
</tr>
@empty<tr><td colspan="6" class="empty">{{ filled(request('q')) ? 'No matching patients. Try a different name, ID, room, caregiver, or device.' : 'No patients assigned yet.' }}</td></tr>@endforelse
</tbody></table><div class="pagination"><span>{{ $patients->total() }} patients</span><div>@if($patients->previousPageUrl())<a href="{{ $patients->previousPageUrl() }}">Previous</a>@endif @if($patients->nextPageUrl())<a href="{{ $patients->nextPageUrl() }}">Next</a>@endif</div></div></section>
@endsection
