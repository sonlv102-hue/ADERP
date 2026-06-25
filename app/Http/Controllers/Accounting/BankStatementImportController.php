<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankStatementImportBatch;
use App\Services\BankStatementImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankStatementImportController extends Controller
{
    public function __construct(private BankStatementImportService $service) {}

    /** POST /bank-accounts/{bankAccount}/transactions/import-excel/batch */
    public function uploadBatch(Request $request, BankAccount $bankAccount): JsonResponse
    {
        $this->authorize('accounting.manage');

        $request->validate([
            'files'   => 'required|array|min:1|max:20',
            'files.*' => 'required|file|mimes:xlsx,xls|max:10240',
        ]);

        try {
            $batch   = $this->service->uploadAndParse($bankAccount, $request->file('files'), auth()->id());
            $preview = $this->service->getBatchPreview($batch);
            return response()->json(['batch_id' => $batch->id, 'preview' => $preview]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            \Log::error('BankStatementImport uploadBatch failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Import thất bại: ' . $e->getMessage()], 500);
        }
    }

    /** GET /bank-statement-import-batches/{batch}/preview */
    public function preview(BankStatementImportBatch $batch): JsonResponse
    {
        $this->authorize('accounting.manage');
        return response()->json($this->service->getBatchPreview($batch));
    }

    /** POST /bank-statement-import-batches/{batch}/confirm */
    public function confirm(BankStatementImportBatch $batch): JsonResponse
    {
        $this->authorize('accounting.manage');

        if (! in_array($batch->status, ['parsed', 'uploaded'])) {
            return response()->json(['message' => 'Batch không thể xác nhận (trạng thái: ' . $batch->status . ').'], 422);
        }

        try {
            $result = $this->service->confirmBatch($batch, auth()->id());
            return response()->json(['imported' => $result['imported'], 'success' => true]);
        } catch (\Throwable $e) {
            \Log::error('BankStatementImport confirm failed', ['batch_id' => $batch->id, 'error' => $e->getMessage()]);
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /** POST /bank-statement-import-batches/{batch}/cancel */
    public function cancel(BankStatementImportBatch $batch): JsonResponse
    {
        $this->authorize('accounting.manage');
        $this->service->cancelBatch($batch);
        return response()->json(['success' => true]);
    }
}
