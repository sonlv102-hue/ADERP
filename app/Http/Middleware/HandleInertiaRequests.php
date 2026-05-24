<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user()?->only('id', 'name', 'email', 'phone', 'avatar'),
                'permissions' => $request->user()?->getAllPermissions()->pluck('name') ?? [],
                'roles' => $request->user()?->getRoleNames() ?? [],
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
                'warning' => session('warning'),
            ],
            'appName' => config('app.name'),
            'company' => Setting::getGroup('company'),
        ];
    }
}
