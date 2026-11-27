<?php

namespace App\Http\Controllers\Warehouse;

use App\Enums\StockEntryStatus;
use App\Http\Controllers\Controller;
use App\Models\AccountCode;
use App\Models\Project;
use App\Models\ProjectWipEntry;
use App\Services\AccountingSettings;
use App\Models\ProductSerial;
use App\Models\StockEntry;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phiếu nhập kho thủ công — KHÔNG liên kết đơn mua hàng.
 *  - kind=purchase: mua của NCC, Có TK phải trả của NCC (331x), có VAT.
 *  - kind=project_return: nhập lại vật tư thừa từ dự án, Nợ 156 / Có 154 (project_id), không VAT, ghi giảm WIP.
 *  - kind=other   : nhập khác (mua lẻ trả tiền ngay, hàng tặng, thừa...), Có TK người dùng chọn, VAT tùy chọn.
 * Xác nhận / hủy / thu hồi / xóa dùng chung StockEntryController.
 */
class ManualStockEntryController extends Controller
{
    public function create(): Response
    {
        return $this->form(null);
    }

    public function store(Request $request): RedirectResponse
    {
        $data  = $this->validated($request, null);
        $entry = DB::transaction(function () use ($data) {
            $entry = StockEntry::create([
                'code' => $data['code'], 'created_by' => auth()->id(), 'status' => StockEntryStatus::Draft,
            ] + $this->header($data));
            $this->writeItems($entry, $data);
            return $entry;
        });

        return redirect()->route('warehouse.stock-entries.show', $entry)
            ->with('success', 'Đã tạo phiếu nhập kho thủ công.');
    }

    public function edit(StockEntry $stockEntry): Response|RedirectResponse
    {
        if ($redirect = $this->guardEditable($stockEntry)) return $redirect;
        return $this->form($stockEntry);
    }

    public function update(Request $request, StockEntry $stockEntry): RedirectResponse
    {
        if ($redirect = $this->guardEditable($stockEntry)) return $redirect;

        $data = $this->validated($request, $stockEntry);
        DB::transaction(function () use ($data, $stockEntry) {
            $oldItemIds = $stockEntry->items()->pluck('id');
            ProductSerial::whereIn('stock_entry_item_id', $oldItemIds)->delete();
            $stockEntry->items()->delete();
            $stockEntry->update(['code' => $data['code']] + $this->header($data));
            $this->writeItems($stockEntry, $data);
        });

        return redirect()->route('warehouse.stock-entries.show', $stockEntry)
            ->with('success', 'Đã cập nhật phiếu nhập kho.');
    }

    private function guardEditable(StockEntry $entry): ?RedirectResponse
    {
        if (!$entry->isManual()) {
            return redirect()->route('warehouse.stock-entries.edit', $entry);
        }
        if ($entry->status !== StockEntryStatus::Draft) {
            return redirect()->route('warehouse.stock-entries.show', $entry)
                ->with('error', 'Chỉ có thể sửa phiếu ở trạng thái nháp.');
        }
        return null;
    }

    private function form(?StockEntry $entry): Response
    {
        $entry?->load(['supplier', 'project', 'items.product', 'items.serials']);
        $account = $entry ? AccountCode::where('code', $entry->credit_account_code)->value('name') : null;

        return Inertia::render('Warehouse/StockEntries/ManualForm', [
            'nextCode'   => $entry?->code ?? StockEntry::nextCode(),
            'warehouses' => Warehouse::orderBy('name')->get(['id', 'name']),
            'entry'      => $entry ? [
                'id'                  => $entry->id,
                'kind'                => $entry->manualKind(),
                'project_id'          => $entry->project_id,
                'project_name'        => $entry->project ? "{$entry->project->code} - {$entry->project->name}" : null,
                'warehouse_id'        => $entry->warehouse_id,
                'supplier_id'         => $entry->supplier_id,
                'supplier_name'       => $entry->supplier?->name,
                'credit_account_code' => $entry->credit_account_code,
                'credit_account_name' => $account ? "{$entry->credit_account_code} - {$account}" : $entry->credit_account_code,
                'entry_date'          => $entry->entry_date->format('Y-m-d'),
                'notes'               => $entry->notes,
                'items'               => $entry->items->map(fn ($i) => [
                    'product_id'   => $i->product_id,
                    'product_name' => $i->product ? "{$i->product->code} - {$i->product->name}" : '',
                    'unit'         => $i->product?->unit,
                    'quantity'     => (int) $i->quantity,
                    'unit_price'   => (float) $i->unit_price,
                    'tax_rate'     => (float) $i->tax_rate,
                    'tax_amount'   => $i->tax_amount !== null ? (float) $i->tax_amount : null,
                    'serials'      => $i->serials->pluck('serial_number')->all(),
                ])->values(),
            ] : null,
        ]);
    }

    private function validated(Request $request, ?StockEntry $entry): array
    {
        $data = $request->validate([
            'kind'                => ['required', 'in:purchase,project_return,other'],
            'project_id'          => ['nullable', 'required_if:kind,project_return', 'exists:projects,id'],
            'code'                => ['required', 'string', 'max:50', Rule::unique('stock_entries', 'code')->ignore($entry?->id)],
            'entry_date'          => ['required', 'date'],
            'warehouse_id'        => ['required', 'exists:warehouses,id'],
            'supplier_id'         => ['nullable', 'required_if:kind,purchase', 'exists:suppliers,id'],
            'credit_account_code' => ['nullable', 'required_if:kind,other', 'string'],
            'notes'               => ['nullable', 'string', 'max:2000'],
            'items'               => ['required', 'array', 'min:1'],
            'items.*.product_id'  => ['required', 'exists:products,id'],
            'items.*.quantity'    => ['required', 'integer', 'min:1'],
            'items.*.unit_price'  => ['required', 'numeric', 'min:0'],
            'items.*.tax_rate'    => ['nullable', 'numeric', 'in:0,5,8,10'],
            'items.*.tax_amount'  => ['nullable', 'numeric', 'min:0'],
            'items.*.serials'     => ['nullable', 'array'],
            'items.*.serials.*'   => ['nullable', 'string', 'max:100'],
        ], [
            'supplier_id.required_if'         => 'Phiếu mua hàng phải chọn nhà cung cấp.',
            'project_id.required_if'          => 'Phiếu nhập lại từ dự án phải chọn dự án.',
            'credit_account_code.required_if' => 'Phiếu nhập khác phải chọn TK đối ứng (bên Có).',
            'items.required'                  => 'Phiếu nhập phải có ít nhất 1 dòng hàng.',
        ]);

        if ($data['kind'] === 'purchase') {
            try {
                $data['credit_account_code'] = Supplier::findOrFail($data['supplier_id'])->getPayableAccount();
            } catch (\RuntimeException $e) {
                throw ValidationException::withMessages(['supplier_id' => $e->getMessage()]);
            }
            if (!str_starts_with($data['credit_account_code'], '331')) {
                throw ValidationException::withMessages(['supplier_id' =>
                    "TK phải trả của NCC ({$data['credit_account_code']}) không thuộc nhóm 331."]);
            }
        } elseif ($data['kind'] === 'project_return') {
            $data['credit_account_code'] = AccountingSettings::get('project_wip_account', '154');
            $data['supplier_id'] = null;
            $this->assertProjectWipCovers((int) $data['project_id'], $data['items']);
        } else {
            $this->assertOtherCreditAccount($data['credit_account_code']);
        }
        if ($data['kind'] !== 'project_return') {
            $data['project_id'] = null;
        }

        $this->assertTaxAmounts($data);
        $this->assertSerials($data['items'], $entry);
        return $data;
    }

    /** Tiền VAT tự nhập không được vượt giá trị hàng chưa VAT của dòng. */
    private function assertTaxAmounts(array $data): void
    {
        if ($data['kind'] === 'project_return') return;
        foreach ($data['items'] as $i => $item) {
            $tax = $item['tax_amount'] ?? null;
            if ($tax === null || $tax === '') continue;
            $excl = (float) $item['quantity'] * (float) $item['unit_price'];
            if ((float) $tax > $excl) {
                throw ValidationException::withMessages(["items.{$i}.tax_amount" =>
                    'Tiền VAT không được lớn hơn giá trị hàng chưa VAT ('.number_format($excl, 0, ',', '.').' đ).']);
            }
        }
    }

    /** TK Có của "nhập khác": phải là TK chi tiết đang dùng, không phải 331 (dùng loại mua hàng) hay TK kho 15x (trừ 154). */
    private function assertOtherCreditAccount(string $code): void
    {
        $acc = AccountCode::where('code', $code)->first();
        $msg = match (true) {
            !$acc                           => 'TK đối ứng không tồn tại.',
            !$acc->is_detail                => 'TK đối ứng phải là TK chi tiết (không dùng TK tổng hợp).',
            $acc->is_active === false       => 'TK đối ứng đang ngừng sử dụng.',
            str_starts_with($code, '331')   => 'Nhập hàng của NCC (TK 331) hãy chọn loại "Mua hàng của NCC".',
            // TK 154 được phép: kế toán tự chịu trách nhiệm, không gắn dự án / không ghi giảm WIP.
            str_starts_with($code, '15') && !str_starts_with($code, '154')
                                            => 'TK đối ứng không được là TK hàng tồn kho (15x).',
            default                         => null,
        };
        if ($msg) throw ValidationException::withMessages(['credit_account_code' => $msg]);
    }

    /** Giá trị nhập lại không được vượt chi phí dở dang (WIP) còn lại của dự án — tránh 154 âm. */
    private function assertProjectWipCovers(int $projectId, array $items): void
    {
        $value = (int) round(array_sum(array_map(fn ($i) => (float) $i['quantity'] * (float) $i['unit_price'], $items)));
        $wip   = (int) ProjectWipEntry::where('project_id', $projectId)->where('status', 'active')->sum('amount');
        if ($value > $wip) {
            $code = Project::whereKey($projectId)->value('code');
            throw ValidationException::withMessages(['project_id' =>
                "Giá trị nhập lại (" . number_format($value, 0, ',', '.') . "đ) vượt chi phí dở dang còn lại của dự án {$code} ("
                . number_format($wip, 0, ',', '.') . 'đ).']);
        }
    }

    private function assertSerials(array $items, ?StockEntry $entry): void
    {
        $own = $entry
            ? ProductSerial::whereIn('stock_entry_item_id', $entry->items()->pluck('id'))->pluck('serial_number')->all()
            : [];
        $seen = [];
        $errors = [];
        foreach ($items as $idx => $item) {
            foreach (array_filter($item['serials'] ?? [], fn ($s) => $s !== '' && $s !== null) as $serial) {
                if (in_array($serial, $seen, true)) {
                    $errors["items.{$idx}.serials"] = "Số serial \"{$serial}\" bị trùng trong phiếu này.";
                } elseif (!in_array($serial, $own, true) && ProductSerial::where('serial_number', $serial)->exists()) {
                    $errors["items.{$idx}.serials"] = "Số serial \"{$serial}\" đã tồn tại trong hệ thống.";
                }
                $seen[] = $serial;
            }
            if (count($item['serials'] ?? []) > (int) $item['quantity']) {
                $errors["items.{$idx}.serials"] = 'Số serial nhiều hơn số lượng nhập.';
            }
        }
        if ($errors) throw ValidationException::withMessages($errors);
    }

    private function header(array $data): array
    {
        return [
            'warehouse_id'        => $data['warehouse_id'],
            'supplier_id'         => $data['supplier_id'] ?? null,
            'project_id'          => $data['project_id'] ?? null,
            'purchase_order_id'   => null,
            'credit_account_code' => $data['credit_account_code'],
            'entry_date'          => $data['entry_date'],
            'notes'               => $data['notes'] ?? null,
        ];
    }

    private function writeItems(StockEntry $entry, array $data): void
    {
        foreach ($data['items'] as $item) {
            // Nhập lại từ dự án không có VAT; mua NCC / nhập khác: kế toán chọn % VAT, có thể tự nhập tiền VAT
            $hasVat    = $data['kind'] !== 'project_return';
            $taxRate   = $hasVat ? (float) ($item['tax_rate'] ?? 0) : 0;
            $taxAmount = $hasVat && isset($item['tax_amount']) && $item['tax_amount'] !== '' ? (float) $item['tax_amount'] : null;
            $row = $entry->items()->create([
                'product_id' => $item['product_id'],
                'quantity'   => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'tax_rate'   => $taxRate,
                'tax_amount' => $taxAmount,
                'unit_cost'  => (float) $item['unit_price'],
            ]);
            foreach (array_filter($item['serials'] ?? [], fn ($s) => $s !== '' && $s !== null) as $serial) {
                ProductSerial::create([
                    'product_id' => $item['product_id'], 'warehouse_id' => $entry->warehouse_id,
                    'serial_number' => $serial, 'stock_entry_item_id' => $row->id, 'status' => 'in_stock',
                ]);
            }
        }
    }
}
