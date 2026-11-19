<?php

namespace App\Http\Controllers\Accounting;

use App\Exports\JournalVouchersExport;
use App\Http\Controllers\Controller;
use App\Services\JournalVoucherPrintService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * In / xuất Phiếu kế toán (1 hoặc nhiều phiếu) ra PDF hoặc Excel.
 * GET ?ids[]=1&ids[]=2 — tối đa JournalVoucherPrintService::MAX_ENTRIES phiếu/lần.
 */
class JournalEntryPrintController extends Controller
{
    public function __construct(private JournalVoucherPrintService $service) {}

    public function pdf(Request $request): Response
    {
        [$vouchers, $company, $place, $signers] = $this->prepare($request);

        return Pdf::loadView('pdf.journal-voucher', [
            'vouchers'     => $vouchers,
            'company'      => $company,
            'signingPlace' => $place,
            'signers'      => $signers,
        ])->setPaper('a4', 'portrait')->stream($this->fileName($vouchers, 'pdf'));
    }

    public function excel(Request $request): Response
    {
        [$vouchers, $company, $place, $signers] = $this->prepare($request);

        return Excel::download(
            new JournalVouchersExport($vouchers, $company, $place, $signers),
            $this->fileName($vouchers, 'xlsx'),
        );
    }

    private function prepare(Request $request): array
    {
        $max = JournalVoucherPrintService::MAX_ENTRIES;
        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1', "max:{$max}"],
            'ids.*' => ['integer', 'distinct'],
        ], [
            'ids.required' => 'Chưa chọn phiếu kế toán nào.',
            'ids.max'      => "Chỉ xuất tối đa {$max} phiếu mỗi lần.",
        ]);

        $vouchers = $this->service->build($data['ids']);
        abort_if(empty($vouchers), 404, 'Không tìm thấy phiếu kế toán.');

        $signers = [];
        foreach ($vouchers as $v) {
            $signers[$v['id']] = $this->service->signers($v);
        }

        return [$vouchers, $this->service->company(), $this->service->signingPlace(), $signers];
    }

    private function fileName(array $vouchers, string $ext): string
    {
        if (count($vouchers) === 1) {
            $code = preg_replace('/[^A-Za-z0-9_-]+/', '-', $vouchers[0]['code']);
            return "PhieuKeToan-{$code}.{$ext}";
        }

        return 'PhieuKeToan_' . count($vouchers) . '-phieu_' . now()->format('Ymd_His') . ".{$ext}";
    }
}
