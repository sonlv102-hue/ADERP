<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\SmallToolCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SmallToolCategoryController extends Controller
{
    public function index(): Response
    {
        $this->authorize('ccdc.view');

        return Inertia::render('Accounting/SmallTools/Categories/Index', [
            'categories' => SmallToolCategory::withCount('tools')->orderBy('name')->get()
                ->map(fn ($c) => [
                    'id'          => $c->id,
                    'name'        => $c->name,
                    'code'        => $c->code,
                    'description' => $c->description,
                    'tools_count' => $c->tools_count,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('ccdc.manage');

        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'code'        => 'nullable|string|max:30|unique:small_tool_categories,code',
            'description' => 'nullable|string',
        ]);

        SmallToolCategory::create($data);

        return back()->with('success', 'Đã tạo nhóm CCDC.');
    }

    public function update(Request $request, SmallToolCategory $category): RedirectResponse
    {
        $this->authorize('ccdc.manage');

        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'code'        => 'nullable|string|max:30|unique:small_tool_categories,code,' . $category->id,
            'description' => 'nullable|string',
        ]);

        $category->update($data);

        return back()->with('success', 'Đã cập nhật nhóm CCDC.');
    }

    public function destroy(SmallToolCategory $category): RedirectResponse
    {
        $this->authorize('ccdc.manage');

        if ($category->tools()->exists()) {
            return back()->withErrors(['error' => 'Nhóm này đang có CCDC, không thể xóa.']);
        }

        $category->delete();

        return back()->with('success', 'Đã xóa nhóm CCDC.');
    }
}
