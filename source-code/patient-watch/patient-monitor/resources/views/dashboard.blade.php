@extends('layouts.app')
@section('title', 'Overview')
@section('content')
<div data-live id="overview-live">
<div class="stats-grid">
    <div class="stat-card verifying"><div class="stat-label">Camera verifying <span class="stat-icon stat-icon-verifying" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 12h3l2-4 4 8 2-4h5"></path></svg></span></div><div class="stat-value">{{ $stats['verifying'] }}</div><div class="stat-caption">Sensor candidates being checked</div></div>
    <div class="stat-card alert"><div class="stat-label">Needs attention <span class="stat-icon stat-icon-alert" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 4 3.8 19h16.4L12 4Z"></path><path d="M12 9v4M12 16.5h.01"></path></svg></span></div><div class="stat-value">{{ $stats['pending'] }}</div><div class="stat-caption">Unacknowledged alerts</div></div>
    <div class="stat-card"><div class="stat-label">Review queue <span class="stat-icon stat-icon-review" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 12h14M12 5v14"></path></svg></span></div><div class="stat-value">{{ $stats['review'] }}</div><div class="stat-caption">Acknowledged or camera-cleared</div></div>
    <div class="stat-card"><div class="stat-label">Patients monitored <span class="stat-icon stat-icon-patient" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3"></circle><path d="M5.5 20a6.5 6.5 0 0 1 13 0"></path></svg></span></div><div class="stat-value">{{ $stats['patients'] }}</div><div class="stat-caption">Active patient profiles</div></div>
    <div class="stat-card"><div class="stat-label">Devices online <span class="stat-icon stat-icon-device" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="12" rx="2"></rect><path d="M8 20h8M12 17v3"></path></svg></span></div><div class="stat-value">{{ $stats['online'] }}<small>/ {{ $stats['devices'] }}</small></div><div class="stat-caption">Seen within the last 90 seconds</div></div>
</div>
@include('partials.dashboard-charts')
@include('partials.patient-overview')
@if(!auth()->user()->isAdmin())
<section class="role-banner caregiver-banner"><div><div class="eyebrow">CAREGIVER VIEW</div><h2>Your assigned patients</h2><p>Review alerts, patient records, and device health. Ask an administrator to change assignments.</p></div><a class="button subtle" href="{{ route('patients.index') }}">View my patients &rarr;</a></section>
@endif
<section class="panel event-records-panel dashboard-event-panel"><div class="panel-heading"><div><h2>Active review queue <span class="count">{{ $events->count() }}</span></h2><p>Sensor candidates, verified alerts, and caregiver responses—newest first.</p></div><span class="live-label"><span class="dot"></span>Updates every 5 seconds</span></div>
@include('partials.event-table')
</section>
</div>
@endsection
