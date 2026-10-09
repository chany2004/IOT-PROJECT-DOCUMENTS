<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login() { return view('auth.login'); }
    public function authenticate(Request $request)
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $accountKey = 'login:failed:'.hash('sha256', Str::lower(trim($credentials['email'])).'|'.$request->ip());
        $ipKey = 'login:ip:'.$request->ip();

        if (RateLimiter::tooManyAttempts($accountKey, 5) || RateLimiter::tooManyAttempts($ipKey, 20)) {
            $seconds = max(RateLimiter::availableIn($accountKey), RateLimiter::availableIn($ipKey));
            throw ValidationException::withMessages([
                'email' => "Too many failed sign-in attempts. Please try again in {$seconds} seconds.",
            ]);
        }

        $credentials['is_active'] = true;
        if (!Auth::attempt($credentials)) {
            RateLimiter::hit($accountKey, 60);
            RateLimiter::hit($ipKey, 60);
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect, or the account is inactive.']);
        }
        RateLimiter::clear($accountKey);
        $request->session()->regenerate();
        return redirect()->intended(route('dashboard'));
    }
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
    public function account() { return view('auth.account'); }
    public function password(Request $request)
    {
        $data = $request->validate(['current_password' => ['required', 'current_password'], 'password' => ['required', 'confirmed', Password::min(12)]]);
        $request->user()->update(['password' => $data['password']]);
        $request->session()->regenerate();
        return back()->with('success', 'Password updated.');
    }
}
