<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(): Response
    {
        $notifications = auth()->user()->notifications()
            ->orderByDesc('created_at')
            ->paginate(20)
            ->through(fn ($n) => [
                'id'         => $n->id,
                'type'       => $n->data['type'] ?? 'general',
                'title'      => $n->data['title'] ?? '',
                'message'    => $n->data['message'] ?? '',
                'url'        => $n->data['url'] ?? null,
                'icon'       => $n->data['icon'] ?? 'bell',
                'color'      => $n->data['color'] ?? 'blue',
                'read_at'    => $n->read_at?->format('d/m/Y H:i'),
                'created_at' => $n->created_at->format('d/m/Y H:i'),
            ]);

        return Inertia::render('Notifications/Index', compact('notifications'));
    }

    public function markRead(string $id): RedirectResponse
    {
        auth()->user()->notifications()->findOrFail($id)->markAsRead();
        return back();
    }

    public function markAllRead(): RedirectResponse
    {
        auth()->user()->unreadNotifications->markAsRead();
        return back();
    }

    public function unreadCount(): JsonResponse
    {
        return response()->json(['count' => auth()->user()->unreadNotifications()->count()]);
    }
}
