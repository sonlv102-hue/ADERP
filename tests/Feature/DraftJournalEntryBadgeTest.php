<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Cảnh báo bút toán nháp chờ duyệt.
 * draftJournalEntryCount là shared prop trả về từ HandleInertiaRequests.
 */
class DraftJournalEntryBadgeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        Permission::firstOrCreate(['name' => 'accounting.view',   'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'accounting.manage', 'guard_name' => 'web']);
        $this->user->givePermissionTo(['accounting.view', 'accounting.manage']);
    }

    private function makeDraftJe(): JournalEntry
    {
        return JournalEntry::create([
            'code'        => JournalEntry::generateCode(),
            'entry_date'  => '2026-06-01',
            'description' => 'Test draft JE',
            'status'      => 'draft',
            'is_auto'     => false,
            'created_by'  => $this->user->id,
        ]);
    }

    /** Draft JEs được đếm đúng trong shared prop. */
    public function test_draft_jes_appear_in_shared_prop_count(): void
    {
        $this->makeDraftJe();
        $this->makeDraftJe();

        $this->get(route('accounting.journal-entries.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('draftJournalEntryCount', 2)
            );
    }

    /** JE đã posted không được tính vào count. */
    public function test_posted_jes_are_not_counted(): void
    {
        JournalEntry::create([
            'code'        => JournalEntry::generateCode(),
            'entry_date'  => '2026-06-01',
            'description' => 'Posted JE',
            'status'      => 'posted',
            'is_auto'     => false,
            'created_by'  => $this->user->id,
            'posted_at'   => now(),
        ]);
        $this->makeDraftJe();

        $this->get(route('accounting.journal-entries.index'))
            ->assertInertia(fn ($page) => $page->where('draftJournalEntryCount', 1));
    }

    /** User không có quyền accounting.view nhận count = 0. */
    public function test_user_without_accounting_view_sees_zero(): void
    {
        $this->makeDraftJe();

        $noPermsUser = User::factory()->create();
        $this->actingAs($noPermsUser);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('draftJournalEntryCount', 0));
    }

    /** Sau khi duyệt JE, count giảm về đúng giá trị. */
    public function test_count_decreases_after_je_is_posted(): void
    {
        $je = $this->makeDraftJe();

        $this->get(route('accounting.journal-entries.index'))
            ->assertInertia(fn ($page) => $page->where('draftJournalEntryCount', 1));

        $this->post(route('accounting.journal-entries.post', $je))
            ->assertRedirect();

        $this->get(route('accounting.journal-entries.index'))
            ->assertInertia(fn ($page) => $page->where('draftJournalEntryCount', 0));
    }
}
