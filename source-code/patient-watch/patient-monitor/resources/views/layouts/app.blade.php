<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Overview') | Patient Watch</title>
    <link rel="stylesheet" href="{{ asset('monitor.css') }}?v={{ filemtime(public_path('monitor.css')) }}">
    <link rel="stylesheet" href="{{ asset('responsive.css') }}?v={{ filemtime(public_path('responsive.css')) }}">
    @if(request()->routeIs('dashboard'))
    <link rel="stylesheet" href="{{ asset('patient-overview.css') }}?v={{ filemtime(public_path('patient-overview.css')) }}">
    @endif
    <script src='{{ asset('auth-history.js') }}?v={{ filemtime(public_path('auth-history.js')) }}' defer></script>
    @if(request()->routeIs('dashboard'))
    <link rel='stylesheet' href='{{ asset('dashboard-charts.css') }}?v={{ filemtime(public_path('dashboard-charts.css')) }}'>
    <script src='{{ asset('dashboard-charts.js') }}?v={{ filemtime(public_path('dashboard-charts.js')) }}' defer></script>
    @endif
    @if(request()->routeIs('devices.index'))
    <link rel="stylesheet" href="{{ asset('device-management.css') }}?v={{ filemtime(public_path('device-management.css')) }}">
    @endif
    @if(request()->routeIs('users.index'))
    <link rel="stylesheet" href="{{ asset('user-management.css') }}?v={{ filemtime(public_path('user-management.css')) }}">
    <script src="{{ asset('user-management.js') }}?v={{ filemtime(public_path('user-management.js')) }}" defer></script>
    @endif
    <script src="{{ asset('monitor.js') }}?v={{ filemtime(public_path('monitor.js')) }}" defer></script>
    @if(request()->routeIs('rooms.index'))
    <link rel="stylesheet" href="{{ asset('room-management.css') }}?v={{ filemtime(public_path('room-management.css')) }}">
    @endif
    <link rel="stylesheet" href="{{ asset('mobile.css') }}?v={{ filemtime(public_path('mobile.css')) }}">
    @if(request()->routeIs('devices.index'))
    <link rel="stylesheet" href="{{ asset('device-registration-modal.css') }}?v={{ filemtime(public_path('device-registration-modal.css')) }}">
    <link rel="stylesheet" href="{{ asset('device-action-modal.css') }}?v={{ filemtime(public_path('device-action-modal.css')) }}">
    <script src="{{ asset('device-registration-modal.js') }}?v={{ filemtime(public_path('device-registration-modal.js')) }}" defer></script>
    <script src="{{ asset('device-action-modal.js') }}?v={{ filemtime(public_path('device-action-modal.js')) }}" defer></script>
    @endif
    @if(request()->routeIs('patients.index'))
    <link rel="stylesheet" href="{{ asset('patient-modal.css') }}?v={{ filemtime(public_path('patient-modal.css')) }}">
    <link rel="stylesheet" href="{{ asset('patient-directory.css') }}?v={{ filemtime(public_path('patient-directory.css')) }}">
    <script src="{{ asset('patient-modal.js') }}?v={{ filemtime(public_path('patient-modal.js')) }}" defer></script>
    @endif
    @if(request()->routeIs('events.index', 'dashboard', 'patients.show'))
    <link rel="stylesheet" href="{{ asset('event-history.css') }}?v={{ filemtime(public_path('event-history.css')) }}">
    @endif
    @if(request()->routeIs('events.show'))
    <link rel="stylesheet" href="{{ asset('event-review.css') }}?v={{ filemtime(public_path('event-review.css')) }}">
    <script src="{{ asset('event-review.js') }}?v={{ filemtime(public_path('event-review.js')) }}" defer></script>
    @endif
    @if(request()->routeIs('patients.show') && config('watcher.channel') === 'telegram')
    <script src="{{ asset('watcher-telegram.js') }}?v={{ filemtime(public_path('watcher-telegram.js')) }}" defer></script>
    @endif
</head>
<body data-feed="{{ route('monitor.feed') }}" data-user="{{ auth()->id() }}">
<aside class="sidebar">
    <div class="sidebar-header">
    <a class="brand" href="{{ route('dashboard') }}" aria-label="Patient Watch overview">
        <span class="brand-icon" aria-hidden="true"><svg viewBox="0 0 32 32" role="img"><path d="M16 27.2S5.7 21.4 5.7 13.2A5.7 5.7 0 0 1 16 9.9a5.7 5.7 0 0 1 10.3 3.3C26.3 21.4 16 27.2 16 27.2Z"></path><path d="M8.5 15.7h4l1.4-3.1 2.4 6 2-4.1h5.2"></path></svg></span>
        <span class="brand-wordmark"><span class="brand-name">patient</span><span class="brand-light">watch</span><small>FALL DETECTION <i></i> CARE</small></span>
    </a>
    <button class="menu-toggle" type="button" aria-controls="main-navigation sidebar-session" aria-expanded="false" aria-label="Open navigation">
        <span aria-hidden="true" class="menu-icon"></span><span data-menu-label>Menu</span>
    </button>
    </div>
    <div class="workspace-label">CARE WORKSPACE</div>
    <nav id="main-navigation" aria-label="Main navigation">
        <a @class(['nav-link', 'active' => request()->routeIs('dashboard')]) href="{{ route('dashboard') }}"><span class="nav-index">01</span><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m4 10 8-6 8 6v9a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-9Z"></path><path d="M9 20v-5h6v5"></path></svg></span><span class="nav-copy">Overview</span></a>
        <a @class(['nav-link', 'active' => request()->routeIs('events.*')]) href="{{ route('events.index') }}"><span class="nav-index">02</span><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg></span><span class="nav-copy">Alerts & history</span><b id="nav-alert-count"></b></a>
        <a @class(['nav-link', 'active' => request()->routeIs('patients.*')]) href="{{ route('patients.index') }}"><span class="nav-index">03</span><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.2"></circle><path d="M5.5 20a6.5 6.5 0 0 1 13 0"></path><path d="M19 6.5h3M20.5 5v3"></path></svg></span><span class="nav-copy">Patients</span></a>
        <a @class(['nav-link', 'active' => request()->routeIs('devices.*')]) href="{{ route('devices.index') }}"><span class="nav-index">04</span><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3.5" y="4.5" width="17" height="12" rx="2"></rect><path d="M8 20h8M12 16.5V20"></path><path d="M8 8h8M8 11h5"></path></svg></span><span class="nav-copy">Connected devices</span></a>
        @if(auth()->user()->isAdmin())
        <div class="workspace-label">ADMINISTRATION</div>
        <a @class(['nav-link', 'active' => request()->routeIs('rooms.*')]) href="{{ route('rooms.index') }}"><span class="nav-index">05</span><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 20V6l8-3 8 3v14"></path><path d="M8 20v-5h8v5M8 9h.01M12 9h.01M16 9h.01M8 12h.01M16 12h.01"></path></svg></span><span class="nav-copy">Rooms</span></a>
        <a @class(['nav-link', 'active' => request()->routeIs('users.*')]) href="{{ route('users.index') }}"><span class="nav-index">06</span><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"></circle><path d="M3.5 20a5.5 5.5 0 0 1 11 0M16 11a3 3 0 1 0 0-6M16 14a5 5 0 0 1 4.5 6"></path></svg></span><span class="nav-copy">User accounts</span></a>
        @endif
    </nav>
    <div class="sidebar-bottom" id="sidebar-session">
        <form method="post" action="{{ route('logout') }}">
            @csrf
            <button class="sidebar-logout" type="submit">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4"></path><path d="m15 16 4-4-4-4M19 12H9"></path></svg>
                <span>Sign out</span>
            </button>
        </form>
    </div>
</aside>
<div class="shell">
    <header class="topbar"><span class="breadcrumb">Care workspace <span>/</span> @yield('title', 'Overview')</span>
        <div class="topbar-actions"><button class="button subtle small" id="sound-toggle" type="button" aria-pressed="false">Enable alert sound</button>
        <a class="account-link" href="{{ route('account') }}" aria-label="My account"><span class="avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span><span>{{ auth()->user()->name }}<small>{{ ucfirst(auth()->user()->role) }}</small></span></a></div>
    </header>
    <div class="connection-strip"><span class="dot" id="connection-dot"></span><span id="connection-status" role="status">Connecting to monitoring service...</span><span class="timezone">Times shown in Philippine time (UTC+8)</span></div>
    <main id="main-content" tabindex="-1">
        @if(session('success'))<div class="notice success" role="status">{{ session('success') }}</div>@endif
        @if(session('warning'))<div class="notice warning" role="status">{{ session('warning') }}</div>@endif
        @if($errors->any())<div class="notice danger" role="alert"><strong>Please check your entry.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @if(session('device_key'))<div class="notice warning"><strong>New key for {{ session('device_code') }}</strong><p>This key is shown once. Use it as X-Device-Key in the device or gateway configuration.</p><code class="secret">{{ session('device_key') }}</code></div>@endif
        @yield('content')
    </main>
    <footer>Patient Watch <span>Caregiver review is required. Check device connectivity regularly.</span></footer>
</div>
<div class="emergency-alert-layer" id="emergency-alert-layer" hidden>
    <section class="emergency-alert-dialog" role="alertdialog" aria-modal="true" aria-labelledby="emergency-alert-title" aria-describedby="emergency-alert-message" tabindex="-1">
        <header class="emergency-alert-header">
            <div class="emergency-alert-heading">
                <span class="emergency-alert-symbol" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M12 3 2.8 19h18.4L12 3Z"></path><path d="M12 9v4"></path><path d="M12 16.5h.01"></path></svg>
                </span>
                <div><span class="emergency-alert-kicker" id="emergency-alert-kicker">URGENT ALERT</span><h2 id="emergency-alert-title">Emergency alert</h2></div>
            </div>
            <div class="emergency-alert-header-actions">
                <span class="emergency-alert-clock" id="emergency-alert-time">Just now</span>
                <button class="emergency-alert-sound" type="button" id="emergency-alert-sound" aria-pressed="false">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 5 6.8 8.5H3.5v7h3.3L11 19V5Z"></path><path d="M15 9a4 4 0 0 1 0 6M18 6a8 8 0 0 1 0 12"></path></svg>
                    <span>Buzzer off</span>
                </button>
                <button type="button" id="emergency-alert-minimize" aria-label="Close emergency details">Close</button>
            </div>
        </header>
        <div class="emergency-alert-body">
            <div class="emergency-patient-summary">
                <div><span class="emergency-field-label">PATIENT</span><strong id="emergency-alert-patient">Patient</strong><span id="emergency-alert-room">Room not assigned</span></div>
                <span class="emergency-patient-state" id="emergency-alert-patient-status">Immediate patient check required</span>
            </div>
            <div class="emergency-alert-grid">
                <div class="emergency-camera-card">
                    <img id="emergency-alert-image" alt="Latest camera evidence for this emergency" hidden>
                    <button class="emergency-image-expand" id="emergency-image-expand" type="button" aria-label="View full camera image" hidden>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3H3v5M16 3h5v5M3 16v5h5M21 16v5h-5"></path></svg>
                        <span>View full image</span>
                    </button>
                    <div class="emergency-camera-placeholder" id="emergency-camera-placeholder">
                        <span class="emergency-camera-reticle" aria-hidden="true">[ + ]</span>
                        <svg class="emergency-camera-ecg" viewBox="0 0 1100 500" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <path class="emergency-camera-ecg-base" d="M20 250H235l42 39 36-84 38 45 72-101 88 148 99-255 83 430 66-292 55 85 43-50 32 35H1080"/>
                            <path class="emergency-camera-ecg-trace" pathLength="100" d="M20 250H235l42 39 36-84 38 45 72-101 88 148 99-255 83 430 66-292 55 85 43-50 32 35H1080"/>
                        </svg>
                        <strong>Camera capture pending</strong>
                        <small>The emergency does not wait for an image.</small>
                    </div>
                    <div class="emergency-camera-meta"><span>Snapshot</span><strong id="emergency-alert-camera-status">Pending</strong></div>
                </div>
                <dl class="emergency-alert-details">
                    <div><dt>Assigned caregiver</dt><dd id="emergency-alert-caregiver">Unassigned</dd></div>
                    <div><dt>Main sensor</dt><dd id="emergency-alert-device">Not available</dd></div>
                    <div><dt>Device health</dt><dd id="emergency-alert-device-status">Checking…</dd></div>
                    <div><dt>Camera device</dt><dd id="emergency-alert-camera-device">Not assigned</dd></div>
                    <div><dt>Response</dt><dd id="emergency-alert-response">New alert</dd></div>
                    <div><dt>Detected</dt><dd id="emergency-alert-detected">Just now</dd></div>
                </dl>
            </div>
            <p class="emergency-alert-message" id="emergency-alert-message" role="status"></p>
        </div>
        <footer class="emergency-alert-actions">
            <button class="button emergency-acknowledge" type="button" id="emergency-alert-acknowledge">Acknowledge — I’m responding</button>
            <a class="button subtle" id="emergency-alert-view" href="#">View full alert</a>
            <button class="button subtle" type="button" id="emergency-alert-next" hidden>Next alert</button>
            <span class="emergency-alert-queue" id="emergency-alert-queue-count"></span>
        </footer>
    </section>
</div>
<button class="emergency-alert-reopen" id="emergency-alert-reopen" type="button" hidden><span aria-hidden="true"></span><strong>Emergency alert</strong><small id="emergency-alert-reopen-count">Open alert</small></button>
<dialog class="emergency-image-viewer" id="emergency-image-viewer" aria-label="Full camera image">
    <div class="emergency-image-viewer-bar">
        <div><span>CAMERA EVIDENCE</span><strong id="emergency-image-viewer-title">Patient image</strong></div>
        <div class="emergency-image-viewer-controls">
            <button type="button" id="emergency-image-zoom-out" aria-label="Zoom out">&minus;</button>
            <output id="emergency-image-zoom-level" aria-live="polite">100%</output>
            <button type="button" id="emergency-image-zoom-in" aria-label="Zoom in">+</button>
            <button type="button" id="emergency-image-zoom-reset">Reset</button>
            <button type="button" id="emergency-image-viewer-close">Close</button>
        </div>
    </div>
    <div class="emergency-image-viewer-stage" id="emergency-image-viewer-stage">
        <img id="emergency-image-viewer-photo" alt="Full camera evidence" draggable="false">
    </div>
    <p>Scroll or pinch to zoom. Drag to move the image. Press Esc to close.</p>
</dialog>
</body>
</html>
