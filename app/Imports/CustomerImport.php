<?php

namespace App\Imports;

use App\Models\Customer;
use App\Models\CustomerBankAccount;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\WithLimit;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Validators\Failure;

class CustomerImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnError, SkipsOnFailure, WithLimit
{
    public array $errors = [];
    public int $imported = 0;

    private array $pendingBanks = [];

    public function model(array $row): ?Customer
    {
        $this->imported++;

        $customer = new Customer([
            'code'        => Customer::generateCode(),
            'name'        => $row['name'],
            'phone'       => $row['phone'] ?? null,
            'email'       => $row['email'] ?? null,
            'address'     => $row['address'] ?? null,
            'tax_code'    => $row['tax_code'] ?? null,
            'notes'       => $row['notes'] ?? null,
            'lead_status' => 'new',
        ]);

        $customer->save();

        // Tạo bank account nếu có
        $bankName   = trim($row['bank_name'] ?? '');
        $accountNo  = trim($row['account_number'] ?? '');
        if ($bankName && $accountNo) {
            CustomerBankAccount::create([
                'customer_id'    => $customer->id,
                'bank_name'      => $bankName,
                'account_number' => $accountNo,
                'account_name'   => trim($row['account_name'] ?? $row['name']),
                'branch'         => trim($row['branch'] ?? '') ?: null,
                'is_primary'     => true,
            ]);
        }

        return null; // đã save thủ công
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email',
        ];
    }

    public function limit(): int
    {
        return 5000;
    }

    public function onError(\Throwable $e): void
    {
        $this->errors[] = $e->getMessage();
    }

    public function onFailure(Failure ...$failures): void
    {
        foreach ($failures as $f) {
            $this->errors[] = "Row {$f->row()}: " . implode(', ', $f->errors());
        }
    }
}
