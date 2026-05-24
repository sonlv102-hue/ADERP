<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = [
        'code', 'name', 'customer_id', 'contract_id', 'location',
        'manager_id', 'start_date', 'expected_end_date', 'actual_end_date',
        'budget', 'status', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status'           => ProjectStatus::class,
            'start_date'       => 'date',
            'expected_end_date'=> 'date',
            'actual_end_date'  => 'date',
            'budget'           => 'decimal:2',
        ];
    }

    public static function generateCode(): string
    {
        $last = self::orderByDesc('id')->value('code');
        $num = $last ? ((int) substr($last, 3)) + 1 : 1;
        return 'DA-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    public function totalExpenses(): float
    {
        return (float) $this->expenses->sum('amount');
    }

    public function totalMaterialCost(): float
    {
        return (float) $this->materials->sum(fn ($m) => $m->quantity * $m->unit_price);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class)->orderBy('sort_order');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(ProjectMaterial::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(ProjectExpense::class);
    }
}
