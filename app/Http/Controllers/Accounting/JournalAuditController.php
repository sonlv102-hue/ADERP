<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\JournalAuditService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JournalAuditController extends Controller
{
    public function index(Request $request, JournalAuditService $service): Response
    {
        $this->authorize('accounting.manage');

        $filters = [
            'from'     => $request->get('from'),
            'to'       => $request->get('to'),
            'severity' => $request->get('severity', ''),
            'type'     => $request->get('type', ''),
        ];

        $findings = [];
        $summary  = ['total' => 0, 'critical' => 0, 'warning' => 0];
        $ranAudit = false;

        // Chỉ chạy audit khi có filter date (tránh scan toàn bộ DB khi mở trang lần đầu)
        if ($filters['from'] || $filters['to']) {
            $ranAudit = true;
            $all = $service->run([
                'from' => $filters['from'],
                'to'   => $filters['to'],
            ]);

            // Filter phía app sau khi có kết quả
            if ($filters['severity']) {
                $all = array_filter($all, fn($f) => $f['severity'] === $filters['severity']);
            }
            if ($filters['type']) {
                $types = explode(',', $filters['type']);
                $all   = array_filter($all, fn($f) => in_array($f['error_code'], $types));
            }

            $findings = array_values($all);
            $summary  = [
                'total'    => count($findings),
                'critical' => count(array_filter($findings, fn($f) => $f['severity'] === 'critical')),
                'warning'  => count(array_filter($findings, fn($f) => $f['severity'] === 'warning')),
            ];
        }

        return Inertia::render('Accounting/JournalAudit/Index', [
            'findings'   => $findings,
            'summary'    => $summary,
            'filters'    => $filters,
            'errorCodes' => JournalAuditService::ERROR_CODES,
            'ranAudit'   => $ranAudit,
        ]);
    }
}
