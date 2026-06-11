<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccountCode;
use App\Models\JournalEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class OpeningBalanceController extends Controller
{
    public function index(): Response
    {
        // Lấy bút toán số dư đầu kỳ hiện tại (nếu có)
        $entry = JournalEntry::with('lines')
            ->where('reference_type', 'opening_balance')
            ->orderByDesc('entry_date')
            ->first();

        $existingLines = $entry
            ? $entry->lines->keyBy('account_code')
            : collect();

        // Lấy tất cả tài khoản chi tiết đang hoạt động
        $accounts = AccountCode::where('is_detail', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->map(fn ($a) => [
                'code'           => $a->code,
                'name'           => $a->name,
                'type'           => $a->type,
                'type_label'     => $a->typeLabel(),
                'normal_balance' => $a->normal_balance,
                'debit'          => (int) ($existingLines[$a->code]?->debit  ?? 0),
                'credit'         => (int) ($existingLines[$a->code]?->credit ?? 0),
            ]);

        return Inertia::render('Accounting/OpeningBalance/Index', [
            'accounts'   => $accounts,
            'entry_date' => $entry?->entry_date?->format('Y-m-d')
                ?? now()->startOfYear()->format('Y-m-d'),
            'has_entry'  => (bool) $entry,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'entry_date'              => ['required', 'date'],
            'lines'                   => ['required', 'array'],
            'lines.*.account_code'    => ['required', 'exists:account_codes,code'],
            'lines.*.debit'           => ['required', 'numeric', 'min:0'],
            'lines.*.credit'          => ['required', 'numeric', 'min:0'],
        ]);

        $lines = array_values(array_filter(
            $request->input('lines'),
            fn ($l) => ((int) $l['debit']) > 0 || ((int) $l['credit']) > 0
        ));

        DB::transaction(function () use ($request, $lines) {
            // Xóa bút toán cũ (nếu có)
            JournalEntry::where('reference_type', 'opening_balance')->delete();

            if (empty($lines)) return;

            $entryDate    = $request->input('entry_date');
            $fiscalPeriod = substr($entryDate, 0, 7); // 'YYYY-MM'

            $entry = JournalEntry::create([
                'code'                         => 'SDDK',
                'entry_date'                   => $entryDate,
                'description'                  => 'Số dư đầu kỳ',
                'reference_type'               => 'opening_balance',
                'source_type'                  => 'opening_balance',
                'fiscal_period'                => $fiscalPeriod,
                'exclude_from_period_movement' => true,
                'status'                       => 'posted',
                'is_auto'                      => false,
                'created_by'                   => auth()->id(),
                'posted_at'                    => now(),
            ]);

            foreach ($lines as $idx => $line) {
                $entry->lines()->create([
                    'account_code' => $line['account_code'],
                    'debit'        => (int) $line['debit'],
                    'credit'       => (int) $line['credit'],
                    'description'  => 'Số dư đầu kỳ',
                    'sort_order'   => $idx + 1,
                ]);
            }
        });

        return back()->with('success', 'Đã lưu số dư đầu kỳ thành công.');
    }

    public function importExcel(Request $request): RedirectResponse
    {
        $request->validate([
            'excel_file' => ['required', 'file', 'max:20480'],
            'entry_date' => ['required', 'date'],
        ]);

        try {
            $parsed = $this->parseMisaClosingBalance($request->file('excel_file'));
        } catch (\Throwable $e) {
            \Log::error('MISA import failed', ['error' => $e->getMessage()]);
            return back()->withErrors(['excel_file' => $e->getMessage()]);
        }

        $account = AccountCode::where('code', $parsed['account_code'])->first();
        if (!$account) {
            return back()->withErrors(['excel_file' => "Tài khoản {$parsed['account_code']} không tồn tại trong hệ thống."]);
        }

        DB::transaction(function () use ($request, $parsed) {
            $entry = JournalEntry::where('reference_type', 'opening_balance')->first();

            if (!$entry) {
                $importDate   = $request->input('entry_date');
                $entry = JournalEntry::create([
                    'code'                         => 'SDDK',
                    'entry_date'                   => $importDate,
                    'description'                  => 'Số dư đầu kỳ',
                    'reference_type'               => 'opening_balance',
                    'source_type'                  => 'opening_balance',
                    'fiscal_period'                => substr($importDate, 0, 7),
                    'exclude_from_period_movement' => true,
                    'status'                       => 'posted',
                    'is_auto'                      => false,
                    'created_by'                   => auth()->id(),
                    'posted_at'                    => now(),
                ]);
            }

            $maxOrder = $entry->lines()->max('sort_order') ?? 0;
            $entry->lines()->updateOrCreate(
                ['account_code' => $parsed['account_code']],
                [
                    'debit'       => $parsed['debit'],
                    'credit'      => $parsed['credit'],
                    'description' => 'Số dư đầu kỳ',
                    'sort_order'  => $maxOrder + 1,
                ]
            );
        });

        $balance = $parsed['debit'] > 0
            ? 'Nợ ' . number_format($parsed['debit'])
            : 'Có ' . number_format($parsed['credit']);

        return back()->with('success',
            "Đã import TK {$parsed['account_code']} ({$account->name}): {$balance} VND " .
            "(tính từ {$parsed['tx_count']} phát sinh)."
        );
    }

    // ─── Private ────────────────────────────────────────────────────────────────

    /**
     * Tính số dư cuối kỳ bằng cách cộng toàn bộ phát sinh trong file
     * (chính xác hơn đọc ô "Số dư cuối kỳ" do tránh lỗi làm tròn MISA)
     */
    private function parseMisaClosingBalance(UploadedFile $file): array
    {
        $rows = Excel::toArray([], $file)[0] ?? [];

        $accountCode = null;
        $openingDebit  = 0.0;
        $openingCredit = 0.0;
        $txDebit  = 0.0;
        $txCredit = 0.0;
        $txCount  = 0;

        foreach ($rows as $row) {
            $col0 = trim((string)($row[0] ?? ''));
            $col3 = trim((string)($row[3] ?? ''));
            $col5 = trim((string)($row[5] ?? ''));
            $col6 = trim((string)($row[6] ?? ''));
            $col7 = (float)($row[7] ?? 0);  // Phát sinh Nợ
            $col9 = (float)($row[9] ?? 0);  // Phát sinh Có

            // Lấy mã tài khoản từ header
            if (!$accountCode && str_contains($col0, 'Tài khoản:')) {
                if (preg_match('/Tài khoản:\s*([\w\.]+)/u', $col0, $m)) {
                    $accountCode = trim($m[1]);
                }
            }

            // Dòng "Số dư đầu kỳ" — số dư trước kỳ báo cáo
            if (str_contains($col3, 'Số dư đầu kỳ') && !$col6) {
                $openingDebit  = (float)($row[12] ?? 0); // Dư Nợ col 12
                $openingCredit = (float)($row[13] ?? 0); // Dư Có col 13
                continue;
            }

            // Bỏ qua dòng tổng kết
            if (str_contains($col3, 'Số dư cuối kỳ') || str_contains($col3, 'Phát sinh')) continue;

            // Dòng giao dịch: có số chứng từ và TK đối ứng
            if (!trim((string)($row[2] ?? '')) || !$col6) continue;
            if (!preg_match('#^\d{2}/\d{2}/\d{4}$#', $col0)) continue;

            $txDebit  += $col7;
            $txCredit += $col9;
            $txCount++;
        }

        if (!$accountCode) {
            throw new \RuntimeException('Không tìm thấy mã tài khoản. Đảm bảo file là sổ chi tiết MISA.');
        }

        // Số dư cuối = Số dư đầu + Phát sinh Nợ - Phát sinh Có (đối với TK có số dư bên Nợ)
        $grossDebit  = $openingDebit  + $txDebit;
        $grossCredit = $openingCredit + $txCredit;
        $net = $grossDebit - $grossCredit;

        $debit  = $net > 0 ? (int) round($net) : 0;
        $credit = $net < 0 ? (int) round(-$net) : 0;

        return [
            'account_code' => $accountCode,
            'debit'        => $debit,
            'credit'       => $credit,
            'tx_count'     => $txCount,
        ];
    }
}
