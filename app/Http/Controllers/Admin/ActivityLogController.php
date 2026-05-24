<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = Activity::with(['causer', 'subject'])
            ->when($request->causer_id, fn ($q) => $q->where('causer_id', $request->causer_id))
            ->when($request->subject_type, fn ($q) => $q->whereRaw('LOWER(subject_type) LIKE ?', ['%' . strtolower($request->subject_type) . '%']))
            ->when($request->date_from, fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->orderByDesc('id')
            ->paginate(50)
            ->through(fn ($log) => [
                'id'           => $log->id,
                'log_name'     => $log->log_name,
                'description'  => $log->description,
                'subject_type' => $log->subject_type ? class_basename($log->subject_type) : null,
                'subject_id'   => $log->subject_id,
                'causer_name'  => $log->causer?->name ?? 'System',
                'properties'   => collect($log->properties)->only(['old', 'new', 'attributes'])->map(function ($val) {
                    if (is_array($val)) {
                        return collect($val)->except(['password', 'remember_token', 'two_factor_secret'])->toArray();
                    }
                    return $val;
                })->toArray(),
                'created_at'   => $log->created_at->format('d/m/Y H:i:s'),
            ]);

        $subjectTypes = Activity::distinct()->pluck('subject_type')
            ->filter()
            ->map(fn ($t) => class_basename($t))
            ->unique()
            ->values();

        $users = User::select('id', 'name')->orderBy('name')->get();

        return Inertia::render('Admin/ActivityLogs/Index', compact('logs', 'subjectTypes', 'users'));
    }
}
