<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    protected const MAX_LOGIN_ATTEMPTS = 5;
    protected const DECAY_SECONDS = 60;

    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $throttleKey = $this->throttleKey($request);

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_LOGIN_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => 'Quá nhiều lần đăng nhập không thành công. Vui lòng thử lại sau ít phút.',
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => 'Email hoặc mật khẩu không đúng.',
            ]);
        }

        if (! Auth::user()->is_active) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => 'Tài khoản của bạn đã bị khóa.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    protected function throttleKey(Request $request): string
    {
        return Str::lower($request->input('email')) . '|' . $request->ip();
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
