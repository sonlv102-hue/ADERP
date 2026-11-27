<?php

namespace App\Exports\InventoryIntegrity;

use App\Models\InventoryAuditRun;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;

class IntegritySummarySheet implements FromArray, WithTitle, ShouldAutoSize, WithStrictNullComparison
{
    public const LABELS = [
        'quantity' => 'Sai lệch số lượng', 'negative' => 'Tồn âm', 'phantom' => 'Tồn kho ảo', 'value' => 'Sai lệch giá trị', 'cost' => 'Sai lệch giá vốn',
        'document' => 'Sai lệch logic chứng từ', 'missing_je' => 'Thiếu bút toán', 'accounting' => 'Sai lệch kế toán', 'expected' => 'Chênh lệch làm tròn',
        'policy' => 'Chờ quyết định chính sách', 'dev' => 'Chờ DEV', 'verification' => 'Chờ xác minh', 'resolved' => 'Đã xử lý',
        'accepted' => 'Chênh lệch hợp lệ',
    ];

    public function __construct(private array $kpis, private ?InventoryAuditRun $run, private array $filters, private int $rows) {}

    public function title(): string
    {
        return 'Tổng hợp';
    }

    public function array(): array
    {
        $r = $this->run;
        $out = [['KIỂM SOÁT SAI LỆCH TỒN KHO VÀ KẾ TOÁN'], [],
            ['Lần audit', $r ? "#{$r->id} ({$r->scope_type}" . ($r->scope_value ? ": {$r->scope_value}" : '') . ')' : 'Chưa có'],
            ['Thời điểm', $r?->finished_at?->format('d/m/Y H:i:s')], ['Phiên bản quy tắc', $r?->rules_version],
            ['Sản phẩm đã kiểm tra', $r?->products_checked], ['Kho đã kiểm tra', $r?->warehouses_checked], ['Dòng SP×kho', $r?->rows_checked],
            ['Bộ lọc khi xuất', json_encode(array_filter($this->filters), JSON_UNESCAPED_UNICODE) ?: '{}'], ['Số vấn đề xuất', $this->rows], [],
            ['Sản phẩm có sai lệch (đang mở)', $this->kpis['products_with_issues']], ['Vấn đề đang mở', $this->kpis['open_issues']], [],
            ['Nhóm sai lệch (đang mở)', 'Số vấn đề']];
        foreach ($this->kpis['groups'] as $k => $n) {
            $out[] = [self::LABELS[$k] ?? $k, $n];
        }
        $out[] = [];
        $out[] = ['Nhóm phụ trách (tất cả)', 'Số vấn đề'];
        foreach ($this->kpis['buckets'] as $k => $n) {
            $out[] = [$k === 'accounting' ? 'Chờ kế toán' : (self::LABELS[$k] ?? $k), $n];
        }
        $out[] = [];
        $out[] = ['Cơ sở đối chiếu giá trị (không cộng giữa các cơ sở)', 'Số vấn đề', 'Tăng', 'Giảm', 'Ròng'];
        foreach ($this->kpis['values'] as $v) {
            $out[] = [$v['label'], $v['count'], $v['increase'], $v['decrease'], $v['net']];
        }
        return array_merge($out, $this->reconciliation());
    }

    /** Bảng đối chiếu giá trị của lần audit toàn hệ thống (chỉ khi người xuất có quyền xem kế toán). */
    private function reconciliation(): array
    {
        $rec = $this->run?->scope_type === 'all' && auth()->user()?->can('accounting.view') ? ($this->run->summary['reconciliation'] ?? null) : null;
        if (! $rec) {
            return [[], ['Đối chiếu giá trị tồn kho – kế toán: không hiển thị (cần lần audit toàn hệ thống và quyền xem kế toán)']];
        }
        $t = $rec['totals'];
        $out = [[], ['ĐỐI CHIẾU GIÁ TRỊ TỒN KHO – KẾ TOÁN', 'Ngưỡng SL: ' . $rec['thresholds']['qty'] . ' · GT: ' . $rec['thresholds']['value'] . 'đ'],
            ['Tổng AVCO', $t['avco']], ['Tổng lô dự án (FIFO)', $t['lot']], ['Sổ kho (movement hiệu lực)', $t['book']], ['TK 156 (tổng)', $t['gl156_total']]];
        foreach ($t['gl156'] as $acc => $v) {
            $out[] = ["  TK {$acc}", $v];
        }
        $out[] = ['TK 154 số dư (sau kết chuyển — chỉ tham khảo)', $t['gl154_total']];
        $out[] = ['WIP hiệu lực (mọi nguồn — chỉ tham khảo)', $t['wip_active_total']];
        foreach ($rec['comparisons'] as $c) {
            $out[] = [];
            $out[] = [$c['label'], 'Bên trái', $c['left'], 'Bên phải', $c['right'], 'Chênh', $c['diff'], 'Chưa giải trình', $c['unexplained'], 'Kết luận', $c['status']];
            foreach ($c['items'] as $i) {
                $out[] = ['  ' . $i['label'], $i['kind'], $i['count'], $i['amount']];
            }
        }
        return $out;
    }
}
