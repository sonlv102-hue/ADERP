<?php

namespace Tests\Feature\Accounting;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\InternalBankAccount;
use App\Models\User;
use App\Services\BankStatementImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Regression: BankStatementImportService::confirmBatch() từng làm mất internal_account_id
 * (chỉ raw_data_json['tx_type'] được lưu qua bước processFile → confirmBatch, id bị bỏ rơi).
 * Hệ quả thực tế trên production: giao dịch internal_transfer nhập qua import Excel hàng loạt
 * luôn có internal_account_id = NULL, nên filter internal_account_ids[] trên báo cáo
 * /accounting/internal-transfers luôn trả về rỗng cho các giao dịch này (2026-08-22).
 */
class BankStatementImportInternalTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirm_batch_sets_internal_account_id_for_internal_transfer(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Gate::before(fn ($u, $a) => true);
        $this->actingAs($user);

        $bankAccount = BankAccount::create([
            'name' => 'TK Công ty', 'bank_name' => 'VCB', 'account_number' => '999888777',
            'account_code' => '1121', 'currency' => 'VND', 'is_active' => true,
        ]);
        $internal = InternalBankAccount::create([
            'name' => 'Giám đốc', 'account_number' => '108605111186', 'bank_name' => 'VCB', 'is_active' => true,
        ]);

        $csv = "Ngày giao dịch,Diễn giải,Tài khoản đối tác,Ghi nợ,Ghi có\n"
             . "15/08/2026,Chuyen tien noi bo,108605111186,0,50000000\n";
        $tmp = tempnam(sys_get_temp_dir(), 'bank_import_') . '.csv';
        file_put_contents($tmp, $csv);
        $file = new UploadedFile($tmp, 'statement.csv', 'text/csv', null, true);

        $service = app(BankStatementImportService::class);
        $batch = $service->uploadAndParse($bankAccount, [$file], $user->id);

        $result = $service->confirmBatch($batch, $user->id);
        $this->assertEquals(1, $result['imported']);

        $tx = BankTransaction::where('tx_type', 'internal_transfer')->firstOrFail();
        $this->assertEquals($internal->id, $tx->internal_account_id,
            'Giao dịch internal_transfer import qua Excel phải có internal_account_id, nếu không filter báo cáo sẽ luôn rỗng.');
    }
}
