<?php

namespace App\Services;

use App\Imports\PurchaseSupplierQuoteImport;
use App\Models\Product;
use App\Models\PurchaseQuoteComparison;
use App\Models\PurchaseQuoteImportLog;
use App\Models\PurchaseSupplierQuote;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

class PurchaseQuoteImportService
{
    private const CACHE_PREFIX = 'pqc_quote_import:';
    private const CACHE_TTL_MINUTES = 30;

    public function __construct(private PurchaseQuoteComparisonService $comparisonService) {}

    /** @return array<string,mixed> preview payload for the modal */
    public function previewQuote(PurchaseQuoteComparison $comparison, UploadedFile $file, array $meta): array
    {
        $comparison->loadMissing('items');
        if ($comparison->items->isEmpty()) {
            throw new RuntimeException('Đợt so sánh chưa có mặt hàng nào — hãy thêm hàng trước khi import báo giá.');
        }

        $itemsByCode = [];
        $nameByItemId = [];
        foreach ($comparison->items as $item) {
            $itemsByCode[$this->norm($item->product_code_snapshot)] = [
                'comparison_item_id' => $item->id,
                'product_id'         => $item->product_id,
                'unit_snapshot'      => $item->unit_snapshot,
                'requested_qty'      => (float) $item->requested_qty,
            ];
            $nameByItemId[$item->id] = $item->product_name_snapshot;
        }

        $knownCodes = Product::pluck('code')
            ->mapWithKeys(fn ($c) => [$this->norm((string) $c) => true])
            ->all();

        $reader = new PurchaseSupplierQuoteImport($itemsByCode, $knownCodes);
        Excel::import($reader, $file);

        if ($reader->templateMismatch) {
            throw new RuntimeException(PurchaseSupplierQuoteImport::TEMPLATE_ERROR);
        }

        // Giữ lại file gốc kể cả khi preview lỗi (truy vết qua purchase_quote_import_logs).
        // TODO: cleanup unconfirmed quote-import files after retention period
        $storedPath = $file->store('quote-imports', 'local');
        $hash = hash_file('sha256', Storage::disk('local')->path($storedPath));

        $warningRows = array_unique(array_column($reader->warnings, 'row'));
        $errorRows = array_unique(array_column($reader->errors, 'row'));
        $canConfirm = !$reader->hasFatalError && count($reader->rows) > 0;

        $log = PurchaseQuoteImportLog::create([
            'comparison_id'     => $comparison->id,
            'original_filename' => $file->getClientOriginalName(),
            'file_hash'         => $hash,
            'total_rows'        => $reader->totalRows,
            'valid_rows'        => count($reader->rows),
            'warning_rows'      => count($warningRows),
            'error_rows'        => count($errorRows),
            'import_status'     => 'previewed',
            'imported_by'       => auth()->id(),
            'imported_at'       => now(),
            'error_detail_json' => $reader->errors ?: null,
        ]);

        $previewId = (string) Str::uuid();
        Cache::put(self::CACHE_PREFIX . $previewId, [
            'comparison_id'     => $comparison->id,
            'import_log_id'     => $log->id,
            'meta'              => $meta,
            'stored_path'       => $storedPath,
            'file_hash'         => $hash,
            'original_filename' => $file->getClientOriginalName(),
            'rows'              => $reader->rows,
            'can_confirm'       => $canConfirm,
        ], now()->addMinutes(self::CACHE_TTL_MINUTES));

        $warningRowSet = array_flip($warningRows);

        return [
            'preview_id'   => $previewId,
            'total_rows'   => $reader->totalRows,
            'valid_rows'   => count($reader->rows),
            'warning_rows' => count($warningRows),
            'error_rows'   => count($errorRows),
            'can_confirm'  => $canConfirm,
            'rows'         => array_map(fn ($r) => [
                'row'          => $r['row'],
                'product_code' => $r['product_code'],
                'product_name' => $nameByItemId[$r['comparison_item_id']] ?? '',
                'unit'         => $r['unit_snapshot'],
                'qty'          => $r['requested_qty'],
                'unit_price'   => $r['unit_price'],
                'discount'     => $r['discount_percent'],
                'vat'          => $r['vat_percent'],
                'net'          => $r['net_unit_price'],
                'status'       => isset($warningRowSet[$r['row']]) ? 'warning' : 'ok',
            ], $reader->rows),
            'errors'       => $reader->errors,
            'warnings'     => $reader->warnings,
        ];
    }

    public function confirmQuote(string $previewId): PurchaseSupplierQuote
    {
        $payload = Cache::get(self::CACHE_PREFIX . $previewId);
        if (!$payload) {
            throw new RuntimeException('Phiên import đã hết hạn. Vui lòng tải file lên lại.');
        }
        if (!$payload['can_confirm']) {
            throw new RuntimeException('File còn lỗi nghiêm trọng — không thể xác nhận import.');
        }

        $comparison = PurchaseQuoteComparison::findOrFail($payload['comparison_id']);
        $meta = $payload['meta'];
        $supplierId = (int) $meta['supplier_id'];

        $validItemIds = $comparison->items()->pluck('id')->flip();
        foreach ($payload['rows'] as $r) {
            if (!$validItemIds->has($r['comparison_item_id'])) {
                throw new RuntimeException('Danh sách hàng của đợt so sánh đã thay đổi. Vui lòng import lại file.');
            }
        }

        $quote = DB::transaction(function () use ($comparison, $payload, $meta, $supplierId) {
            $maxVersion = (int) PurchaseSupplierQuote::withTrashed()
                ->where('comparison_id', $comparison->id)
                ->where('supplier_id', $supplierId)
                ->max('version_no');

            PurchaseSupplierQuote::where('comparison_id', $comparison->id)
                ->where('supplier_id', $supplierId)
                ->update(['is_active_version' => false]);

            $quote = PurchaseSupplierQuote::create([
                'comparison_id'     => $comparison->id,
                'supplier_id'       => $supplierId,
                'quote_no'          => $meta['quote_no'] ?? null,
                'quote_date'        => $meta['quote_date'] ?? null,
                'valid_until'       => $meta['valid_until'] ?? null,
                'currency'          => $meta['currency'] ?? 'VND',
                'payment_terms'     => $meta['payment_terms'] ?? null,
                'shipping_fee'      => $meta['shipping_fee'] ?? 0,
                'note'              => $meta['note'] ?? null,
                'version_no'        => $maxVersion + 1,
                'is_active_version' => true,
                'original_filename' => $payload['original_filename'],
                'stored_file_path'  => $payload['stored_path'],
                'file_hash'         => $payload['file_hash'],
                'uploaded_by'       => auth()->id(),
                'uploaded_at'       => now(),
            ]);

            foreach ($payload['rows'] as $r) {
                $quote->lines()->create([
                    'comparison_item_id' => $r['comparison_item_id'],
                    'product_id'         => $r['product_id'],
                    'unit_snapshot'      => $r['unit_snapshot'],
                    'unit_price'         => $r['unit_price'],
                    'discount_percent'   => $r['discount_percent'],
                    'net_unit_price'     => $r['net_unit_price'],
                    'vat_percent'        => $r['vat_percent'],
                    'delivery_time'      => $r['delivery_time'],
                    'warranty'           => $r['warranty'],
                    'note'               => $r['note'],
                ]);
            }

            PurchaseQuoteImportLog::where('id', $payload['import_log_id'])->update([
                'import_status' => 'confirmed',
                'quote_id'      => $quote->id,
            ]);

            $this->comparisonService->recalcStatus($comparison);

            activity()
                ->performedOn($quote)
                ->withProperties([
                    'supplier_id' => $supplierId,
                    'version'     => $quote->version_no,
                    'lines'       => count($payload['rows']),
                    'file'        => $payload['original_filename'],
                ])
                ->log('Import báo giá NCC');

            return $quote;
        });

        Cache::forget(self::CACHE_PREFIX . $previewId);

        return $quote->load('lines', 'supplier');
    }

    public function deleteQuote(PurchaseSupplierQuote $quote): void
    {
        DB::transaction(function () use ($quote) {
            $comparison = $quote->comparison;
            $supplierId = $quote->supplier_id;
            $comparisonId = $quote->comparison_id;

            $quote->update(['is_active_version' => false]);
            $quote->delete();

            $prev = PurchaseSupplierQuote::where('comparison_id', $comparisonId)
                ->where('supplier_id', $supplierId)
                ->orderByDesc('version_no')
                ->first();
            if ($prev) {
                $prev->update(['is_active_version' => true]);
            }

            // Xóa selection trỏ tới NCC vừa bị gỡ báo giá active
            $this->comparisonService->recalcStatus($comparison);
        });
    }

    private function norm(string $v): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $v)));
    }
}
