<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Catalog/Services/Index', [
            'services' => Service::orderBy('code')
                ->paginate(20)
                ->through(fn ($s) => [
                    'id' => $s->id,
                    'code' => $s->code,
                    'name' => $s->name,
                    'unit' => $s->unit,
                    'price' => $s->price,
                    'is_active' => $s->is_active,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Catalog/Services/Form', [
            'nextCode' => Service::generateCode(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'unique:services,code'],
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:50'],
            'price' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
        ]);

        Service::create($data);

        return redirect()->route('catalog.services.index')
            ->with('success', 'Đã tạo dịch vụ.');
    }

    public function edit(Service $service): Response
    {
        return Inertia::render('Catalog/Services/Form', [
            'service' => [
                'id' => $service->id,
                'code' => $service->code,
                'name' => $service->name,
                'unit' => $service->unit,
                'price' => $service->price,
                'description' => $service->description,
                'is_active' => $service->is_active,
            ],
        ]);
    }

    public function update(Request $request, Service $service): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'unique:services,code,' . $service->id],
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:50'],
            'price' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $service->update($data);

        return redirect()->route('catalog.services.index')
            ->with('success', 'Đã cập nhật dịch vụ.');
    }

    public function destroy(Service $service): RedirectResponse
    {
        $service->delete();

        return redirect()->route('catalog.services.index')
            ->with('success', 'Đã xóa dịch vụ.');
    }
}
