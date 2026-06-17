<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\FundTransferStatus;
use App\Http\Controllers\Controller;
use App\Models\AccountCode;
use App\Models\Fund;
use App\Models\FundTransfer;
use App\Services\FundTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class FundTransferController extends Controller
{
    public function __construct(private FundTransferService $service) {}

    public function index(Request $request): Response
    {
        $query = FundTransfer::with('fromFund', 'toFund', 'creator')
            ->orderByDesc('transfer_date')
            ->orderByDesc('id');

        if ($s = $request->input('status')) {
            $query->where('status', $s);
        }

        $transfers = $query->paginate(25)->through(fn (FundTransfer $t) => [
            'id'            => $t->id,
            'transfer_no'   => $t->transfer_no,
            'transfer_date' => $t->transfer_date->format('d/m/Y'),
            'from_fund'     => $t->fromFund?->name,
            'to_fund'       => $t->toFund?->name,
            'amount'        => (float) $t->amount,
            'description'   => $t->description,
            'status'        => $t->status->value,
            'status_label'  => $t->status->label(),
            'status_color'  => $t->status->color(),
            'creator'       => $t->creator?->name,
        ]);

        return Inertia::render('Accounting/FundTransfers/Index', [
            'transfers' => $transfers,
            'filters'   => $request->only(['status']),
            'statuses'  => collect(FundTransferStatus::cases())->map(fn ($s) => [
                'value' => $s->value,
                'label' => $s->label(),
            ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Accounting/FundTransfers/Form', [
            'funds'    => $this->activeFunds(),
            'transfer' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'transfer_date' => 'required|date',
            'from_fund_id'  => 'required|exists:funds,id',
            'to_fund_id'    => 'required|exists:funds,id|different:from_fund_id',
            'amount'        => 'required|numeric|min:0.01',
            'description'   => 'nullable|string|max:500',
        ], [
            'to_fund_id.different' => 'Quỹ nguồn và quỹ đích không được trùng nhau.',
            'amount.min'           => 'Số tiền phải lớn hơn 0.',
        ]);

        $transfer = FundTransfer::create([
            'transfer_no'   => FundTransfer::generateNo(),
            'transfer_date' => $data['transfer_date'],
            'from_fund_id'  => $data['from_fund_id'],
            'to_fund_id'    => $data['to_fund_id'],
            'amount'        => $data['amount'],
            'description'   => $data['description'] ?? null,
            'status'        => FundTransferStatus::Draft,
            'created_by'    => auth()->id(),
        ]);

        return redirect()->route('accounting.fund-transfers.show', $transfer)
            ->with('success', "Phiếu luân chuyển {$transfer->transfer_no} đã được tạo.");
    }

    public function show(FundTransfer $fundTransfer): Response
    {
        $fundTransfer->load('fromFund', 'toFund', 'creator', 'poster', 'reverser', 'journalEntry');

        return Inertia::render('Accounting/FundTransfers/Show', [
            'transfer' => $this->dto($fundTransfer),
        ]);
    }

    public function post(FundTransfer $fundTransfer): RedirectResponse
    {
        try {
            $this->service->post($fundTransfer);
            return back()->with('success', "Phiếu {$fundTransfer->transfer_no} đã được ghi sổ.");
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reverse(Request $request, FundTransfer $fundTransfer): RedirectResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        try {
            $this->service->reverse($fundTransfer, $data['reason'] ?? '');
            return back()->with('success', "Phiếu {$fundTransfer->transfer_no} đã được đảo bút toán.");
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(FundTransfer $fundTransfer): RedirectResponse
    {
        try {
            $this->service->cancel($fundTransfer);
            return back()->with('success', "Phiếu {$fundTransfer->transfer_no} đã bị hủy.");
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(FundTransfer $fundTransfer): RedirectResponse
    {
        $allowed = [FundTransferStatus::Cancelled, FundTransferStatus::Reversed];
        if (!in_array($fundTransfer->status, $allowed)) {
            return back()->with('error', 'Chỉ có thể xóa phiếu đã hủy hoặc đã đảo bút toán.');
        }

        $no = $fundTransfer->transfer_no;
        $fundTransfer->delete();

        return redirect()->route('accounting.fund-transfers.index')
            ->with('success', "Phiếu {$no} đã được xóa.");
    }

    // ─── Private ───────────────────────────────────────────────────────────────

    private function activeFunds(): array
    {
        return Fund::where('is_active', true)
            ->orderBy('type')
            ->orderBy('name')
            ->get()
            ->map(fn (Fund $f) => [
                'id'           => $f->id,
                'name'         => $f->name,
                'code'         => $f->code,
                'type'         => $f->type,
                'type_label'   => $f->type === 'bank' ? 'Ngân hàng' : 'Tiền mặt',
                'account_code' => $f->account_code,
                'bank_name'    => $f->bank_name,
                'balance'      => $f->balance(),
            ])
            ->toArray();
    }

    private function dto(FundTransfer $t): array
    {
        return [
            'id'              => $t->id,
            'transfer_no'     => $t->transfer_no,
            'transfer_date'   => $t->transfer_date->format('Y-m-d'),
            'transfer_date_f' => $t->transfer_date->format('d/m/Y'),
            'from_fund_id'    => $t->from_fund_id,
            'from_fund'       => $t->fromFund ? ['id' => $t->fromFund->id, 'name' => $t->fromFund->name, 'type' => $t->fromFund->type, 'account_code' => $t->fromFund->account_code] : null,
            'to_fund_id'      => $t->to_fund_id,
            'to_fund'         => $t->toFund   ? ['id' => $t->toFund->id,   'name' => $t->toFund->name,   'type' => $t->toFund->type,   'account_code' => $t->toFund->account_code] : null,
            'amount'          => (float) $t->amount,
            'description'     => $t->description,
            'status'          => $t->status->value,
            'status_label'    => $t->status->label(),
            'status_color'    => $t->status->color(),
            'journal_entry'   => $t->journalEntry ? ['id' => $t->journalEntry->id, 'code' => $t->journalEntry->code] : null,
            'creator'         => $t->creator?->name,
            'poster'          => $t->poster?->name,
            'posted_at'       => $t->posted_at?->format('d/m/Y H:i'),
            'reverser'        => $t->reverser?->name,
            'created_at'      => $t->created_at->format('d/m/Y H:i'),
        ];
    }
}
