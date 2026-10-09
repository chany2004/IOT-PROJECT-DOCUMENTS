@extends('layouts.app')
@section('title', $patient->exists ? 'Edit patient' : 'Add patient')
@section('content')
<div class="page-heading">
    <div>
        <div class="eyebrow">PATIENT PROFILE</div>
        <h1>{{ $patient->exists ? 'Edit patient' : 'Add a patient' }}</h1>
        <p>Set the caregiver, room, and physical monitoring device in one guided form.</p>
    </div>
    <div class="page-actions">
        <a class="button subtle small" href="{{ route('patients.index') }}">Back to patients</a>
    </div>
</div>
<div class="workflow-strip" role="note">
    <div>
        <div class="eyebrow">ADMIN SETUP</div>
        <strong>Assign the care team and hardware together.</strong>
        <p>The caregiver will see this patient after you save the profile.</p>
    </div>
    <a class="button subtle small" href="{{ route('users.index') }}">Manage caregivers</a>
</div>
<form class="panel form-shell" method="post" action="{{ $patient->exists ? route('patients.update', $patient) : route('patients.store') }}">@csrf @if($patient->exists) @method('PUT') @endif
    @include('management.partials.patient-fields')
    <div class="form-submit-bar">
        <p>Saving updates the patient record and assignment at the same time.</p>
        <div class="form-actions"><button class="button" type="submit">{{ $patient->exists ? 'Save changes' : 'Create patient' }}</button><a class="button subtle" href="{{ route('patients.index') }}">Cancel</a></div>
    </div>
</form>
@endsection
