<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Sign in | Patient Watch</title>
    <link rel="stylesheet" href="{{ asset('monitor.css') }}?v={{ filemtime(public_path('monitor.css')) }}">
    <link rel="stylesheet" href="{{ asset('mobile.css') }}?v={{ filemtime(public_path('mobile.css')) }}">
    <link rel="stylesheet" href="{{ asset('login.css') }}?v={{ filemtime(public_path('login.css')) }}">
    <script src="{{ asset('login.js') }}?v={{ filemtime(public_path('login.js')) }}" defer></script>
</head>
<body class="login-page">
    <section class="login-story" aria-labelledby="login-story-title">
        <a class="brand" href="{{ route('login') }}" aria-label="Patient Watch home">
            <span class="brand-icon" aria-hidden="true">+</span>
            <span>patient<span class="brand-light">watch</span><small>FALL DETECTION & CARE</small></span>
        </a>

        <div class="login-story-content">
            <div class="eyebrow">CONNECTED CARE, CLOSER ATTENTION</div>
            <h1 id="login-story-title"><span>Every alert.</span> <span>A person who</span> <span>needs your care.</span></h1>
            <p>A shared place to monitor patient activity, review fall alerts, and record a timely response.</p>
            <div class="login-pulse" aria-hidden="true">
                <svg viewBox="0 0 500 100" preserveAspectRatio="none"><path d="M0 55 H130 L155 55 L170 30 L195 85 L220 5 L245 65 L265 55 H500"/></svg>
            </div>
        </div>

        <div class="login-story-footer"><span class="login-story-dot" aria-hidden="true"></span> Patient-end fall detection & care</div>
    </section>

    <main class="login-form">
        <div class="login-form-inner">
            <div class="eyebrow">SECURE ACCESS</div>
            <h2>Sign in to Patient Watch</h2>
            <p class="login-intro">Use your account to review patient monitoring and alerts.</p>

            @if(request()->boolean('expired'))
                <div class="notice danger" role="alert">Your session expired or the address changed. Please sign in again.</div>
            @endif
            @if($errors->any())
                <div class="notice danger" role="alert">{{ $errors->first() }}</div>
            @endif

            <form id="login-form" method="post" action="{{ route('login') }}">
                @csrf
                <label class="login-field" for="login-email">Email
                    <input id="login-email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" inputmode="email" autocapitalize="none" spellcheck="false" required placeholder="name@example.com" @if($errors->has('email')) aria-invalid="true" @endif>
                </label>
                <div class="login-password-field">
                    <label for="login-password">Password</label>
                    <div class="login-password-control">
                        <input id="login-password" type="password" name="password" autocomplete="current-password" required placeholder="Enter your password" @if($errors->has('password')) aria-invalid="true" @endif>
                        <button id="login-password-toggle" type="button" aria-controls="login-password" aria-pressed="false" aria-label="Show password" title="Show password">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                <path d="M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6-10-6-10-6Z"/>
                                <circle cx="12" cy="12" r="2.5"/>
                                <path class="login-password-slash" d="M4 20 20 4"/>
                            </svg>
                        </button>
                    </div>
                </div>
                <button id="login-submit" class="button full login-submit" type="submit">
                    <span>Sign in</span>
                    <span class="login-submit-arrow" aria-hidden="true">&rarr;</span>
                </button>
            </form>

            <p class="login-help">Need help signing in? Contact your system administrator.</p>
        </div>
    </main>
    <div id="login-loading" class="login-loading" role="status" aria-live="polite" hidden>
        <div class="login-loading-content">
            <svg class="login-ecg" viewBox="0 0 1100 500" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                <path class="login-ecg-base" d="M20 250H235l42 39 36-84 38 45 72-101 88 148 99-255 83 430 66-292 55 85 43-50 32 35H1080"/>
                <path class="login-ecg-trace" pathLength="100" d="M20 250H235l42 39 36-84 38 45 72-101 88 148 99-255 83 430 66-292 55 85 43-50 32 35H1080"/>
            </svg>
            <div class="login-loading-copy">
                <h3>Signing you in</h3>
                <p>Preparing your Patient Watch workspace...</p>
            </div>
        </div>
    </div>
</body>
</html>
