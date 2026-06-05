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

            $entry = JournalEntry::create([
                'code'           => 'SDDK',
                'entry_date'     => $request->input('entry_date'),
                'description'    => 'Số dư đầu kỳ',
                'reference_type' => 'opening_balance',
                'status'         => 'posted',
                'is_auto'        => false,
                'created_by'     => auth()->id(),
                'posted_at'      => now(),
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
        ]);

        try {
            $result = $this->importMisaTransactions($request->file('excel_file'));
        } catch (\Throwable $e) {
            \Log::error('MISA import failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return back()->withErrors(['excel_file' => $e->getMessage()]);
        }

        return back()->with('success',
            "Đã import TK {$result['account_code']}: {$result['imported']} chứng từ, {$result['lines']} dòng. " .
            ($result['updated'] > 0 ? "Cập nhật lại {$result['updated']} chứng từ đã tồn tại." : '')
        );
    }

    // ─── Private ────────────────────────────────────────────────────────────────

    private function importMisaTransactions(UploadedFile $file): array
    {
        $rows = Excel::toArray([], $file)[0] ?? [];

        $accountCode = null;
        $documents   = []; // doc_number => ['date'=>..., 'desc'=>..., 'lines'=>[acc_code=>['debit'=>,'credit'=>]]]

        foreach ($rows as $row) {
            $col0 = trim((string)($row[0] ?? ''));
            $col2 = trim((string)($row[2] ?? '')); // Số chứng từ
            $col3 = trim((string)($row[3] ?? '')); // Diễn giải
            $col5 = trim((string)($row[5] ?? '')); // Tài khoản chính
            $col6 = trim((string)($row[6] ?? '')); // TK đối ứng
            $col7 = (float)($row[7] ?? 0);          // Phát sinh Nợ
            $col9 = (float)($row[9] ?? 0);          // Phát sinh Có

            // Lấy mã tài khoản từ header
            if (!$accountCode && str_contains($col0, 'Tài khoản:')) {
                if (preg_match('/Tài khoản:\s*([\w\.]+)/u', $col0, $m)) {
                    $accountCode = trim($m[1]);
                }
            }

            // Bỏ qua dòng không phải giao dịch
            if (!$col2 || !$col6) continue;
            if (str_contains($col3, 'Số dư') || str_contains($col3, 'Phát sinh')) continue;

            // Parse ngày DD/MM/YYYY
            $date = null;
            if ($col0 && preg_match('#^\d{2}/\d{2}/\d{4}$#', $col0)) {
                $d = \DateTime::createFromFormat('d/m/Y', $col0);
                if ($d) $date = $d->format('Y-m-d');
            }
            if (!$date) continue;

            if (!isset($documents[$col2])) {
                $documents[$col2] = ['date' => $date, 'desc' => $col3, 'lines' => []];
            }

            // Gộp theo account_code trong cùng một chứng từ
            foreach ([$col5, $col6] as $acc) {
                if (!$acc) continue;
                if (!isset($documents[$col2]['lines'][$acc])) {
                    $documents[$col2]['lines'][$acc] = ['debit' => 0, 'credit' => 0];
                }
            }
            // Bên chính: Nợ/Có theo file
            $documents[$col2]['lines'][$col5]['debit']  += $col7;
            $documents[$col2]['lines'][$col5]['credit'] += $col9;
            // Bên đối ứng: ngược lại
            $documents[$col2]['lines'][$col6]['debit']  += $col9;
            $documents[$col2]['lines'][$col6]['credit'] += $col7;
        }

        if (!$accountCode) {
            throw new \RuntimeException('Không tìm thấy mã tài khoản. Đảm bảo file là sổ chi tiết MISA xuất ra.');
        }
        if (empty($documents)) {
            throw new \RuntimeException("Không tìm thấy giao dịch nào trong file.");
        }

        $imported = 0;
        $updated  = 0;
        $lineCount = 0;

        DB::transaction(function () use ($documents, &$imported, &$updated, &$lineCount) {
            foreach ($documents as $docNumber => $doc) {
                $exists = JournalEntry::where('code', $docNumber)
                    ->where('reference_type', 'misa_historical')
                    ->exists();

                $entry = JournalEntry::updateOrCreate(
                    ['code' => $docNumber, 'reference_type' => 'misa_historical'],
                    [
                        'entry_date'  => $doc['date'],
                        'description' => mb_substr($doc['desc'], 0, 500),
                        'status'      => 'posted',
                        'is_auto'     => true,
                        'created_by'  => auth()->id(),
                        'posted_at'   => now(),
                    ]
                );

                foreach ($doc['lines'] as $accCode => $amounts) {
                    if ($amounts['debit'] == 0 && $amounts['credit'] == 0) continue;
                    $entry->lines()->updateOrCreate(
                        ['account_code' => $accCode],
                        [
                            'debit'       => (int) round($amounts['debit']),
                            'credit'      => (int) round($amounts['credit']),
                            'description' => mb_substr($doc['desc'], 0, 500),
                            'sort_order'  => 0,
                        ]
                    );
                    $lineCount++;
                }

                $exists ? $updated++ : $imported++;
            }
        });

        return [
            'account_code' => $accountCode,
            'imported'     => $imported,
            'updated'      => $updated,
            'lines'        => $lineCount,
        ];
    }
}
