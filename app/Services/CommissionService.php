<?php

namespace App\Services;

use App\Enums\CommissionStatus;
use App\Models\Commission;
use Illuminate\Support\Facades\Auth;

class CommissionService
{
    public function submit(Commission $commission): void
    {
        $this->assertStatus($commission, CommissionStatus::Draft);
        $commission->update(['status' => CommissionStatus::PendingL1]);
    }

    public function approveL1(Commission $commission): void
    {
        $this->assertStatus($commission, CommissionStatus::PendingL1);
        $commission->update([
            'status'       => CommissionStatus::PendingL2,
            'approver1_id' => Auth::id(),
            'approved1_at' => now(),
        ]);
    }

    public function approveL2(Commission $commission): void
    {
        $this->assertStatus($commission, CommissionStatus::PendingL2);
        $commission->update([
            'status'       => CommissionStatus::PendingPayment,
            'approver2_id' => Auth::id(),
            'approved2_at' => now(),
        ]);
    }

    public function reject(Commission $commission, string $reason): void
    {
        if (!in_array($commission->status->value, ['pending_l1', 'pending_l2'])) {
            throw new \RuntimeException('Chỉ có thể từ chối khi đang chờ duyệt.');
        }
        $commission->update([
            'status'        => CommissionStatus::Rejected,
            'reject_reason' => $reason,
        ]);
    }

    public function pay(Commission $commission, string $paidDate): void
    {
        $this->assertStatus($commission, CommissionStatus::PendingPayment);
        $commission->update([
            'status'    => CommissionStatus::Paid,
            'payer_id'  => Auth::id(),
            'paid_at'   => now(),
            'paid_date' => $paidDate,
        ]);
    }

    public function cancel(Commission $commission): void
    {
        $cancellable = [
            CommissionStatus::Draft->value,
            CommissionStatus::PendingL1->value,
            CommissionStatus::PendingL2->value,
        ];
        if (!in_array($commission->status->value, $cancellable)) {
            throw new \RuntimeException('Không thể hủy khoản hoa hồng ở trạng thái này.');
        }
        $commission->update(['status' => CommissionStatus::Cancelled]);
    }

    private function assertStatus(Commission $commission, CommissionStatus $expected): void
    {
        if ($commission->status !== $expected) {
            throw new \RuntimeException("Trạng thái hiện tại không cho phép thao tác này.");
        }
    }
}
