@extends('layouts.app')
@section('title', 'Rooms')
@section('content')
@php
    $occupiedCount = $rooms->filter(fn ($room) => $room->patient !== null)->count();
    $availableCount = $rooms->filter(fn ($room) => !$room->is_occupied)->count();
    $reservedCount = $rooms->count() - $occupiedCount - $availableCount;
    $createFailed = $errors->any() && old('_room_form') === 'create';
@endphp
<div class="room-workspace">
    <div class="page-heading">
        <div><div class="eyebrow">ROOM MANAGEMENT</div><h1>Rooms &amp; occupancy</h1><p>Keep room details organized and connect each patient to a dedicated space.</p></div>
        <div class="page-actions">
            <a class="button subtle small" href="{{ route('patients.index') }}">Patient directory</a>
            <a class="button small" href="#create-room" data-open-room-registration>+ Add room</a>
        </div>
    </div>
    <section class="room-summary" aria-label="Room overview">
        <div><span>Total rooms</span><strong>{{ $rooms->count() }}</strong><small>One patient per room</small></div>
        <div><span>Occupied</span><strong>{{ $occupiedCount }}</strong><small>Assigned patient records</small></div>
        <div class="is-available"><span>Available</span><strong>{{ $availableCount }}</strong><small>Ready for assignment</small></div>
        <div><span>Awaiting a room</span><strong>{{ $unassignedPatients->count() }}</strong><small>Active, unassigned patients</small></div>
    </section>
    <details id="create-room" class="panel room-create" @if($rooms->isEmpty() || $createFailed) open @endif>
        <summary><span><strong>Add a room</strong><small>Register its number, floor, and room details.</small></span><span class="room-disclosure-icon" aria-hidden="true">+</span></summary>
        <form method="post" action="{{ route('rooms.store') }}" class="room-details-form">
            @csrf
            <input type="hidden" name="_room_form" value="create">
            @include('management.partials.room-fields', ['room' => null, 'formKey' => 'create'])
            <div class="room-form-footer"><span>Room numbers must be unique. New rooms start available.</span><button class="button">Create room</button></div>
        </form>
    </details>
    <div class="section-heading room-list-heading">
        <div><h2>Room directory</h2><p>Assign unplaced patients here. Move existing occupants from their patient profile.</p></div>
        <span>{{ $rooms->count() }} {{ $rooms->count() === 1 ? 'room' : 'rooms' }}</span>
    </div>
    @if($reservedCount)
        <p class="room-reserved-note" role="note">{{ $reservedCount }} {{ $reservedCount === 1 ? 'room is' : 'rooms are' }} reserved by archived patient records and cannot accept a new assignment.</p>
    @endif
    <div class="room-card-grid">
    @forelse($rooms as $room)
        @php
            $patient = $room->patient;
            $editFailed = $errors->any() && old('_room_form') === 'edit-'.$room->id;
            $assignmentFailed = $errors->any() && old('_room_form') === 'assign-'.$room->id;
            $roomState = $patient ? 'occupied' : ($room->is_occupied ? 'reserved' : 'available');
            $devices = $patient?->currentAssignments->pluck('device') ?? collect();
        @endphp
        <article class="panel room-card" id="room-{{ $room->id }}">
            <header class="room-card-header">
                <div class="room-identity"><span class="room-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 21V4h16v17M8 21V8h8v13M13 14h.01M2 21h20"/></svg></span><div><h2>Room {{ $room->number }}</h2><p>{{ $room->name }}</p></div></div>
                <x-badge :value="$roomState"/>
            </header>
            <dl class="room-facts"><div><dt>Floor</dt><dd>{{ $room->floor ?? 'Not specified' }}</dd></div><div><dt>Capacity</dt><dd>1 patient</dd></div></dl>
            @if($patient)
                <section class="room-occupant" aria-label="Patient assigned to Room {{ $room->number }}">
                    <span class="room-field-label">ASSIGNED PATIENT</span>
                    <a class="room-patient-name" href="{{ route('patients.show', $patient) }}">{{ $patient->full_name }} <span aria-hidden="true">&rarr;</span></a>
                    <span class="room-patient-code">{{ $patient->code }}{{ !$patient->is_active ? ' / Inactive profile' : '' }}</span>
                    <dl class="room-care-details"><div><dt>Caregiver</dt><dd>{{ $patient->caregiver?->name ?? 'Not assigned' }}</dd></div><div><dt>Monitoring hardware</dt><dd>{{ $devices->count() }} / 2 assigned <small>{{ $devices->filter(fn ($device) => $device->isOnline())->count() }} online</small></dd></div></dl>
                    <div class="room-patient-actions"><a class="button subtle small" href="{{ route('patients.show', $patient) }}">Patient record</a><a class="text-link" href="{{ route('patients.edit', $patient) }}">Manage assignment &rarr;</a></div>
                </section>
            @elseif($room->is_occupied)
                <div class="room-vacancy"><strong>Reserved room</strong><p>An archived patient record still holds this room. Its assignment has not been released.</p></div>
            @else
                <section class="room-vacancy"><strong>Ready for a patient</strong><p>Only active patients without a room can be assigned here.</p>
                    @if($unassignedPatients->isNotEmpty())
                        <form method="post" action="{{ route('rooms.assign', $room) }}" class="room-assignment-form">
                            @csrf
                            <input type="hidden" name="_room_form" value="assign-{{ $room->id }}">
                            <label class="sr-only" for="room-{{ $room->id }}-patient">Assign patient to Room {{ $room->number }}</label>
                            <select id="room-{{ $room->id }}-patient" name="patient_id" required>
                                <option value="">Select a patient</option>
                                @foreach($unassignedPatients as $candidate)
                                    <option value="{{ $candidate->id }}" @selected($assignmentFailed && (string) old('patient_id') === (string) $candidate->id)>{{ $candidate->full_name }} ({{ $candidate->code }})</option>
                                @endforeach
                            </select>
                            <button class="button small">Assign patient</button>
                        </form>
                        @if($assignmentFailed) @error('patient_id')<p class="room-field-error" role="alert">{{ $message }}</p>@enderror @endif
                    @else
                        <span class="room-no-candidates">All active patients have a room, or none have been added yet.</span>
                        <a class="text-link" href="{{ route('patients.create') }}">Add a patient &rarr;</a>
                    @endif
                </section>
            @endif
            @if($room->notes)<div class="room-notes"><span class="room-field-label">ROOM NOTES</span><p class="preserve-lines">{{ $room->notes }}</p></div>@endif
            <details class="room-edit" @if($editFailed) open @endif>
                <summary><span>Edit room details</span><span class="room-disclosure-icon" aria-hidden="true">+</span></summary>
                <form method="post" action="{{ route('rooms.update', $room) }}" class="room-details-form">
                    @csrf @method('PUT')
                    <input type="hidden" name="_room_form" value="edit-{{ $room->id }}">
                    @include('management.partials.room-fields', ['formKey' => 'edit-'.$room->id])
                    <div class="room-form-footer"><span>Updating details does not change the occupant.</span><button class="button small">Save changes</button></div>
                </form>
            </details>
        </article>
    @empty
        <section class="panel room-empty"><h2>No rooms registered yet</h2><p>Add a room, then assign a patient. Each room holds one patient.</p><a class="button small" href="#create-room" data-open-room-registration>Add your first room</a></section>
    @endforelse
    </div>
</div>
@endsection
