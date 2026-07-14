<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectSubcontractAcceptance extends Model
{
    protected $table = 'project_subcontract_acceptances';

    protected $fillable = [
        'subcontract_id', 'project_id', 'acceptance_no', 'acceptance_date', 'description',
        'amount_before_vat', 'vat_rate', 'vat_amount', 'total_amount',
        'invoice_no', 'invoice_date', 'journal_entry_id', 'project_wip_entry_id',
        'status', 'cancel_reason', 'cancelled_by', 'cancelled_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'acceptance_date'   => 'date',
            'invoice_date'      => 'date',
            'amount_before_vat' => 'decimal:2',
            'vat_rate'          => 'decimal:2',
            'vat_amount'        => 'decimal:2',
            'total_amount'      => 'decimal:2',
            'cancelled_at'      => 'datetime',
        ];
    }

    public function subcontract(): BelongsTo
    {
        return $this->belongsTo(ProjectSubcontract::class, 'subcontract_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function wipEntry(): BelongsTo
    {
        return $this->belongsTo(ProjectWipEntry::class, 'project_wip_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
