@extends('layouts.app')
@section('title', 'Patient record')
@section('content')
<a class="text-link" href="{{ route('patients.index') }}">&larr; Patients</a>
<div class="page-heading">
    <div>
        <div class="eyebrow">{{ $patient->code }}</div>
        <h1>{{ $patient->full_name }}</h1>
        <p>{{ $patient->room ? 'Room '.$patient->room->number : 'Room unassigned' }} / {{ $patient->caregiver?->name ?? 'Caregiver unassigned' }}</p>
    </div>
    <div class="record-heading-actions">
        @if(auth()->user()->isAdmin())
            <a class="button subtle" href="{{ route('patients.edit', $patient) }}">Edit profile</a>
        @endif
    </div>
</div>
@if(!auth()->user()->isAdmin())
    <div class="workflow-strip" role="note">
        <div>
            <div class="eyebrow">CAREGIVER VIEW</div>
            <strong>This patient is assigned to your care.</strong>
            <p>Review the device status, recent alerts, and sensor readings below.</p>
        </div>
        <a class="button subtle small" href="{{ route('events.index', ['patient_id' => $patient->id]) }}">View alerts</a>
    </div>
@endif
<div class="two-columns">
    <section class="panel padded">
        <div class="panel-heading flush"><div><h2>Profile & care notes</h2><p>Information shared with the assigned caregiver.</p></div><x-badge :value="$patient->is_active ? 'active' : 'inactive'"/></div>
        <dl class="details"><dt>Birth date</dt><dd>{{ $patient->birth_date?->format('M d, Y') ?? 'Not provided' }}</dd><dt>Gender</dt><dd>{{ ucfirst($patient->gender ?? 'Not provided') }}</dd><dt>Caregiver</dt><dd>{{ $patient->caregiver?->name ?? 'Not assigned' }}</dd>@if($patient->emergency_contact)<dt>Previous contact notes</dt><dd>{{ $patient->emergency_contact }}</dd>@endif</dl>
        <div class="divider"></div>
        <h3>Watcher & emergency contact</h3>
        <dl class="details">
            <dt>Watcher name</dt><dd>{{ $patient->emergency_contact_name ?? 'Not provided' }}</dd>
            <dt>Mobile number</dt><dd>@if($patient->emergency_contact_phone)<a class="text-link" href="tel:{{ $patient->emergency_contact_phone }}">{{ $patient->emergency_contact_phone }}</a>@else Not provided @endif</dd>
            <dt>Relationship</dt><dd>{{ $patient->emergency_contact_relationship ?? 'Not provided' }}</dd>
            @if(config('watcher.channel') === 'semaphore')
            <dt>Confirmed-fall SMS</dt><dd>{{ $patient->emergency_sms_enabled ? 'Enabled for this watcher' : 'Disabled' }}</dd>
            @else
            <dt>Telegram alerts</dt><dd>{{ $patient->watcher_telegram_enabled ? 'Enabled for this watcher' : 'Disabled' }}</dd>
            @endif
        </dl>
        @if(config('watcher.channel') === 'semaphore')
        <p class="small-text muted">SMS is triggered after an administrator or assigned caregiver confirms a sensor-detected fall.</p>
        @else
            @include('management.partials.watcher-telegram')
        @endif
        <div class="divider"></div>
        <h3>Care notes</h3>
        <p class="preserve-lines">{{ $patient->medical_notes ?? 'No care notes recorded.' }}</p>
    </section>
    <section class="panel padded">
        <div class="panel-heading flush"><div><h2>Monitoring devices</h2><p>Hardware assigned to this patient.</p></div></div>
        @forelse($patient->currentAssignments as $assignment)
            <div class="assignment-card">
                <strong>{{ $assignment->device->name }}</strong>
                <span class="mono">{{ $assignment->device->code }}</span>
                <p><x-badge :value="'hardware'"/> <x-badge :value="$assignment->device->isOnline() ? 'online' : 'offline'"/></p>
                <small>Last seen: <x-time :value="$assignment->device->last_seen_at"/></small>
                @if(auth()->user()->isAdmin())
                    <form method="post" action="{{ route('devices.assign', $assignment->device) }}" class="form-actions" onsubmit="return confirm('Unassign this device from the patient?')">
                        @csrf
                        <input type="hidden" name="patient_id" value="">
                        <button class="button subtle small" type="submit">Unassign device</button>
                    </form>
                @endif
            </div>
        @empty
            <div class="notice warning compact">No device is assigned to this patient yet.</div>
        @endforelse
        @if(auth()->user()->isAdmin())
            <div class="assignment-section">
                <h3>Assign a physical device</h3>
                <p class="muted small-text">Select an available device to connect its heartbeat and emergency events to this patient.</p>
                @forelse($assignableDevices as $device)
                    @php
                        $currentPatient = $device->currentAssignment?->patient;
                    @endphp
                    <div class="assignment-card">
                        <strong>{{ $device->name }}</strong>
                        <span class="mono">{{ $device->code }}</span>
                        <small>
                            @if($currentPatient && $currentPatient->id !== $patient->id)
                                Assigned to {{ $currentPatient->full_name }}
                            @elseif($currentPatient)
                                Already assigned to this patient
                            @else
                                Available to assign
                            @endif
                        </small>
                        @if(!$currentPatient || $currentPatient->id === $patient->id)
                            <form method="post" action="{{ route('devices.assign', $device) }}" class="form-actions">
                                @csrf
                                <input type="hidden" name="patient_id" value="{{ $patient->id }}">
                                <button class="button subtle small" type="submit">
                                    {{ $currentPatient ? 'Keep assigned' : 'Assign to this patient' }}
                                </button>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="muted">No physical devices are registered yet.</p>
                @endforelse
            </div>
        @endif
    </section>
</div>
<section class="panel event-records-panel patient-events-panel">
    <div class="panel-heading">
        <div>
            <span class="eyebrow">ALERT HISTORY</span>
            <h2>Recent events</h2>
            <a class="text-link" href="{{ route('events.index', ['patient_id' => $patient->id]) }}">Full history &rarr;</a>
        </div>
    </div>
    @include('partials.event-table')
</section>
<section class="panel"><div class="panel-heading"><div><h2>Recent sensor readings</h2><p>Acceleration includes gravity. Axis acceleration uses m/s^2 and axis gyroscope values use rad/s.</p></div></div><div class="table-wrap"><table><thead><tr><th>Recorded</th><th>Acceleration magnitude</th><th>Gyroscope magnitude</th><th>Axes, when supplied</th><th>Source</th></tr></thead><tbody>@forelse($readings as $reading)<tr><td><x-time :value="$reading->recorded_at"/></td><td>{{ number_format($reading->acceleration_g, 3) }} g</td><td>{{ number_format($reading->gyro_rad_s, 3) }} rad/s</td><td class="mono small-text">{{ $reading->axes ? json_encode($reading->axes) : 'Not supplied' }}</td><td><x-badge :value="'hardware'"/></td></tr>@empty<tr><td colspan="5" class="empty">No sensor samples received.</td></tr>@endforelse</tbody></table></div></section>
@endsection
