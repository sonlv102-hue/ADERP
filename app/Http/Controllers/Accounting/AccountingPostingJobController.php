<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccountingPostingJob;
use App\Services\AccountingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountingPostingJobController extends Controller
{
    public function __construct(private AccountingService $accounting) {}

    public function index(Request $request): Response
    {
        $query = AccountingPostingJob::with('createdBy')
            ->orderByDesc('id');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }
        if ($sourceType = $request->get('source_type')) {
            $query->where('source_type', $sourceType);
        }

        $jobs = $query->paginate(30)->through(fn ($job) => [
            'id'               => $job->id,
            'source_type'      => $job->source_type,
            'source_type_label'=> AccountingPostingJob::sourceTypeLabel($job->source_type),
            'source_id'        => $job->source_id,
            'posting_type'     => $job->posting_type,
            'status'           => $job->status->value,
            'status_label'     => $job->status->label(),
            'status_color'     => $job->status->color(),
            'journal_entry_id' => $job->journal_entry_id,
            'posting_date'     => $job->posting_date?->format('Y-m-d'),
            'description'      => $job->description,
            'error_code'       => $job->error_code,
            'error_message'    => $job->error_message,
            'attempts'         => $job->attempts,
            'last_attempted_at'=> $job->last_attempted_at?->format('d/m/Y H:i'),
            'posted_at'        => $job->posted_at?->format('d/m/Y H:i'),
        ]);

        return Inertia::render('Accounting/PostingJobs/Index', [
            'jobs'       => $jobs,
            'filters'    => $request->only(['status', 'source_type']),
            'sourceTypes'=> $this->getSourceTypes(),
        ]);
    }

    public function retry(AccountingPostingJob $accountingPostingJob): RedirectResponse
    {
        $this->authorize('accounting.manage');

        try {
            $this->accounting->retryJob($accountingPostingJob);
            return back()->with('success', 'Hạch toán thành công.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Hạch toán thất bại: ' . $e->getMessage());
        }
    }

    private function getSourceTypes(): array
    {
        return [
            'invoice'                    => 'Hóa đơn',
            'payment'                    => 'Thanh toán hóa đơn',
            'cash_voucher'               => 'Phiếu thu/chi',
            'prepaid_expense'            => 'Chi phí trả trước',
            'prepaid_expense_allocation' => 'Phân bổ CPTTT',
            'purchase_invoice_payment'   => 'Thanh toán NCC',
            'stock_entry'                => 'Phiếu nhập kho',
            'stock_exit'                 => 'Phiếu xuất kho',
            'payroll'                    => 'Bảng lương',
        ];
    }
}
