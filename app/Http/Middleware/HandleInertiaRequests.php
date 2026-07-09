<?php

namespace App\Http\Middleware;

use App\Models\JournalEntry;
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
                'permissions' => $request->user()?->getAllPermissions()->pluck('code')->toArray() ?? [],
                'roles' => $request->user()?->getRoleNames() ?? [],
            ],
            'menuItems' => $request->user()
                ? \App\Models\MenuItem::where('is_active', true)
                    ->whereNull('parent_id')
                    ->orderBy('order')
                    ->get()
                    ->map(function ($item) use ($request) {
                        if ($item->required_permission && !$request->user()->hasPermission($item->required_permission)) {
                            return null;
                        }

                        $children = $item->children()
                            ->where('is_active', true)
                            ->orderBy('order')
                            ->get()
                            ->filter(function ($child) use ($request) {
                                if ($child->required_permission && !$request->user()->hasPermission($child->required_permission)) {
                                    return false;
                                }
                                return true;
                            })
                            ->values();

                        if (empty($item->route_name) && $children->isEmpty()) {
                            return null;
                        }

                        $item->setRelation('children', $children);
                        return $item;
                    })
                    ->filter()
                    ->values()
                : [],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
                'warning' => session('warning'),
            ],
            'appName' => config('app.name'),
            'company' => Setting::getGroup('company'),
            'draftJournalEntryCount' => $request->user()?->can('accounting.view')
                ? JournalEntry::where('status', 'draft')->count()
                : 0,
        ];
    }
}
