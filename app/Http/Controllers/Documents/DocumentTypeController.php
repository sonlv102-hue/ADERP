<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\DocumentType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DocumentTypeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Documents/DocumentTypes/Index', [
            'types' => DocumentType::withCount('documents')->orderBy('name')->get()->map(fn ($t) => [
                'id'             => $t->id,
                'name'           => $t->name,
                'code'           => $t->code,
                'description'    => $t->description,
                'is_active'      => $t->is_active,
                'documents_count'=> $t->documents_count,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:200',
            'code'        => 'required|string|max:50|unique:document_types,code',
            'description' => 'nullable|string|max:500',
        ]);

        DocumentType::create([...$data, 'is_active' => true]);

        return redirect()->route('documents.types.index')->with('success', 'Đã thêm loại chứng từ.');
    }

    public function update(Request $request, DocumentType $type): RedirectResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:200',
            'code'        => 'required|string|max:50|unique:document_types,code,' . $type->id,
            'description' => 'nullable|string|max:500',
            'is_active'   => 'boolean',
        ]);

        $type->update($data);

        return redirect()->route('documents.types.index')->with('success', 'Đã cập nhật loại chứng từ.');
    }

    public function destroy(DocumentType $type): RedirectResponse
    {
        if ($type->documents()->exists()) {
            return back()->with('error', 'Không thể xoá — đã có chứng từ thuộc loại này.');
        }

        $type->delete();

        return redirect()->route('documents.types.index')->with('success', 'Đã xoá loại chứng từ.');
    }
}
