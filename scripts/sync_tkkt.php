<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AccountCode;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

$file = 'Danh_muc_TKKT.xlsx';
if (!file_exists($file)) {
    $file = '/var/www/html/Danh_muc_TKKT.xlsx';
}
if (!file_exists($file)) {
    $file = 'C:/Mini_erp/Danh mục TKKT.xlsx';
}
echo "=== Bắt đầu đồng bộ danh mục tài khoản từ Excel ===\n";
echo "Đọc file: $file\n";

try {
    $rows = Excel::toArray([], $file)[0] ?? [];
    $totalRows = count($rows);
    echo "Tổng số dòng trong file: $totalRows\n";

    if ($totalRows <= 1) {
        throw new \Exception("File không chứa dữ liệu hoặc chỉ có header.");
    }

    DB::transaction(function () use ($rows) {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        // Pass 1: Upsert các tài khoản từ file Excel
        // Bắt đầu từ dòng 1 (bỏ qua header dòng 0)
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $code = trim((string)($row[0] ?? ''));
            $name = trim((string)($row[1] ?? ''));

            if (!$code || !$name) {
                $skipped++;
                continue;
            }

            // Tìm tài khoản hiện tại trong DB
            $account = AccountCode::where('code', $code)->first();

            if ($account) {
                // Đã tồn tại -> Cập nhật tên
                if ($account->name !== $name) {
                    $account->update(['name' => $name]);
                    $updated++;
                }
            } else {
                // Tạo mới -> Xác định các thuộc tính dựa trên prefix dài nhất hiện có
                $parentCode = null;
                $parent = null;

                // Tìm parent bằng cách cắt ngắn code từ phải qua trái
                for ($len = strlen($code) - 1; $len > 0; $len--) {
                    $prefix = substr($code, 0, $len);
                    $parent = AccountCode::where('code', $prefix)->first();
                    if ($parent) {
                        $parentCode = $prefix;
                        break;
                    }
                }

                // Thiết lập các giá trị mặc định dựa trên parent
                if ($parent) {
                    $type = $parent->type;
                    $balance = $parent->normal_balance;
                    $level = $parent->level + 1;
                } else {
                    // Fallback theo ký tự đầu tiên nếu không có parent
                    $firstChar = substr($code, 0, 1);
                    $type = match ($firstChar) {
                        '1', '2' => 'asset',
                        '3' => 'liability',
                        '4' => 'equity',
                        '5', '7' => 'revenue',
                        '6', '8' => 'expense',
                        default => 'equity',
                    };
                    $balance = in_array($type, ['asset', 'expense']) ? 'debit' : 'credit';
                    $level = 1;
                }

                AccountCode::create([
                    'code' => $code,
                    'name' => $name,
                    'type' => $type,
                    'normal_balance' => $balance,
                    'parent_code' => $parentCode,
                    'level' => $level,
                    'is_detail' => true, // Tạm thời để true, sẽ cập nhật lại ở Pass 2
                    'is_active' => true,
                ]);
                $created++;
            }
        }

        echo "Pass 1 hoàn thành: Đã tạo mới $created TK, cập nhật $updated TK, bỏ qua $skipped dòng trống.\n";

        // Pass 2: Cập nhật lại thuộc tính is_detail và parent_code cho toàn bộ tài khoản
        echo "Bắt đầu cập nhật cấu trúc phân cấp (parent_code & is_detail)...\n";
        
        $allAccounts = AccountCode::all();
        $allCodes = $allAccounts->pluck('code')->toArray();
        $parentTracker = [];

        foreach ($allAccounts as $acc) {
            $code = $acc->code;
            
            // Tìm parent_code tối ưu nhất
            $parentCode = null;
            for ($len = strlen($code) - 1; $len > 0; $len--) {
                $prefix = substr($code, 0, $len);
                if (in_array($prefix, $allCodes)) {
                    $parentCode = $prefix;
                    break;
                }
            }

            $acc->parent_code = $parentCode;
            
            // Tính toán level
            if ($parentCode) {
                $parentTracker[$code] = $parentCode;
                // Tính level đệ quy
                $level = 1;
                $temp = $parentCode;
                while ($temp && in_array($temp, $allCodes)) {
                    $level++;
                    // Tìm parent của parent
                    $tempParent = null;
                    for ($len = strlen($temp) - 1; $len > 0; $len--) {
                        $p = substr($temp, 0, $len);
                        if (in_array($p, $allCodes)) {
                            $tempParent = $p;
                            break;
                        }
                    }
                    $temp = $tempParent;
                }
                $acc->level = $level;
            } else {
                $acc->level = 1;
            }

            $acc->save();
        }

        // Cập nhật is_detail
        $parentCodesInUse = AccountCode::whereNotNull('parent_code')->distinct()->pluck('parent_code')->toArray();
        
        $detailCount = 0;
        $groupCount = 0;
        foreach (AccountCode::all() as $acc) {
            $isDetail = !in_array($acc->code, $parentCodesInUse);
            $acc->update(['is_detail' => $isDetail]);
            
            if ($isDetail) {
                $detailCount++;
            } else {
                $groupCount++;
            }
        }

        echo "Pass 2 hoàn thành: Cập nhật thành công cấu trúc phân cấp.\n";
        echo "Tổng kết DB: $groupCount tài khoản tổng hợp (nhóm), $detailCount tài khoản chi tiết.\n";
    });

    echo "=== ĐỒNG BỘ THÀNH CÔNG ===\n";

} catch (\Throwable $e) {
    echo "Lỗi đồng bộ: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
