<?php

namespace App\Services;

use App\Enums\LeadStatus;
use App\Models\Customer;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;

class LeadService
{
    private const VALID_TRANSITIONS = [
        'new'        => ['contacted', 'lost'],
        'contacted'  => ['qualified', 'lost'],
        'qualified'  => ['proposal', 'lost'],
        'proposal'   => ['negotiation', 'won', 'lost'],
        'negotiation'=> ['won', 'lost'],
        'won'        => [],
        'lost'       => [],
    ];

    public function convertToCustomer(Lead $lead, array $data): Customer
    {
        return DB::transaction(function () use ($lead, $data) {
            $customer = Customer::create([
                'code'        => Customer::generateCode(),
                'name'        => $data['name'] ?? $lead->full_name,
                'company'     => $data['company'] ?? $lead->company_name,
                'phone'       => $data['phone'] ?? $lead->phone,
                'email'       => $data['email'] ?? $lead->email,
                'lead_status' => LeadStatus::Won->value,
                'assigned_to' => $lead->assigned_to,
                'notes'       => $lead->notes,
            ]);

            $lead->update([
                'status'               => LeadStatus::Won->value,
                'converted_customer_id'=> $customer->id,
            ]);

            return $customer;
        });
    }

    public function transition(Lead $lead, LeadStatus $to): void
    {
        $from = $lead->status->value;
        $allowed = self::VALID_TRANSITIONS[$from] ?? [];

        if (! in_array($to->value, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Cannot transition lead from [{$from}] to [{$to->value}]."
            );
        }

        $lead->update(['status' => $to->value]);
    }
}
