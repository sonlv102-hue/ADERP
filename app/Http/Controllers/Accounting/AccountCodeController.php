<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccountCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class AccountCodeController extends Controller
{
    public function index(): Response
    {
        $accounts = AccountCode::orderBy('code')->get()
            ->map(fn ($a) => [
                'code'           => $a->code,
                'name'           => $a->name,
                'type'           => $a->type,
                'type_label'     => $a->typeLabel(),
                'normal_balance' => $a->normal_balance,
                'parent_code'    => $a->parent_code,
                'level'          => $a->level,
                'is_detail'      => $a->is_detail,
                'is_active'      => $a->is_active,
            ]);

        return Inertia::render('Accounting/AccountCodes/Index', [
            'accounts' => $accounts,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'           => ['required', 'string', 'max:10', 'unique:account_codes,code'],
            'name'           => ['required', 'string', 'max:200'],
            'type'           => ['required', 'in:asset,liability,equity,revenue,expense,contra'],
            'normal_balance' => ['required', 'in:debit,credit'],
            'parent_code'    => ['nullable', 'exists:account_codes,code'],
            'is_detail'      => ['boolean'],
            'notes'          => ['nullable', 'string', 'max:500'],
        ]);

        $level = 1;
        if ($data['parent_code'] ?? null) {
            $parent = AccountCode::find($data['parent_code']);
            $level  = $parent ? $parent->level + 1 : 1;
        }

        AccountCode::create([...$data, 'level' => $level]);

        return back()->with('success', 'Đã thêm tài khoản kế toán.');
    }

    public function update(Request $request, AccountCode $accountCode): RedirectResponse
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:200'],
            'is_detail' => ['boolean'],
            'is_active' => ['boolean'],
            'notes'     => ['nullable', 'string', 'max:500'],
        ]);

        $accountCode->update($data);

        return back()->with('success', 'Đã cập nhật tài khoản.');
    }

    public function destroy(AccountCode $accountCode): RedirectResponse
    {
        if ($accountCode->journalLines()->exists()) {
            return back()->with('error', 'Không thể xóa tài khoản đã có bút toán.');
        }
        if ($accountCode->children()->exists()) {
            return back()->with('error', 'Không thể xóa tài khoản đã có tài khoản con.');
        }

        $accountCode->delete();

        return back()->with('success', 'Đã xóa tài khoản.');
    }

    public function downloadSample(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Danh mục tài khoản');

        // Header row
        $headers = ['Mã TK', 'Tên tài khoản', 'Loại', 'Dư bình thường', 'TK cha', 'Tài khoản chi tiết', 'Ghi chú'];
        foreach ($headers as $i => $h) {
            $col = chr(65 + $i);
            $sheet->setCellValue($col . '1', $h);
        }
        $sheet->getStyle('A1:G1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1D4ED8']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // Instruction row
        $instructions = [
            'Bắt buộc', 'Bắt buộc',
            'asset | liability | equity | revenue | expense | contra',
            'debit | credit',
            'Mã TK cha (để trống nếu không có)',
            '1 = chi tiết / 0 = nhóm',
            'Tùy chọn',
        ];
        foreach ($instructions as $i => $v) {
            $sheet->setCellValue(chr(65 + $i) . '2', $v);
        }
        $sheet->getStyle('A2:G2')->applyFromArray([
            'font' => ['italic' => true, 'color' => ['argb' => 'FF6B7280']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF9FAFB']],
        ]);

        // Sample data
        $data = [
            ['111',  'Tiền mặt',                         'asset',     'debit',  '',    '0', ''],
            ['1111', 'Tiền Việt Nam',                     'asset',     'debit',  '111', '1', ''],
            ['1112', 'Ngoại tệ',                          'asset',     'debit',  '111', '1', ''],
            ['112',  'Tiền gửi ngân hàng',                'asset',     'debit',  '',    '0', ''],
            ['1121', 'Tiền gửi Việt Nam đồng',            'asset',     'debit',  '112', '1', ''],
            ['131',  'Phải thu của khách hàng',           'asset',     'debit',  '',    '0', ''],
            ['1311', 'Phải thu KH trong nước',            'asset',     'debit',  '131', '1', ''],
            ['156',  'Hàng hóa',                          'asset',     'debit',  '',    '0', ''],
            ['1561', 'Giá mua hàng hóa',                  'asset',     'debit',  '156', '1', ''],
            ['331',  'Phải trả cho người bán',            'liability', 'credit', '',    '0', ''],
            ['3311', 'Phải trả NCC trong nước',           'liability', 'credit', '331', '1', ''],
            ['411',  'Vốn đầu tư của chủ sở hữu',        'equity',    'credit', '',    '0', ''],
            ['4111', 'Vốn góp của chủ sở hữu',           'equity',    'credit', '411', '1', ''],
            ['511',  'Doanh thu bán hàng và cung cấp DV', 'revenue',   'credit', '',    '0', ''],
            ['5111', 'Doanh thu bán hàng hóa',            'revenue',   'credit', '511', '1', ''],
            ['641',  'Chi phí bán hàng',                  'expense',   'debit',  '',    '0', ''],
            ['6411', 'Chi phí nhân viên bán hàng',        'expense',   'debit',  '641', '1', ''],
        ];

        foreach ($data as $row => $values) {
            foreach ($values as $col => $val) {
                $sheet->setCellValue(chr(65 + $col) . ($row + 3), $val);
            }
        }

        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $tmpPath = storage_path('app/temp/mau_danh_muc_tai_khoan.xlsx');
        File::ensureDirectoryExists(dirname($tmpPath));

        (new XlsxWriter($spreadsheet))->save($tmpPath);

        return response()->download($tmpPath, 'mau_danh_muc_tai_khoan.xlsx')->deleteFileAfterSend(true);
    }

    public function importExcel(Request $request): RedirectResponse
    {
        $request->validate(['excel_file' => ['required', 'file', 'max:10240']]);

        try {
            $rows = Excel::toArray([], $request->file('excel_file'))[0] ?? [];
        } catch (\Throwable $e) {
            return back()->withErrors(['excel_file' => 'Không đọc được file: ' . $e->getMessage()]);
        }

        if (count($rows) < 2) {
            return back()->withErrors(['excel_file' => 'File không có dữ liệu.']);
        }

        // Bỏ qua header và dòng hướng dẫn (nếu có)
        $startRow = 1;
        $secondCell = strtolower(trim((string)($rows[1][0] ?? '')));
        if (str_contains($secondCell, 'bắt buộc') || str_contains($secondCell, 'bat buoc')) {
            $startRow = 2;
        }

        $typeMap = [
            'asset'     => 'asset',     'tài sản'        => 'asset',     'tai san'        => 'asset',
            'liability' => 'liability', 'nợ phải trả'    => 'liability', 'no phai tra'    => 'liability',
            'equity'    => 'equity',    'vốn chủ sở hữu' => 'equity',    'von chu so huu' => 'equity',
            'revenue'   => 'revenue',   'doanh thu'      => 'revenue',
            'expense'   => 'expense',   'chi phí'        => 'expense',   'chi phi'        => 'expense',
            'contra'    => 'contra',    'điều chỉnh'     => 'contra',    'dieu chinh'     => 'contra',
        ];
        $balanceMap = [
            'debit'  => 'debit',  'nợ' => 'debit',  'no' => 'debit',  'd' => 'debit',
            'credit' => 'credit', 'có' => 'credit', 'co' => 'credit', 'c' => 'credit',
        ];

        $created = 0; $updated = 0; $skipped = [];

        DB::transaction(function () use ($rows, $startRow, $typeMap, $balanceMap, &$created, &$updated, &$skipped) {
            for ($i = $startRow; $i < count($rows); $i++) {
                $row  = $rows[$i];
                $code = trim((string)($row[0] ?? ''));
                $name = trim((string)($row[1] ?? ''));
                if (!$code || !$name) continue;

                $typeRaw    = strtolower(trim((string)($row[2] ?? '')));
                $balanceRaw = strtolower(trim((string)($row[3] ?? '')));
                $parentCode = trim((string)($row[4] ?? '')) ?: null;
                $isDetail   = in_array(trim((string)($row[5] ?? '')), ['1', 'x', 'có', 'co', 'yes', 'true'], true);
                $notes      = trim((string)($row[6] ?? '')) ?: null;

                $type    = $typeMap[$typeRaw]       ?? null;
                $balance = $balanceMap[$balanceRaw] ?? null;

                if (!$type) {
                    $skipped[] = "Dòng " . ($i + 1) . " (TK {$code}): loại '{$typeRaw}' không hợp lệ";
                    continue;
                }
                if (!$balance) {
                    $balance = in_array($type, ['asset', 'expense']) ? 'debit' : 'credit';
                }

                $level = 1;
                if ($parentCode) {
                    $parent = AccountCode::find($parentCode);
                    $level  = $parent ? $parent->level + 1 : 2;
                }

                $exists = AccountCode::where('code', $code)->exists();
                AccountCode::updateOrCreate(
                    ['code' => $code],
                    [
                        'name'           => $name,
                        'type'           => $type,
                        'normal_balance' => $balance,
                        'parent_code'    => $parentCode,
                        'level'          => $level,
                        'is_detail'      => $isDetail,
                        'notes'          => $notes,
                        'is_active'      => true,
                    ]
                );
                $exists ? $updated++ : $created++;
            }
        });

        $msg = "Import hoàn tất: tạo mới {$created}, cập nhật {$updated} tài khoản.";
        if (!empty($skipped)) {
            $msg .= ' Bỏ qua: ' . implode('; ', array_slice($skipped, 0, 5));
        }

        return back()->with('success', $msg);
    }
}
