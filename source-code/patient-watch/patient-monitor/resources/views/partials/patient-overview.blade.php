<section class="care-overview" aria-labelledby="patient-overview-title" data-patient-overview>
    <div class="care-overview-heading">
        <div><div class="eyebrow">PATIENT MONITORING</div><h2 id="patient-overview-title">Patient overview <span class="count">{{ $patients->count() }}</span></h2><p>Review care priorities, assigned devices, and patient records.</p></div>
        <a class="button subtle small" href="{{ route('patients.index') }}">Patient directory &rarr;</a>
    </div>
    <div class="care-overview-result"><span>{{ $patients->count() }} patients</span></div>
    <div class="care-patient-grid" id="patient-overview-cards">
        @forelse($patients as $patient)
            @php
                $devices = $patient->currentAssignments->pluck('device');
                $controller = $devices->firstWhere('type', 'esp32');
                $camera = $devices->firstWhere('type', 'esp32_cam');
                $deviceAttention = !$controller || !$camera || $devices->contains(fn ($device) =>
                    !$device->isOnline() || ($device->type === 'esp32' && $device->sensor_ok !== true));
                $status = $patient->urgent_events_count > 0 ? 'urgent'
                    : ($patient->review_events_count > 0 ? 'review'
                    : ($patient->verifying_events_count > 0 ? 'verifying' : 'monitoring'));
                $statusLabel = match ($status) {
                    'urgent' => 'Urgent alert', 'review' => 'Under review',
                    'verifying' => 'Camera verifying', default => 'No open alerts',
                };
                $priority = match ($status) { 'urgent' => 0, 'review' => 1, 'verifying' => 2, default => $deviceAttention ? 3 : 4 };
            @endphp
            <article class="care-patient-card" data-patient-card data-name="{{ $patient->full_name }}" data-room="{{ $patient->room?->number ?? '' }}" data-priority="{{ $priority }}" data-status="{{ $status }}" data-device-attention="{{ $deviceAttention ? 'true' : 'false' }}" data-search="{{ $patient->full_name }} {{ $patient->code }} {{ $patient->room?->number }} {{ $patient->caregiver?->name }}">
                <div class="care-card-heading">
                    <div class="care-patient-identity"><span class="care-patient-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($patient->full_name, 0, 1)) }}</span><div><h3><a href="{{ route('patients.show', $patient) }}">{{ $patient->full_name }}</a></h3><span>{{ $patient->code }}</span></div></div>
                    <span class="care-status care-status-{{ $status }}">{{ $statusLabel }}</span>
                </div>
                <dl class="care-patient-info">
                    <div><dt>Room</dt><dd>{{ $patient->room ? 'Room '.$patient->room->number : 'Unassigned' }}</dd></div>
                    <div><dt>Assigned caregiver</dt><dd>{{ $patient->caregiver?->name ?? 'Unassigned' }}</dd></div>
                </dl>
                <div class="care-device-list" aria-label="Assigned monitoring devices">
                    @foreach(['Motion sensor' => $controller, 'Camera node' => $camera] as $label => $device)
                        @php
                            $online = $device?->isOnline() ?? false;
                            $deviceState = !$device ? 'Unassigned' : (!$device->is_active ? 'Inactive' : (!$online ? 'Offline' :
                                ($device->type === 'esp32' && $device->sensor_ok === false ? 'Sensor fault' :
                                ($device->type === 'esp32' && $device->sensor_ok === null ? 'Sensor not checked' : 'Online'))));
                            $healthy = $online && ($device->type !== 'esp32' || $device->sensor_ok === true);
                        @endphp
                        <div class="care-device-row"><div><strong>{{ $label }}</strong><span>{{ $device?->code ?? 'No device assigned' }}</span></div><span class="care-device-state {{ $healthy ? 'is-online' : 'needs-attention' }}"><i aria-hidden="true"></i>{{ $deviceState }}</span></div>
                    @endforeach
                </div>
                <div class="care-card-summary">
                    @if($patient->urgent_events_count > 0)<span class="care-urgent-count">{{ $patient->urgent_events_count }} urgent {{ $patient->urgent_events_count === 1 ? 'alert needs' : 'alerts need' }} a response</span>
                    @elseif($patient->review_events_count > 0)<span>{{ $patient->review_events_count }} {{ $patient->review_events_count === 1 ? 'incident' : 'incidents' }} awaiting completion</span>
                    @elseif($patient->verifying_events_count > 0)<span>Camera assessment in progress</span>
                    @elseif($deviceAttention)<span>Review device connection or assignment</span>
                    @else<span>Monitoring devices connected</span>@endif
                </div>
                <div class="care-card-actions"><a class="button small" href="{{ route('patients.show', $patient) }}">Patient record</a><a class="button subtle small" href="{{ route('events.index', ['patient_id' => $patient->id]) }}">Alert history</a>@if(auth()->user()->isAdmin())<a class="care-edit-link" href="{{ route('patients.edit', $patient) }}">Edit profile</a>@endif</div>
            </article>
        @empty
            <div class="care-overview-empty"><strong>No active patients</strong><p>{{ auth()->user()->isAdmin() ? 'Create a patient profile and assign monitoring devices to begin.' : 'Patients assigned to your care will appear here.' }}</p>@if(auth()->user()->isAdmin())<a class="button small" href="{{ route('patients.create') }}">Add patient</a>@endif</div>
        @endforelse
    </div>
</section>
