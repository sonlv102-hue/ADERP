<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\User;
use App\Services\Accounting\PeriodCloseService;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PeriodCloseTest extends TestCase
{
    use RefreshDatabase;

    private PeriodCloseService $service;
    private AccountingService $accounting;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($user);
        Gate::before(fn ($user, $ability) => true);

        $this->accounting = app(AccountingService::class);
        $this->service    = app(PeriodCloseService::class);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

        $accounts = [
            ['511',  'Doanh thu bán hàng',          'revenue', 'credit', null,  false],
            ['5111', 'Doanh thu bán hàng hóa',       'revenue', 'credit', '511', true],
            ['632',  'Giá vốn hàng bán',             'expense', 'debit',  null,  false],
            ['6321', 'Giá vốn hàng bán',             'expense', 'debit',  '632', true],
            ['642',  'Chi phí QLDN',                 'expense', 'debit',  null,  false],
            ['6422', 'Chi phí QLDN khác',             'expense', 'debit',  '642', true],
            ['91',   'TK trung gian',                 'equity',  'credit', null,  false],
            ['911',  'Xác định kết quả KD',           'equity',  'credit', '91',  true],
            ['421',  'LNST chưa phân phối',           'equity',  'credit', null,  false],
            ['4212', 'LNST chưa phân phối năm nay',  'equity',  'credit', '421', true],
            ['111',  'Tiền mặt',                      'asset',   'debit',  null,  false],
            ['1111', 'Tiền mặt tại quỹ',              'asset',   'debit',  '111', true],
        ];
        foreach ($accounts as [$code, $name, $type, $nb, $parent, $detail]) {
            AccountCode::updateOrCreate(['code' => $code], [
                'name' => $name, 'type' => $type, 'normal_balance' => $nb,
                'parent_code' => $parent, 'level' => $parent ? 4 : 3,
                'is_detail' => $detail, 'is_active' => true,
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC1: Chỉ có doanh thu → 2 JE (kết chuyển DT + lợi nhuận)
    // ─────────────────────────────────────────────────────────────────────────
    public function test_revenue_only_creates_two_closing_entries(): void
    {
        $this->accounting->post('Bán hàng tháng 6', now()->setMonth(6)->setDay(15), [
            ['account' => '5111', 'debit' => 0,          'credit' => 10_000_000, 'description' => 'DT'],
            ['account' => '1111', 'debit' => 10_000_000, 'credit' => 0,          'description' => 'TM'],
        ]);

        $jes = $this->service->close('2026-06');

        $this->assertCount(2, $jes, 'Phải có 2 JE: kết chuyển DT và lợi nhuận');

        $revenueJe = collect($jes)->first(fn ($j) => str_contains($j->description, 'doanh thu'));
        $this->assertNotNull($revenueJe);

        $dr5111 = $revenueJe->lines->where('account_code', '5111')->sum('debit');
        $cr911  = $revenueJe->lines->where('account_code', '911')->sum('credit');
        $this->assertEquals(10_000_000, $dr5111);
        $this->assertEquals(10_000_000, $cr911);

        $profitJe = collect($jes)->first(fn ($j) => str_contains($j->description, 'lợi nhuận'));
        $this->assertNotNull($profitJe);
        $this->assertEquals(10_000_000, $profitJe->lines->where('account_code', '911')->sum('debit'));
        $this->assertEquals(10_000_000, $profitJe->lines->where('account_code', '4212')->sum('credit'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC2: DT và CP → 3 JE; lợi nhuận đúng
    // ─────────────────────────────────────────────────────────────────────────
    public function test_revenue_and_expense_creates_three_entries(): void
    {
        $this->accounting->post('DT tháng 6', now()->setMonth(6)->setDay(15), [
            ['account' => '5111', 'debit' => 0,          'credit' => 20_000_000, 'description' => 'DT'],
            ['account' => '1111', 'debit' => 20_000_000, 'credit' => 0,          'description' => 'TM'],
        ]);

        $this->accounting->post('CP tháng 6', now()->setMonth(6)->setDay(15), [
            ['account' => '6422', 'debit' => 8_000_000,  'credit' => 0,          'description' => 'CP'],
            ['account' => '1111', 'debit' => 0,          'credit' => 8_000_000,  'description' => 'TM'],
        ]);

        $jes = $this->service->close('2026-06');

        $this->assertCount(3, $jes, 'Phải có 3 JE: DT, CP, lợi nhuận');

        $profitJe = collect($jes)->last();
        $this->assertEquals(12_000_000, $profitJe->lines->where('account_code', '4212')->sum('credit'), 'LN = 20M - 8M = 12M');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC3: Idempotent — gọi lần 2 phải throw "đã được kết chuyển"
    // ─────────────────────────────────────────────────────────────────────────
    public function test_idempotent_second_close_throws(): void
    {
        $this->accounting->post('DT', now()->setMonth(6)->setDay(15), [
            ['account' => '5111', 'debit' => 0,         'credit' => 5_000_000, 'description' => 'DT'],
            ['account' => '1111', 'debit' => 5_000_000, 'credit' => 0,         'description' => 'TM'],
        ]);

        $this->service->close('2026-06');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/đã được kết chuyển/');
        $this->service->close('2026-06');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC4: Kỳ không tồn tại → throw
    // ─────────────────────────────────────────────────────────────────────────
    public function test_nonexistent_period_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->close('2025-01');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC5: Lỗ (CP > DT) → Dr 4212 / Cr 911
    // ─────────────────────────────────────────────────────────────────────────
    public function test_loss_creates_dr_4212_cr_911(): void
    {
        $this->accounting->post('CP > DT', now()->setMonth(6)->setDay(15), [
            ['account' => '6422', 'debit' => 5_000_000, 'credit' => 0,         'description' => 'CP'],
            ['account' => '1111', 'debit' => 0,         'credit' => 5_000_000, 'description' => 'TM'],
        ]);

        $jes = $this->service->close('2026-06');

        $lossJe = collect($jes)->last();
        $this->assertEquals(5_000_000, $lossJe->lines->where('account_code', '4212')->sum('debit'), 'Dr 4212 khi lỗ');
        $this->assertEquals(5_000_000, $lossJe->lines->where('account_code', '911')->sum('credit'), 'Cr 911 khi lỗ');
    }
}
