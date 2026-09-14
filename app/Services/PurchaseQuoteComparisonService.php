<?php

namespace App\Services;

use App\Enums\PurchaseQuoteComparisonStatus;
use App\Imports\PurchaseQuoteItemsImport;
use App\Models\Product;
use App\Models\PurchaseQuoteComparison;
use App\Models\PurchaseQuoteComparisonItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

class PurchaseQuoteComparisonService
{
    public function create(array $data): PurchaseQuoteComparison
    {
        return PurchaseQuoteComparison::create([
            'code'            => PurchaseQuoteComparison::generateCode(),
            'name'            => $data['name'],
            'comparison_date' => $data['comparison_date'],
            'department'      => $data['department'] ?? null,
            'project_id'      => $data['project_id'] ?? null,
            'buyer_id'        => $data['buyer_id'] ?? null,
            'note'            => $data['note'] ?? null,
            'status'          => PurchaseQuoteComparisonStatus::Draft,
            'created_by'      => auth()->id(),
        ]);
    }

    public function updateHeader(PurchaseQuoteComparison $comparison, array $data): void
    {
        $comparison->update([
            'name'            => $data['name'],
            'comparison_date' => $data['comparison_date'],
            'department'      => $data['department'] ?? null,
            'project_id'      => $data['project_id'] ?? null,
            'buyer_id'        => $data['buyer_id'] ?? null,
            'note'            => $data['note'] ?? null,
        ]);
    }

    public function addItem(PurchaseQuoteComparison $comparison, int $productId, float $qty, ?string $spec, ?string $note): PurchaseQuoteComparisonItem
    {
        $this->assertDraft($comparison);

        if ($comparison->items()->where('product_id', $productId)->exists()) {
            throw new RuntimeException('Sản phẩm này đã có trong đợt so sánh.');
        }

        $product = Product::findOrFail($productId);

        return $comparison->items()->create([
            'product_id'            => $product->id,
            'product_code_snapshot' => $product->code,
            'product_name_snapshot' => $product->name,
            'unit_snapshot'         => $product->unit,
            'specification'         => $spec,
            'requested_qty'         => $qty,
            'note'                  => $note,
        ]);
    }

    public function removeItem(PurchaseQuoteComparisonItem $item): void
    {
        $this->assertDraft($item->comparison);

        if ($item->quoteLines()->exists()) {
            throw new RuntimeException('Không thể xóa: mặt hàng đã có báo giá NCC.');
        }

        DB::transaction(function () use ($item) {
            $item->selection()->delete();
            $item->delete();
        });
    }

    /**
     * Import danh sách hàng từ Excel (chỉ khi Draft).
     * @return array{created:int, skipped:int, errors:array<int,string>}
     */
    public function importItems(PurchaseQuoteComparison $comparison, UploadedFile $file): array
    {
        $this->assertDraft($comparison);

        $reader = new PurchaseQuoteItemsImport();
        Excel::import($reader, $file);

        $existingProductIds = $comparison->items()->pluck('product_id')->flip();
        $productsByCode = Product::where('is_active', true)
            ->pluck('id', 'code')
            ->mapWithKeys(fn ($id, $code) => [mb_strtolower(trim($code)) => $id]);

        $created = 0;
        $skipped = 0;
        $errors = [];

        DB::transaction(function () use ($reader, $comparison, $productsByCode, $existingProductIds, &$created, &$skipped, &$errors) {
            foreach ($reader->rows as $r) {
                $key = mb_strtolower(trim($r['code']));
                $productId = $productsByCode[$key] ?? null;

                if (!$productId) {
                    $errors[] = "Dòng {$r['row']}: mã hàng \"{$r['code']}\" không tồn tại.";
                    continue;
                }
                if ($existingProductIds->has($productId)) {
                    $skipped++;
                    continue;
                }

                $qty = is_numeric(str_replace([',', ' '], ['.', ''], $r['qty']))
                    ? (float) str_replace([',', ' '], ['.', ''], $r['qty'])
                    : 0;

                $product = Product::find($productId);
                $comparison->items()->create([
                    'product_id'            => $product->id,
                    'product_code_snapshot' => $product->code,
                    'product_name_snapshot' => $product->name,
                    'unit_snapshot'         => $product->unit,
                    'specification'         => $r['specification'],
                    'requested_qty'         => $qty,
                    'note'                  => $r['note'],
                ]);
                $existingProductIds->put($productId, true);
                $created++;
            }
        });

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    public function recalcStatus(PurchaseQuoteComparison $comparison): void
    {
        if ($comparison->status === PurchaseQuoteComparisonStatus::Completed) {
            return;
        }

        $comparison->update([
            'status' => $comparison->activeQuotes()->exists()
                ? PurchaseQuoteComparisonStatus::Quoted
                : PurchaseQuoteComparisonStatus::Draft,
        ]);
    }

    public function markCompleted(PurchaseQuoteComparison $comparison): void
    {
        if (!$comparison->activeQuotes()->exists()) {
            throw new RuntimeException('Chưa có báo giá NCC nào — không thể hoàn thành đợt so sánh.');
        }
        $comparison->update(['status' => PurchaseQuoteComparisonStatus::Completed]);
    }

    public function reopen(PurchaseQuoteComparison $comparison): void
    {
        $comparison->update([
            'status' => $comparison->activeQuotes()->exists()
                ? PurchaseQuoteComparisonStatus::Quoted
                : PurchaseQuoteComparisonStatus::Draft,
        ]);
    }

    private function assertDraft(PurchaseQuoteComparison $comparison): void
    {
        if ($comparison->status !== PurchaseQuoteComparisonStatus::Draft) {
            throw new RuntimeException('Chỉ có thể sửa danh sách hàng khi đợt so sánh ở trạng thái Nháp.');
        }
    }
}
