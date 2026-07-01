<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\EmployeeImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

class EmployeeImportController extends Controller
{
    public function __construct(private EmployeeImportService $service) {}

    public function template()
    {
        return Excel::download($this->service->generateTemplate(), 'Mau_upload_nhan_vien.xlsx');
    }

    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file'             => ['required', 'file', 'extensions:xlsx,xls,csv', 'max:10240'],
            'update_existing'  => ['nullable', 'boolean'],
        ]);

        try {
            $result = $this->service->previewImport(
                $request->file('file'),
                (bool) $request->boolean('update_existing')
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'preview_id'       => ['required', 'string'],
            'update_existing'  => ['nullable', 'boolean'],
        ]);

        try {
            $result = $this->service->confirmImport(
                $request->string('preview_id')->toString(),
                (bool) $request->boolean('update_existing')
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function downloadErrors(string $errorFileId)
    {
        try {
            $export = $this->service->errorsExport($errorFileId);
        } catch (RuntimeException $e) {
            abort(404, $e->getMessage());
        }

        return Excel::download($export, 'Loi_import_nhan_vien.xlsx');
    }
}
