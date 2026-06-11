<?php

namespace Tests\Feature;

use App\Enums\AccountingPostingStatus;
use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\AccountingPostingJob;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingPostingJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private AccountingService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->svc = app(AccountingService::class);

        // Tạo account codes cần thiết (dùng firstOrCreate để idempotent)
        // Chú ý: migration 2026_06_06_900045 đã seed '131' (is_detail=true) và '5111' (is_detail=true)
        // Phải dùng TK chi tiết (is_detail=true) vì validateLines từ chối TK tổng hợp
        foreach ([
            ['code' => '131',  'name' => 'Phải thu KH',         'type' => 'asset',   'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '5111', 'name' => 'Doanh thu bán hàng',  'type' => 'revenue', 'normal_balance' => 'credit', 'is_detail' => true],
            ['code' => '1111', 'name' => 'Tiền mặt VND',        'type' => 'asset',   'normal_balance' => 'debit',  'is_detail' => true],
        ] as $ac) {
            AccountCode::firstOrCreate(['code' => $ac['code']], $ac);
        }

        // Kỳ kế toán mở cho tháng test
        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);
    }

    private function sampleLines(): array
    {
        return [
            ['account' => '131',  'debit' => 1000000, 'credit' => 0,       'description' => 'Dr 131'],
            ['account' => '5111', 'debit' => 0,       'credit' => 1000000, 'description' => 'Cr 5111'],
        ];
    }

    /** AC1: tryPost thành công → tạo job với status=posted */
    public function test_tryPost_records_success_as_posted(): void
    {
        $je = $this->svc->tryPost(
            'Test doanh thu',
            Carbon::parse('2026-06-01'),
            $this->sampleLines(),
            'invoice', 1, 'revenue'
        );

        $this->assertNotNull($je);

        $job = AccountingPostingJob::where('source_type', 'invoice')
            ->where('source_id', 1)
            ->where('posting_type', 'revenue')
            ->first();

        $this->assertNotNull($job);
        $this->assertEquals(AccountingPostingStatus::Posted, $job->status);
        $this->assertEquals($je->id, $job->journal_entry_id);
        $this->assertNull($job->error_code);
        $this->assertNotNull($job->posted_at);
        $this->assertEquals(1, $job->attempts);
    }

    /** AC2: tryPost fail vì kỳ đóng → ghi job với status=failed, error_code=PERIOD_CLOSED */
    public function test_tryPost_records_period_closed_failure(): void
    {
        AccountingPeriod::create(['year' => 2026, 'month' => 5, 'status' => 'closed']);

        $result = $this->svc->tryPost(
            'Test doanh thu kỳ đóng',
            Carbon::parse('2026-05-15'),
            $this->sampleLines(),
            'invoice', 2, 'revenue'
        );

        $this->assertNull($result, 'tryPost phải trả về null khi kỳ đóng');

        $job = AccountingPostingJob::where('source_type', 'invoice')
            ->where('source_id', 2)
            ->where('posting_type', 'revenue')
            ->first();

        $this->assertNotNull($job);
        $this->assertEquals(AccountingPostingStatus::Failed, $job->status);
        $this->assertEquals('PERIOD_CLOSED', $job->error_code);
        $this->assertNotNull($job->error_message);
        $this->assertEquals(1, $job->attempts);
    }

    /** AC3: tryPost idempotent — gọi lại sau khi đã posted không tạo bút toán thứ 2 */
    public function test_tryPost_is_idempotent_when_already_posted(): void
    {
        $je1 = $this->svc->tryPost(
            'Test doanh thu',
            Carbon::parse('2026-06-01'),
            $this->sampleLines(),
            'invoice', 3, 'revenue'
        );

        $je2 = $this->svc->tryPost(
            'Test doanh thu',
            Carbon::parse('2026-06-01'),
            $this->sampleLines(),
            'invoice', 3, 'revenue'
        );

        $this->assertEquals($je1->id, $je2->id, 'Phải trả về cùng JE, không tạo bút toán mới');
        $this->assertEquals(1, JournalEntry::where('reference_type', 'invoice')->where('reference_id', 3)->count());

        $job = AccountingPostingJob::where('source_type', 'invoice')->where('source_id', 3)->first();
        $this->assertEquals(1, $job->attempts, 'attempts không tăng khi đã posted');
    }

    /** AC4: retry thành công sau khi mở lại kỳ */
    public function test_retry_succeeds_after_period_reopened(): void
    {
        AccountingPeriod::create(['year' => 2026, 'month' => 4, 'status' => 'closed']);

        // Lần 1: fail vì kỳ đóng
        $this->svc->tryPost(
            'Test stock entry',
            Carbon::parse('2026-04-10'),
            $this->sampleLines(),
            'stock_entry', 10, 'inbound'
        );

        $job = AccountingPostingJob::where('source_type', 'stock_entry')->where('source_id', 10)->first();
        $this->assertEquals(AccountingPostingStatus::Failed, $job->status);

        // Mở lại kỳ
        AccountingPeriod::where('year', 2026)->where('month', 4)->update(['status' => 'open']);

        // Retry → thành công
        $je = $this->svc->retryJob($job);

        $this->assertNotNull($je);
        $job->refresh();
        $this->assertEquals(AccountingPostingStatus::Posted, $job->status);
        $this->assertEquals($je->id, $job->journal_entry_id);
        $this->assertEquals(2, $job->attempts);
    }

    /** AC5: retry không tạo trùng bút toán nếu job đã posted */
    public function test_retry_does_not_create_duplicate_when_already_posted(): void
    {
        $je1 = $this->svc->tryPost(
            'Test', Carbon::parse('2026-06-05'),
            $this->sampleLines(),
            'cash_voucher', 5, 'confirm'
        );

        $job = AccountingPostingJob::where('source_type', 'cash_voucher')->where('source_id', 5)->first();
        $this->assertEquals(AccountingPostingStatus::Posted, $job->status);

        $je2 = $this->svc->retryJob($job);

        $this->assertEquals($je1->id, $je2->id, 'retryJob phải trả về JE cũ, không tạo mới');
        $this->assertEquals(1, JournalEntry::where('reference_type', 'cash_voucher')->where('reference_id', 5)->count());
    }

    /** AC6: retry vẫn fail nếu kỳ chưa mở → throw exception, job status=failed, attempts tăng */
    public function test_retry_still_fails_if_period_still_closed(): void
    {
        AccountingPeriod::create(['year' => 2026, 'month' => 3, 'status' => 'closed']);

        $this->svc->tryPost(
            'Test',
            Carbon::parse('2026-03-01'),
            $this->sampleLines(),
            'payroll', 20, 'salary'
        );

        $job = AccountingPostingJob::where('source_type', 'payroll')->where('source_id', 20)->first();
        $this->assertEquals(AccountingPostingStatus::Failed, $job->status);

        $this->expectException(\Throwable::class);

        try {
            $this->svc->retryJob($job);
        } finally {
            $job->refresh();
            $this->assertEquals(AccountingPostingStatus::Failed, $job->status);
            $this->assertEquals('PERIOD_CLOSED', $job->error_code);
            $this->assertEquals(2, $job->attempts);
        }
    }

    /** UI/API: endpoint retry yêu cầu permission accounting.manage */
    public function test_retry_endpoint_requires_accounting_manage_permission(): void
    {
        AccountingPeriod::create(['year' => 2026, 'month' => 2, 'status' => 'closed']);
        $this->svc->tryPost('Test', Carbon::parse('2026-02-01'), $this->sampleLines(), 'invoice', 99, 'revenue');
        $job = AccountingPostingJob::where('source_type', 'invoice')->where('source_id', 99)->first();

        // User không có permission → forbidden
        $response = $this->post(route('accounting.posting-jobs.retry', $job->id));
        $response->assertForbidden();
    }
}
