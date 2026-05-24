<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentRelation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DocumentService
{
    public function storeFile(UploadedFile $file): array
    {
        $path = $file->store('documents', 'public');

        return [
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
        ];
    }

    public function deleteFile(Document $document): void
    {
        if ($document->file_path) {
            Storage::disk('public')->delete($document->file_path);
        }
    }

    public function attach(Document $document, string $relatedType, int $relatedId, string $relatedLabel = ''): DocumentRelation
    {
        return DocumentRelation::firstOrCreate(
            [
                'document_id'  => $document->id,
                'related_type' => $relatedType,
                'related_id'   => $relatedId,
            ],
            ['related_label' => $relatedLabel]
        );
    }

    public function detach(Document $document, int $relationId): void
    {
        DocumentRelation::where('document_id', $document->id)
            ->where('id', $relationId)
            ->delete();
    }

    public function resolveRelatedLabel(string $type, int $id): string
    {
        return match($type) {
            'order'          => \App\Models\Order::find($id)?->code ?? "#$id",
            'customer'       => \App\Models\Customer::find($id)?->name ?? "#$id",
            'project'        => \App\Models\Project::find($id)?->code ?? "#$id",
            'contract'       => \App\Models\Contract::find($id)?->code ?? "#$id",
            'purchase_order' => \App\Models\PurchaseOrder::find($id)?->code ?? "#$id",
            'invoice'        => \App\Models\Invoice::find($id)?->code ?? "#$id",
            'ticket'         => \App\Models\Ticket::find($id)?->code ?? "#$id",
            'warranty'       => \App\Models\Warranty::find($id)?->code ?? "#$id",
            default          => "#$id",
        };
    }
}
