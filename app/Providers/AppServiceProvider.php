<?php

namespace App\Providers;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use App\Models\Permission;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Event::listen(Login::class, function (Login $event) {
            activity()
                ->causedBy($event->user)
                ->withProperties(['ip' => request()->ip(), 'user_agent' => request()->userAgent()])
                ->log('Đăng nhập');
        });

        Event::listen(Logout::class, function (Logout $event) {
            if ($event->user) {
                activity()
                    ->causedBy($event->user)
                    ->log('Đăng xuất');
            }
        });

        // Dynamic Gate resolver with Super Admin wildcard and user overrides check
        Gate::before(function ($user, $ability) {
            if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
                return true;
            }

            if (method_exists($user, 'hasPermission')) {
                $denyOverride = $user->permissions()
                    ->where('code', $ability)
                    ->where('user_permissions.effect', 'deny')
                    ->exists();
                if ($denyOverride) {
                    return false;
                }

                if ($user->hasPermission($ability)) {
                    return true;
                }
            }
        });
    }
}
