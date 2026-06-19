<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SystemHealthService;
use Inertia\Inertia;
use Inertia\Response;

class SystemHealthController extends Controller
{
    public function __invoke(SystemHealthService $service): Response
    {
        return Inertia::render('Admin/SystemHealth', [
            'checks' => $service->getChecks(),
        ]);
    }
}
