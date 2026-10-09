@extends('layouts.app')
@section('title', 'Connected devices')
@section('content')
@php
    $isAdmin = auth()->user()->isAdmin();
    $registeredCount = $devices->count();
    $onlineCount = $devices->filter(fn ($device) => $device->isOnline())->count();
    $assignedCount = $devices->filter(fn ($device) => $device->currentAssignment?->patient)->count();
    $faultCount = $devices->filter(fn ($device) => $device->sensor_ok === false)->count();
    $registrationFailed = $errors->any() && old('_device_form') === 'register';
    $registrationValue = fn ($field, $default = null) => $registrationFailed ? old($field, $default) : $default;
@endphp

<div class="device-workspace">
<div class="page-heading device-page-heading">
    <div>
        <div class="eyebrow">DEVICE REGISTRY</div>
        <h1>Connected devices</h1>
        <p>Manage hardware, monitor readiness, and maintain patient assignments.</p>
    </div>
    @if($isAdmin)
        <div class="page-actions">
            <a class="button subtle small" href="{{ route('patients.index') }}">View patients</a>
            <a class="button small" href="{{ route('devices.index', ['register' => 1]) }}#register-device" data-open-device-registration aria-controls="register-device" aria-haspopup="dialog">+ Register device</a>
        </div>
    @endif
</div>

<section class="device-overview" aria-label="Device overview">
    <div class="device-overview-item">
        <span class="device-overview-icon">D</span>
        <div><strong>{{ $registeredCount }}</strong><span>Registered</span></div>
    </div>
    <div class="device-overview-item {{ $onlineCount ? 'is-positive' : '' }}">
        <span class="device-overview-icon"><i class="device-presence-dot"></i></span>
        <div><strong>{{ $onlineCount }}<small>/{{ $registeredCount }}</small></strong><span>Online now</span></div>
    </div>
    <div class="device-overview-item">
        <span class="device-overview-icon">P</span>
        <div><strong>{{ $assignedCount }}</strong><span>Assigned</span></div>
    </div>
    <div class="device-overview-item {{ $faultCount ? 'is-warning' : '' }}">
        <span class="device-overview-icon">!</span>
        <div><strong>{{ $faultCount }}</strong><span>Reported faults</span></div>
    </div>
</section>

@if($isAdmin)
<dialog id="register-device" class="device-registration-dialog" aria-labelledby="register-device-title" aria-describedby="register-device-description" @if($registrationFailed || request()->boolean('register')) open @endif>
    <header class="device-registration-header">
        <span class="device-create-icon" aria-hidden="true">+</span>
        <div><span class="eyebrow">NEW HARDWARE</span><h2 id="register-device-title">Register a physical device</h2><p id="register-device-description">Add a main fall sensor or ESP32-CAM to Patient Watch.</p></div>
        <form id="device-registration-close-form" method="dialog" class="device-registration-close-form"><button class="device-registration-close" type="submit" aria-label="Close Register device">&times;</button></form>
    </header>
    <form method="post" action="{{ route('devices.store') }}" class="device-create-form device-registration-form">
        @csrf
        <input type="hidden" name="_device_form" value="register">
        <div class="device-registration-body">
        @if($registrationFailed)
            <div class="device-registration-errors" role="alert" tabindex="-1"><strong>Please check the device details.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <div class="form-grid">
            <label>Device code
                <input id="new-device-code" name="code" required maxlength="40" pattern="[A-Za-z0-9_-]+" value="{{ $registrationValue('code') }}" placeholder="ESP32-FALL-03">
                <small class="field-hint">Use a unique code printed on or attached to the hardware.</small>
            </label>
            <label>Device name
                <input name="name" required maxlength="100" value="{{ $registrationValue('name') }}" placeholder="Room 3 Fall Sensor">
            </label>
            <label>Device type
                <select name="type" data-device-type>
                    <option value="esp32" @selected($registrationValue('type', 'esp32') === 'esp32')>ESP32 main controller</option>
                    <option value="esp32_cam" @selected($registrationValue('type') === 'esp32_cam')>ESP32-CAM</option>
                </select>
            </label>
            <label>Status
                <select name="is_active">
                    <option value="1" @selected($registrationValue('is_active', '1') === '1')>Enabled</option>
                    <option value="0" @selected($registrationValue('is_active') === '0')>Disabled</option>
                </select>
            </label>
        </div>
        <details class="device-nested-settings" data-camera-registration @if($registrationValue('type') === 'esp32_cam') open @endif>
            <summary>Camera connection <span>ESP32-CAM only</span></summary>
            <p class="muted small-text">Use the camera's private Wi-Fi address. The capture key must match <span class="mono">CAMERA_NODE_KEY</span> in its firmware.</p>
            <div class="form-grid">
                <label>Camera IPv4<input name="camera_ip" value="{{ $registrationValue('camera_ip') }}" placeholder="192.168.137.120"></label>
                <label>Camera port<input type="number" name="camera_port" min="1" max="65535" value="{{ $registrationValue('camera_port', 80) }}" required></label>
            </div>
            <label>Capture key<input type="password" name="camera_key" minlength="32" maxlength="256" autocomplete="new-password"><small class="field-hint">At least 32 characters. This protects the camera's /capture endpoint.</small></label>
        </details>
        </div>
        <div class="device-form-footer">
            <p>The device key will be shown once immediately after registration.</p>
            <div class="device-registration-actions"><button class="button subtle" type="submit" form="device-registration-close-form">Cancel</button><button id="device-registration-submit" class="button" type="submit">Register device</button></div>
        </div>
    </form>
    <div id="device-registration-loading" class="device-registration-loading" role="status" aria-live="polite" hidden>
        <div class="device-registration-loading-content">
            <svg class="device-registration-ecg" viewBox="0 0 1100 500" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                <path class="device-registration-ecg-base" d="M20 250H235l42 39 36-84 38 45 72-101 88 148 99-255 83 430 66-292 55 85 43-50 32 35H1080"/>
                <path class="device-registration-ecg-trace" pathLength="100" d="M20 250H235l42 39 36-84 38 45 72-101 88 148 99-255 83 430 66-292 55 85 43-50 32 35H1080"/>
            </svg>
            <strong>Registering device</strong>
            <p>Saving hardware details securely...</p>
        </div>
    </div>
</dialog>
@endif

<div class="section-heading device-list-heading">
    <div><h2>{{ $isAdmin ? 'Device registry' : 'Assigned hardware' }}</h2><p>Online means a heartbeat was received within {{ config('monitor.offline_after_seconds') }} seconds. Readiness is reported separately.</p></div>
    <span>{{ $registeredCount }} {{ $registeredCount === 1 ? 'device' : 'devices' }}</span>
</div>

<div class="device-card-grid">
@forelse($devices as $device)
    @php
        $assignedPatient = $device->currentAssignment?->patient;
        $isCamera = $device->type === 'esp32_cam';
        $editFailed = $errors->any() && old('_device_form') === 'edit-'.$device->id;
        $assignmentFailed = $errors->any() && old('_device_form') === 'assign-'.$device->id;
        $securityFailed = $errors->any() && old('_device_form') === 'security-'.$device->id;
        $deviceValue = fn ($field, $default = null) => $editFailed ? old($field, $default) : $default;
    @endphp
    <article @class(['panel device-card', 'is-camera' => $isCamera, 'is-controller' => !$isCamera, 'is-online' => $device->is_active && $device->isOnline(), 'is-offline' => $device->is_active && !$device->isOnline(), 'is-disabled' => !$device->is_active])>
        <div class="device-card-kicker">
            <span>{{ $isCamera ? 'Visual verification' : 'Fall detection' }}</span>
            <span class="device-code mono">{{ $device->code }}</span>
        </div>
        <header class="device-card-header">
            <div class="device-identity">
                <span class="device-type-icon {{ $isCamera ? 'is-camera' : 'is-controller' }}" aria-hidden="true">
                    @if($isCamera)
                        <svg viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="14" rx="3"></rect><circle cx="12" cy="13" r="3.25"></circle><path d="M8 6l1.2-2h5.6L16 6"></path></svg>
                    @else
                        <svg viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="4"></rect><path d="M7 12h3l1.5-3 2.5 6 1.5-3H17"></path></svg>
                    @endif
                </span>
                <div>
                    <h2>{{ $device->name }}</h2>
                    <span class="device-kind">{{ $isCamera ? 'ESP32-CAM visual verifier' : 'ESP32 main controller' }}</span>
                </div>
            </div>
            <div class="device-status-stack">
                <x-badge :value="!$device->is_active ? 'disabled' : ($device->isOnline() ? 'online' : 'offline')"/>
            </div>
        </header>

        <dl class="device-health-grid">
            <div>
                <dt>Last heartbeat</dt>
                <dd><x-time :value="$device->last_seen_at"/></dd>
            </div>
            <div>
                <dt>{{ $isCamera ? 'Camera readiness' : 'Sensor readiness' }}</dt>
                <dd>
                    @if($device->sensor_ok === true)<span class="health-label is-good"><i></i>Ready</span>
                    @elseif($device->sensor_ok === false)<span class="health-label is-fault"><i></i>Needs attention</span>
                    @else<span class="health-label"><i></i>Not reported</span>@endif
                </dd>
            </div>
            <div class="device-patient-cell">
                <dt>Assigned patient</dt>
                <dd>@if($assignedPatient)<a class="text-link" href="{{ route('patients.show', $assignedPatient) }}">{{ $assignedPatient->full_name }} &rarr;</a>@else<span class="unassigned-label">Unassigned</span>@endif</dd>
            </div>
        </dl>

        @if($isAdmin)
        <div class="device-card-controls">
            <a class="device-action-trigger device-assignment-trigger" href="{{ route('devices.index', ['device_action' => 'assign-'.$device->id]) }}#device-{{ $device->id }}-assignment" data-open-device-action aria-haspopup="dialog" aria-controls="device-{{ $device->id }}-assignment">
                <span class="device-action-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="8" cy="8" r="3"></circle><path d="M2.5 19a5.5 5.5 0 0 1 11 0M15 9h6m-3-3v6"></path></svg></span>
                <span class="device-action-copy"><strong>Manage patient assignment</strong><small>{{ $assignedPatient ? 'Linked to '.$assignedPatient->full_name : 'Connect this device to a patient' }}</small></span>
                <span class="device-action-chevron" aria-hidden="true"></span>
            </a>
            <a class="device-action-trigger" href="{{ route('devices.index', ['device_action' => 'edit-'.$device->id]) }}#device-{{ $device->id }}-edit" data-open-device-action aria-haspopup="dialog" aria-controls="device-{{ $device->id }}-edit">
                <span class="device-action-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 17h16"></path><circle cx="9" cy="7" r="2"></circle><circle cx="15" cy="17" r="2"></circle></svg></span>
                <span class="device-action-copy"><strong>Edit settings</strong><small>{{ $isCamera ? 'Identity & camera connection' : 'Identity & availability' }}</small></span>
                <span class="device-action-chevron" aria-hidden="true"></span>
            </a>
            <a class="device-action-trigger device-security-trigger" href="{{ route('devices.index', ['device_action' => 'security-'.$device->id]) }}#device-{{ $device->id }}-security" data-open-device-action aria-haspopup="dialog" aria-controls="device-{{ $device->id }}-security">
                <span class="device-action-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"></path></svg></span>
                <span class="device-action-copy"><strong>Device key</strong><small>Security & rotation</small></span>
                <span class="device-action-chevron" aria-hidden="true"></span>
            </a>
        </div>

        @push('device-action-dialogs')
        <dialog id="device-{{ $device->id }}-assignment" class="device-action-dialog device-assignment-dialog" aria-labelledby="device-{{ $device->id }}-assignment-title" @if($assignmentFailed || request('device_action') === 'assign-'.$device->id) open @endif>
            <header class="device-action-dialog-header">
                <span class="device-action-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="8" cy="8" r="3"></circle><path d="M2.5 19a5.5 5.5 0 0 1 11 0M15 9h6m-3-3v6"></path></svg></span>
                <div><span class="eyebrow">PATIENT LINK</span><h2 id="device-{{ $device->id }}-assignment-title">Manage patient assignment</h2><p>{{ $device->name }} · {{ $device->code }}</p></div>
                <form method="dialog"><button class="device-action-close" type="submit" aria-label="Close patient assignment">&times;</button></form>
            </header>
            <div class="device-assignment-body">
            @if($assignmentFailed)<div class="device-action-errors" role="alert" tabindex="-1"><strong>Please check the assignment.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <p class="device-assignment-intro">{{ $assignedPatient ? 'Change or remove this patient connection.' : 'Choose who should receive this device’s alerts.' }}</p>
            <form method="post" action="{{ route('devices.assign', $device) }}" class="device-assignment-form" data-current-patient="{{ $device->currentAssignment?->patient_id }}">
                @csrf
                <input type="hidden" name="_device_form" value="assign-{{ $device->id }}">
                <label class="sr-only" for="device-{{ $device->id }}-patient">Assigned patient</label>
                <select id="device-{{ $device->id }}-patient" name="patient_id" aria-describedby="device-{{ $device->id }}-assignment-hint">
                    <option value="">Unassigned</option>
                    @if($assignedPatient && !$patients->contains('id', $assignedPatient->id))
                        <option value="{{ $assignedPatient->id }}" selected disabled>{{ $assignedPatient->full_name }} (inactive)</option>
                    @endif
                    @foreach($patients as $patient)
                        <option value="{{ $patient->id }}" @disabled(!$device->is_active && $device->currentAssignment?->patient_id !== $patient->id) @selected((string) ($assignmentFailed ? old('patient_id') : $device->currentAssignment?->patient_id) === (string) $patient->id)>{{ $patient->full_name }} ({{ $patient->code }})</option>
                    @endforeach
                </select>
                <button class="button small" type="submit">Save assignment</button>
            </form>
            <p class="field-hint" id="device-{{ $device->id }}-assignment-hint">{{ $device->is_active ? 'One device of each type per patient. Saving replaces any previous same-type device; history is retained. Select Unassigned to disconnect.' : 'Enable this device in settings to assign a patient. Existing assignments may still be removed.' }}</p>
            </div>
        </dialog>

        <dialog id="device-{{ $device->id }}-edit" class="device-action-dialog device-edit-dialog" aria-labelledby="device-{{ $device->id }}-edit-title" @if($editFailed || request('device_action') === 'edit-'.$device->id) open @endif>
            <header class="device-action-dialog-header">
                <span class="device-action-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 17h16"></path><circle cx="9" cy="7" r="2"></circle><circle cx="15" cy="17" r="2"></circle></svg></span>
                <div><span class="eyebrow">DEVICE SETTINGS</span><h2 id="device-{{ $device->id }}-edit-title">Edit settings</h2><p>{{ $device->name }} · {{ $device->code }}</p></div>
                <form method="dialog"><button class="device-action-close" type="submit" aria-label="Close device settings">&times;</button></form>
            </header>
            <form method="post" action="{{ route('devices.update', $device) }}" class="device-edit-form">
                @csrf
                @method('PUT')
                <input type="hidden" name="_device_form" value="edit-{{ $device->id }}">
                <input type="hidden" name="type" value="{{ $device->type }}">
                @if($editFailed)<div class="device-action-errors" role="alert" tabindex="-1"><strong>Please check the device details.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                <div class="form-grid">
                    <label>Device code<input name="code" required maxlength="40" pattern="[A-Za-z0-9_-]+" value="{{ $deviceValue('code', $device->code) }}"></label>
                    <label>Device name<input name="name" required maxlength="100" value="{{ $deviceValue('name', $device->name) }}"></label>
                    <label>Device type<input value="{{ $isCamera ? 'ESP32-CAM' : 'ESP32 main controller' }}" disabled></label>
                    <label>Status<select name="is_active"><option value="1" @selected((string) $deviceValue('is_active', (int) $device->is_active) === '1')>Enabled</option><option value="0" @selected((string) $deviceValue('is_active', (int) $device->is_active) === '0')>Disabled</option></select></label>
                </div>
                @if($isCamera)
                    <div class="device-camera-fields">
                        <div class="form-grid">
                            <label>Camera IPv4<input name="camera_ip" value="{{ $deviceValue('camera_ip', $device->camera_ip) }}" placeholder="192.168.137.120"></label>
                            <label>Camera port<input type="number" name="camera_port" min="1" max="65535" value="{{ $deviceValue('camera_port', $device->camera_port ?? 80) }}" required></label>
                        </div>
                        <label>New capture key <small>(leave blank to keep current)</small><input type="password" name="camera_key" minlength="32" maxlength="256" autocomplete="new-password"></label>
                    </div>
                @else
                    <input type="hidden" name="camera_port" value="{{ $device->camera_port ?? 80 }}">
                @endif
                <div class="action-row"><button class="button small" type="submit">Save device details</button></div>
            </form>
        </dialog>

        <dialog id="device-{{ $device->id }}-security" class="device-action-dialog device-security-dialog" aria-labelledby="device-{{ $device->id }}-security-title" @if($securityFailed || request('device_action') === 'security-'.$device->id) open @endif>
            <header class="device-action-dialog-header">
                <span class="device-action-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"></path></svg></span>
                <div><span class="eyebrow">DEVICE SECURITY</span><h2 id="device-{{ $device->id }}-security-title">Device key</h2><p>{{ $device->name }} · {{ $device->code }}</p></div>
                <form method="dialog"><button class="device-action-close" type="submit" aria-label="Close device key">&times;</button></form>
            </header>
            <form method="post" action="{{ route('devices.rotate', $device) }}">
                @csrf
                <input type="hidden" name="_device_form" value="security-{{ $device->id }}">
                @if($securityFailed)<div class="device-action-errors" role="alert" tabindex="-1"><strong>Please confirm the key rotation.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                <p class="muted small-text">The current key will stop working immediately. The replacement is displayed only once.</p>
                <label class="check"><input type="checkbox" name="confirm" value="1" required>I will update the hardware configuration with the new key.</label>
                <div class="action-row"><button class="button danger-button small" type="submit">Generate replacement key</button></div>
            </form>
        </dialog>
        @endpush
        @else
            <div class="device-readonly-note">Device settings and assignments are managed by an administrator.</div>
        @endif
    </article>
@empty
    <section class="panel device-empty-state">
        <span class="device-empty-icon">+</span>
        <h2>{{ $isAdmin ? 'No hardware registered yet' : 'No hardware assigned yet' }}</h2>
        <p>{{ $isAdmin ? 'Register a controller or camera, then assign it to a patient.' : 'An administrator can assign hardware to patients in your care.' }}</p>
        @if($isAdmin)<a class="button small" href="{{ route('devices.index', ['register' => 1]) }}#register-device" data-open-device-registration aria-controls="register-device" aria-haspopup="dialog">Register first device</a>@endif
    </section>
@endforelse
</div>
@if($isAdmin)
    @stack('device-action-dialogs')
@endif

@if($isAdmin && $assignments->isNotEmpty())
<details class="panel assignment-history-panel">
    <summary><span><strong>Assignment history</strong><small>Previous patient-device connections remain available for audit.</small></span><span>{{ $assignments->count() }} recent records</span></summary>
    <div class="table-wrap"><table><thead><tr><th>Device</th><th>Patient</th><th>Assigned</th><th>Ended</th></tr></thead><tbody>@foreach($assignments as $assignment)<tr><td>{{ $assignment->device->code }}</td><td>{{ $assignment->patient->full_name }}</td><td><x-time :value="$assignment->assigned_at"/></td><td>{{ $assignment->unassigned_at ? $assignment->unassigned_at->timezone(config('monitor.display_timezone'))->format('M d, Y h:i A') : 'Current assignment' }}</td></tr>@endforeach</tbody></table></div>
</details>
@endif
</div>
@endsection
