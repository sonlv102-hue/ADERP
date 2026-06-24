<?php

namespace App\Exports;

use App\Models\BankAccount;
use App\Models\BankTransaction;

class BankTransactionsExport extends BaseListExport
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Giao dịch ngân hàng'; }
    protected function reportTitle(): string { return 'DANH SÁCH GIAO DỊCH NGÂN HÀNG'; }

    protected function filterDescription(): string
    {
        $parts = [];
        if ($id = $this->filters['bank_account_id'] ?? null) {
            $name = BankAccount::find($id)?->account_name ?? "TK #{$id}";
            $parts[] = "Tài khoản: {$name}";
        }
        if ($f = $this->filters['date_from'] ?? null) $parts[] = "Từ: {$f}";
        if ($t = $this->filters['date_to']   ?? null) $parts[] = "Đến: {$t}";
        if ($s = $this->filters['status']    ?? null) $parts[] = "Trạng thái: {$s}";
        if ($tx = $this->filters['tx_type']  ?? null) $parts[] = "Loại GD: {$tx}";
        return $parts ? implode('   |   ', $parts) : 'Tất cả giao dịch ngân hàng';
    }

    protected function columns(): array
    {
        return [
            ['key' => '__stt',            'header' => 'STT',              'width' => 5,  'type' => 'number'],
            ['key' => 'transaction_date', 'header' => 'Ngày GD',          'width' => 12, 'type' => 'date'],
            ['key' => 'reference',        'header' => 'Số GD',            'width' => 16, 'type' => 'text'],
            ['key' => 'bank_account',     'header' => 'Tài khoản NH',     'width' => 28, 'type' => 'text'],
            ['key' => 'description',      'header' => 'Nội dung',         'width' => 40, 'type' => 'text'],
            ['key' => 'debit',            'header' => 'Tiền vào (₫)',     'width' => 18, 'type' => 'money'],
            ['key' => 'credit',           'header' => 'Tiền ra (₫)',      'width' => 18, 'type' => 'money'],
            ['key' => 'tx_type',          'header' => 'Loại GD',          'width' => 16, 'type' => 'text'],
            ['key' => 'reconcile_status', 'header' => 'Đối soát',         'width' => 14, 'type' => 'text'],
            ['key' => 'status',           'header' => 'Hạch toán',        'width' => 14, 'type' => 'text'],
        ];
    }

    protected function buildRows(): array
    {
        $bankAccountId = $this->filters['bank_account_id'] ?? null;
        $status        = $this->filters['status'] ?? null;
        $txType        = $this->filters['tx_type'] ?? null;
        $from          = $this->filters['date_from'] ?? null;
        $to            = $this->filters['date_to'] ?? null;
        $counterpart   = $this->filters['counterpart'] ?? null;

        return BankTransaction::with(['bankAccount'])
            ->when($bankAccountId, fn ($q) => $q->where('bank_account_id', $bankAccountId))
            ->when($status,        fn ($q) => $q->where('status', $status))
            ->when($txType,        fn ($q) => $q->where('tx_type', $txType))
            ->when($from,          fn ($q) => $q->where('transaction_date', '>=', $from))
            ->when($to,            fn ($q) => $q->where('transaction_date', '<=', $to))
            ->when($counterpart,   fn ($q) => $q->where(function ($sq) use ($counterpart) {
                $sq->where('counterpart_account', 'ilike', "%{$counterpart}%")
                   ->orWhere('counterpart_name', 'ilike', "%{$counterpart}%");
            }))
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get()
            ->map(fn ($tx) => [
                'transaction_date' => $tx->transaction_date?->format('d/m/Y') ?? '',
                'reference'        => $tx->reference ?? '',
                'bank_account'     => $tx->bankAccount->account_name ?? '',
                'description'      => $tx->description ?? '',
                'debit'            => (float) $tx->debit,
                'credit'           => (float) $tx->credit,
                'tx_type'          => $tx->txTypeLabel(),
                'reconcile_status' => $tx->reconciled_at ? 'Đã đối soát' : 'Chưa đối soát',
                'status'           => $tx->status->label(),
            ])
            ->toArray();
    }

    protected function buildTotals(): array
    {
        $rows = $this->buildRows();
        return [
            'reference'   => 'Tổng cộng (' . count($rows) . ' giao dịch)',
            'debit'       => array_sum(array_column($rows, 'debit')),
            'credit'      => array_sum(array_column($rows, 'credit')),
        ];
    }
}
