<?php

namespace App\Exports;

use App\Enums\JournalEntryStatus;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;

class JournalEntriesExport extends BaseListExport
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Danh sách bút toán'; }
    protected function reportTitle(): string { return 'DANH SÁCH BÚT TOÁN'; }

    protected function filterDescription(): string
    {
        $parts = [];
        if ($q  = $this->filters['search'] ?? null) $parts[] = "Tìm kiếm: {$q}";
        if ($s  = $this->filters['status'] ?? null) {
            $label = JournalEntryStatus::tryFrom($s)?->label() ?? $s;
            $parts[] = "Trạng thái: {$label}";
        }
        if ($f  = $this->filters['from'] ?? null) $parts[] = "Từ: {$f}";
        if ($t  = $this->filters['to']   ?? null) $parts[] = "Đến: {$t}";
        return $parts ? implode('   |   ', $parts) : 'Tất cả bút toán';
    }

    protected function columns(): array
    {
        return [
            ['key' => '__stt',       'header' => 'STT',          'width' => 5,  'type' => 'number'],
            ['key' => 'code',        'header' => 'Mã BT',        'width' => 14, 'type' => 'text'],
            ['key' => 'entry_date',  'header' => 'Ngày HT',      'width' => 12, 'type' => 'date'],
            ['key' => 'description', 'header' => 'Diễn giải',    'width' => 40, 'type' => 'text'],
            ['key' => 'debit_tk',    'header' => 'TK Nợ',        'width' => 20, 'type' => 'text'],
            ['key' => 'credit_tk',   'header' => 'TK Có',        'width' => 20, 'type' => 'text'],
            ['key' => 'total_debit', 'header' => 'Tổng Nợ (₫)', 'width' => 18, 'type' => 'money'],
            ['key' => 'total_credit','header' => 'Tổng Có (₫)', 'width' => 18, 'type' => 'money'],
            ['key' => 'status',      'header' => 'Trạng thái',   'width' => 14, 'type' => 'text'],
            ['key' => 'creator',     'header' => 'Người lập',    'width' => 20, 'type' => 'text'],
        ];
    }

    protected function buildRows(): array
    {
        $search = $this->filters['search'] ?? null;
        $status = $this->filters['status'] ?? null;
        $from   = $this->filters['from']   ?? null;
        $to     = $this->filters['to']     ?? null;

        $entries = JournalEntry::with(['creator'])
            ->when($search, fn ($q) => $q->where(function ($sq) use ($search) {
                $sq->where('code', 'ilike', "%{$search}%")
                   ->orWhere('description', 'ilike', "%{$search}%");
            }))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($from,   fn ($q) => $q->where('entry_date', '>=', $from))
            ->when($to,     fn ($q) => $q->where('entry_date', '<=', $to))
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->get();

        if ($entries->isEmpty()) {
            return [];
        }

        // Load lines in bulk to avoid N+1
        $lineMap = DB::table('journal_entry_lines')
            ->whereIn('journal_entry_id', $entries->pluck('id'))
            ->select('journal_entry_id', 'account_code', 'debit', 'credit')
            ->get()
            ->groupBy('journal_entry_id');

        return $entries->map(function ($je) use ($lineMap) {
            $lines      = $lineMap->get($je->id, collect());
            $debitTk    = $lines->where('debit', '>', 0)->pluck('account_code')->unique()->join(' / ');
            $creditTk   = $lines->where('credit', '>', 0)->pluck('account_code')->unique()->join(' / ');
            $totalDebit = (float) $lines->sum('debit');
            return [
                'code'         => $je->code,
                'entry_date'   => $je->entry_date?->format('d/m/Y') ?? '',
                'description'  => $je->description ?? '',
                'debit_tk'     => $debitTk ?: '—',
                'credit_tk'    => $creditTk ?: '—',
                'total_debit'  => $totalDebit,
                'total_credit' => $totalDebit,
                'status'       => $je->status->label(),
                'creator'      => $je->creator->name,
            ];
        })->toArray();
    }

    protected function buildTotals(): array
    {
        $rows = $this->buildRows();
        return [
            'code'         => 'Tổng cộng (' . count($rows) . ' bút toán)',
            'total_debit'  => array_sum(array_column($rows, 'total_debit')),
            'total_credit' => array_sum(array_column($rows, 'total_credit')),
        ];
    }
}
