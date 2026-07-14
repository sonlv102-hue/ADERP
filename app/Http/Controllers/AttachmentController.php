<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    private array $typeMap = [
        'order'             => \App\Models\Order::class,
        'quotation'         => \App\Models\Quotation::class,
        'contract'          => \App\Models\Contract::class,
        'purchase_invoice'  => \App\Models\PurchaseInvoice::class,
        'purchase_contract' => \App\Models\PurchaseContract::class,
        'invoice'           => \App\Models\Invoice::class,
        'employee'          => \App\Models\Employee::class,
        'project_subcontract' => \App\Models\ProjectSubcontract::class,
    ];

    public function store(Request $request, string $type, int $id): RedirectResponse
    {
        abort_unless(array_key_exists($type, $this->typeMap), 404);

        $request->validate([
            'files'   => ['required', 'array', 'min:1'],
            'files.*' => ['file', 'max:20480'],
        ]);

        $model = $this->typeMap[$type]::findOrFail($id);

        foreach ($request->file('files') as $file) {
            $path = $file->store("attachments/{$type}", 'public');
            $model->attachments()->create([
                'file_name'  => $file->getClientOriginalName(),
                'file_path'  => $path,
                'mime_type'  => $file->getMimeType(),
                'file_size'  => $file->getSize(),
                'created_by' => auth()->id(),
            ]);
        }

        return back()->with('success', 'Đã đính kèm ' . count($request->file('files')) . ' file.');
    }

    public function destroy(Attachment $attachment): RedirectResponse
    {
        Storage::disk('public')->delete($attachment->file_path);
        $attachment->delete();

        return back()->with('success', 'Đã xóa file đính kèm.');
    }
}
