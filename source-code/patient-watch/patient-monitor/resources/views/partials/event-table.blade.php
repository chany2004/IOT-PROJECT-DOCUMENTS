<div class="table-wrap event-table-wrap"><table class="event-record-table">
<thead><tr><th>Patient / event</th><th>Detected</th><th>Device</th><th>Assessment</th><th>Camera</th><th>Response</th><th class="event-action-heading"><span class="sr-only">Action</span></th></tr></thead>
<tbody>
@forelse($events as $event)
@php
    $patientName = $event->patient->full_name;
    $cameraStatus = $event->camera ? ucfirst(str_replace('_', ' ', $event->camera->status)) : 'Not requested';
    $cameraStateClass = strtolower(str_replace(' ', '-', $cameraStatus));
@endphp
<tr>
    <td class="event-patient-column">
        <div class="event-patient-cell">
            <span class="event-patient-avatar" aria-hidden="true">{{ strtoupper(substr($patientName, 0, 1)) }}</span>
            <div class="event-patient-copy">
                <a class="strong-link" href="{{ route('events.show', $event) }}">{{ $patientName }}</a>
                <small><span class="event-type-label">{{ $event->assessmentLabel() }}</span><span class="event-id mono">#{{ substr($event->public_id, 0, 8) }}</span></small>
            </div>
        </div>
    </td>
    <td class="time"><div class="event-detected"><strong><x-time :value="$event->detected_at"/></strong><span>Philippine time</span></div></td>
    <td class="mono"><span class="event-device"><span class="event-device-dot" aria-hidden="true"></span>{{ $event->device->code }}</span></td>
    <td><span class="event-badge-cell"><x-badge :value="$event->verification_status"/></span></td>
    <td><span class="event-camera-state event-camera-state--{{ $cameraStateClass }}"><span class="event-state-dot" aria-hidden="true"></span>{{ $cameraStatus }}</span></td>
    <td><span class="event-badge-cell"><x-badge :value="$event->status"/></span></td>
    <td class="event-action"><a class="text-link event-review-link" href="{{ route('events.show', $event) }}"><span>Review</span><span aria-hidden="true">&rarr;</span></a></td>
</tr>
@empty
<tr><td colspan="7"><div class="empty"><span class="empty-symbol">+</span><strong>No matching events</strong><p>Sensor candidates and manual emergencies will appear here.</p></div></td></tr>
@endforelse
</tbody></table></div>
