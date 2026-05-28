<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\PaymentTerm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaymentTermController extends Controller
{
    public function index(): Response
    {
        $terms = PaymentTerm::orderBy('days')->get()->map(fn($t) => [
            'id'          => $t->id,
            'code'        => $t->code,
            'name'        => $t->name,
            'days'        => $t->days,
            'description' => $t->description,
            'is_active'   => $t->is_active,
        ]);

        return Inertia::render('Accounting/PaymentTerms/Index', ['terms' => $terms]);
    }

    public function create(): Response
    {
        return Inertia::render('Accounting/PaymentTerms/Form', ['term' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'        => 'required|string|max:20|unique:payment_terms,code',
            'name'        => 'required|string|max:100',
            'days'        => 'required|integer|min:0|max:365',
            'description' => 'nullable|string|max:255',
            'is_active'   => 'boolean',
        ]);

        PaymentTerm::create($data);

        return redirect()->route('accounting.payment-terms.index')->with('success', 'Đã tạo điều khoản thanh toán.');
    }

    public function edit(PaymentTerm $paymentTerm): Response
    {
        return Inertia::render('Accounting/PaymentTerms/Form', [
            'term' => [
                'id'          => $paymentTerm->id,
                'code'        => $paymentTerm->code,
                'name'        => $paymentTerm->name,
                'days'        => $paymentTerm->days,
                'description' => $paymentTerm->description,
                'is_active'   => $paymentTerm->is_active,
            ],
        ]);
    }

    public function update(Request $request, PaymentTerm $paymentTerm): RedirectResponse
    {
        $data = $request->validate([
            'code'        => 'required|string|max:20|unique:payment_terms,code,' . $paymentTerm->id,
            'name'        => 'required|string|max:100',
            'days'        => 'required|integer|min:0|max:365',
            'description' => 'nullable|string|max:255',
            'is_active'   => 'boolean',
        ]);

        $paymentTerm->update($data);

        return redirect()->route('accounting.payment-terms.index')->with('success', 'Đã cập nhật.');
    }
}
