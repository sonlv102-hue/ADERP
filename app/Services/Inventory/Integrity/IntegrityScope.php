<?php

namespace App\Services\Inventory\Integrity;

use App\Models\InventoryIntegrityIssue;
use App\Services\Inventory\InventoryDocumentAuditService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Phạm vi một lần audit. Cùng một vị từ dùng để (1) lọc phát hiện và (2) xác định vấn đề cũ nào được phép
 * tự đóng → audit phạm vi nhỏ không bao giờ đóng vấn đề ngoài phạm vi.
 */
class IntegrityScope
{
    public const TYPES = ['all', 'product', 'warehouse', 'project', 'document', 'issue'];

    private ?array $rowKeys = null;   // khóa "pid-wid" thuộc phạm vi project/document

    public function __construct(public string $type, public ?string $value = null, public ?int $id = null, public ?string $issueKey = null) {}

    public static function make(string $type, ?string $value): self
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Phạm vi audit không hợp lệ.');
        }
        $value = $value !== null ? trim($value) : null;
        return match ($type) {
            'all' => new self('all'),
            'product' => new self('product', $value, self::resolve('products', $value) ?? throw new InvalidArgumentException("Không tìm thấy sản phẩm {$value}.")),
            'warehouse' => new self('warehouse', $value, DB::table('warehouses')->where('id', (int) $value)->value('id') ?? throw new InvalidArgumentException('Không tìm thấy kho.')),
            'project' => new self('project', $value, self::resolve('projects', $value) ?? throw new InvalidArgumentException("Không tìm thấy dự án {$value}.")),
            'document' => new self('document', $value),
            'issue' => self::forIssue(InventoryIntegrityIssue::findOrFail((int) $value)),
        };
    }

    private static function forIssue(InventoryIntegrityIssue $issue): self
    {
        $level = $issue->evidence['level'] ?? 'row';
        $base = match (true) {
            $level === 'doc' && $issue->document_ref => new self('document', $issue->document_ref),
            $level === 'row' && $issue->product_id => new self('product', (string) $issue->product_id, $issue->product_id),
            default => new self('all'),
        };
        $base->issueKey = $issue->issue_key;
        return $base;
    }

    private static function resolve(string $table, ?string $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        return DB::table($table)->where(fn ($q) => is_numeric($v) ? $q->where('id', (int) $v)->orWhere('code', $v) : $q->where('code', $v))->value('id');
    }

    public function auditFilters(): array
    {
        return match ($this->type) {
            'product' => ['product' => [$this->id]],
            'warehouse' => ['warehouse' => $this->id],
            'project' => ['project' => $this->id],
            'document' => ['document' => $this->value],
            default => [],
        };
    }

    public function matchesFinding(array $f): bool
    {
        return $this->matches($f['keys'], $f['warehouse_id'] ?? null, $f['project_id'] ?? null, $f['doc_code'] ?? null, $f['doc_type'] === 'company' ? 'aggregate' : 'doc');
    }

    public function matchesIssue(InventoryIntegrityIssue $i): bool
    {
        if ($this->issueKey !== null) {
            return $i->issue_key === $this->issueKey;
        }
        if ($i->source !== 'auto') {
            return false; // chủ đề chính sách không tự đóng
        }
        $ev = $i->evidence ?? [];
        return $this->matches($ev['keys'] ?? [], $i->warehouse_id, $i->project_id, $i->document_ref, $ev['level'] ?? 'row');
    }

    private function matches(array $keys, ?int $wid, ?int $pid, ?string $doc, string $level): bool
    {
        if ($this->type === 'all') {
            return true;
        }
        if ($level === 'aggregate') {
            return false;
        }
        if ($this->type === 'document' && $level === 'doc') {
            return $doc === $this->value;
        }
        return match ($this->type) {
            'product' => (bool) array_filter($keys, fn ($k) => str_starts_with($k, $this->id . '-')),
            'warehouse' => $wid === $this->id || (bool) array_filter($keys, fn ($k) => str_ends_with($k, '-' . $this->id)),
            'project' => $pid === $this->id || (bool) array_intersect($keys, $this->rowKeys()),
            'document' => (bool) array_intersect($keys, $this->rowKeys()),
            default => false,
        };
    }

    private function rowKeys(): array
    {
        if ($this->rowKeys === null) {
            $docs = app(InventoryDocumentAuditService::class);
            $this->rowKeys = array_keys($this->type === 'project' ? $docs->rowsForProject($this->id) : $docs->rowsForDocument((string) $this->value));
        }
        return $this->rowKeys;
    }
}
