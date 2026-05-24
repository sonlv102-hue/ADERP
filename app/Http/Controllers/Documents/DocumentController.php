<?php

namespace App\Http\Controllers\Documents;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Order;
use App\Models\Project;
use App\Services\DocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class DocumentController extends Controller
{
    public function __construct(private DocumentService $service) {}

    public function index(Request $request): Response
    {
        $query = Document::with(['documentType', 'uploader'])->orderByDesc('id');

        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(fn ($q2) =>
                $q2->where('code', 'ilike', "%{$q}%")
                   ->orWhere('title', 'ilike', "%{$q}%")
            );
        }
        if ($request->filled('type_id')) {
            $query->where('document_type_id', $request->type_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return Inertia::render('Documents/Documents/Index', [
            'documents'     => $query->paginate(20)->through(fn ($d) => [
                'id'           => $d->id,
                'code'         => $d->code,
                'title'        => $d->title,
                'type_name'    => $d->documentType->name,
                'issued_date'  => $d->issued_date?->format('d/m/Y'),
                'expired_date' => $d->expired_date?->format('d/m/Y'),
                'status'       => $d->status->value,
                'status_label' => $d->status->label(),
                'status_color' => $d->status->color(),
                'file_name'    => $d->file_name,
                'file_type'    => $d->file_type,
                'uploader'     => $d->uploader->name,
                'created_at'   => $d->created_at->format('d/m/Y'),
            ]),
            'document_types' => DocumentType::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'statuses'       => collect(DocumentStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'filters'        => $request->only(['search', 'type_id', 'status']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Documents/Documents/Form', [
            'document_types' => DocumentType::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'statuses'       => collect(DocumentStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'related_types'  => collect(Document::relatedTypes())->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'next_code'      => Document::generateCode(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('documents.create');

        $data = $request->validate([
            'document_type_id' => 'required|exists:document_types,id',
            'title'            => 'required|string|max:300',
            'issued_date'      => 'nullable|date',
            'expired_date'     => 'nullable|date|after_or_equal:issued_date',
            'status'           => 'required|string',
            'note'             => 'nullable|string|max:1000',
            'file'             => 'nullable|file|max:20480',
            'related_type'     => 'nullable|string',
            'related_id'       => 'nullable|integer|min:1',
        ]);

        $fileData = [];
        if ($request->hasFile('file')) {
            $fileData = $this->service->storeFile($request->file('file'));
        }

        $document = Document::create([
            'code'             => Document::generateCode(),
            'document_type_id' => $data['document_type_id'],
            'title'            => $data['title'],
            'issued_date'      => $data['issued_date'] ?? null,
            'expired_date'     => $data['expired_date'] ?? null,
            'status'           => $data['status'],
            'note'             => $data['note'] ?? null,
            'uploaded_by'      => auth()->id(),
            ...$fileData,
        ]);

        if (!empty($data['related_type']) && !empty($data['related_id'])) {
            $label = $this->service->resolveRelatedLabel($data['related_type'], (int) $data['related_id']);
            $this->service->attach($document, $data['related_type'], (int) $data['related_id'], $label);
        }

        return redirect()->route('documents.documents.show', $document)->with('success', "Đã tạo chứng từ {$document->code}.");
    }

    public function show(Document $document): Response
    {
        $document->load(['documentType', 'uploader', 'relations']);

        return Inertia::render('Documents/Documents/Show', [
            'document' => [
                'id'              => $document->id,
                'code'            => $document->code,
                'title'           => $document->title,
                'type_name'       => $document->documentType->name,
                'issued_date'     => $document->issued_date?->format('d/m/Y'),
                'expired_date'    => $document->expired_date?->format('d/m/Y'),
                'status'          => $document->status->value,
                'status_label'    => $document->status->label(),
                'status_color'    => $document->status->color(),
                'note'            => $document->note,
                'file_name'       => $document->file_name,
                'file_type'       => $document->file_type,
                'file_size_human' => $document->file_size_human,
                'file_url'        => $document->file_url,
                'uploader'        => $document->uploader->name,
                'created_at'      => $document->created_at->format('d/m/Y H:i'),
                'relations'       => $document->relations->map(fn ($r) => [
                    'id'           => $r->id,
                    'related_type' => $r->related_type,
                    'type_label'   => $r->relatedTypeLabel(),
                    'related_id'   => $r->related_id,
                    'related_label'=> $r->related_label,
                ]),
            ],
            'related_types' => collect(Document::relatedTypes())->map(fn ($label, $value) => compact('value', 'label'))->values(),
        ]);
    }

    public function edit(Document $document): Response
    {
        return Inertia::render('Documents/Documents/Form', [
            'document'       => [
                'id'               => $document->id,
                'code'             => $document->code,
                'document_type_id' => $document->document_type_id,
                'title'            => $document->title,
                'issued_date'      => $document->issued_date?->format('Y-m-d'),
                'expired_date'     => $document->expired_date?->format('Y-m-d'),
                'status'           => $document->status->value,
                'note'             => $document->note,
                'file_name'        => $document->file_name,
                'file_url'         => $document->file_url,
            ],
            'document_types' => DocumentType::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'statuses'       => collect(DocumentStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'related_types'  => collect(Document::relatedTypes())->map(fn ($label, $value) => compact('value', 'label'))->values(),
        ]);
    }

    public function update(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('documents.manage');

        $data = $request->validate([
            'document_type_id' => 'required|exists:document_types,id',
            'title'            => 'required|string|max:300',
            'issued_date'      => 'nullable|date',
            'expired_date'     => 'nullable|date',
            'status'           => 'required|string',
            'note'             => 'nullable|string|max:1000',
            'file'             => 'nullable|file|max:20480',
        ]);

        $fileData = [];
        if ($request->hasFile('file')) {
            $this->service->deleteFile($document);
            $fileData = $this->service->storeFile($request->file('file'));
        }

        $document->update([
            'document_type_id' => $data['document_type_id'],
            'title'            => $data['title'],
            'issued_date'      => $data['issued_date'] ?? null,
            'expired_date'     => $data['expired_date'] ?? null,
            'status'           => $data['status'],
            'note'             => $data['note'] ?? null,
            ...$fileData,
        ]);

        return redirect()->route('documents.documents.show', $document)->with('success', 'Chứng từ đã được cập nhật.');
    }

    public function destroy(Document $document): RedirectResponse
    {
        $this->authorize('documents.manage');
        $this->service->deleteFile($document);
        $document->delete();

        return redirect()->route('documents.documents.index')->with('success', 'Đã xoá chứng từ.');
    }

    public function download(Document $document): mixed
    {
        if (!$document->file_path || !Storage::disk('public')->exists($document->file_path)) {
            return back()->with('error', 'File không tồn tại.');
        }

        return Storage::disk('public')->download($document->file_path, $document->file_name);
    }

    public function attach(Request $request, Document $document): RedirectResponse
    {
        $data = $request->validate([
            'related_type' => 'required|string',
            'related_id'   => 'required|integer|min:1',
        ]);

        $label = $this->service->resolveRelatedLabel($data['related_type'], (int) $data['related_id']);
        $this->service->attach($document, $data['related_type'], (int) $data['related_id'], $label);

        return back()->with('success', 'Đã gắn chứng từ.');
    }

    public function detach(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('documents.manage');
        $this->service->detach($document, (int) $request->relation_id);

        return back()->with('success', 'Đã gỡ liên kết.');
    }
}
