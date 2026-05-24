<?php

namespace App\Models;

use App\Enums\LeadStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'company', 'tax_code', 'phone', 'email',
        'address', 'lead_status', 'assigned_to', 'notes',
    ];

    protected function casts(): array
    {
        return ['lead_status' => LeadStatus::class];
    }

    public static function generateCode(): string
    {
        $last = self::withTrashed()->orderByDesc('id')->value('code');
        $num = $last ? ((int) substr($last, 3)) + 1 : 1;
        return 'KH-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function primaryContact()
    {
        return $this->contacts()->where('is_primary', true)->first();
    }
}
