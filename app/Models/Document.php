<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    protected $fillable = [
        'code', 'document_type_id', 'title',
        'file_path', 'file_name', 'file_type', 'file_size',
        'issued_date', 'expired_date', 'status', 'note', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'status'       => DocumentStatus::class,
            'issued_date'  => 'date',
            'expired_date' => 'date',
        ];
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function relations(): HasMany
    {
        return $this->hasMany(DocumentRelation::class);
    }

    public function getFileUrlAttribute(): ?string
    {
        return $this->file_path ? Storage::url($this->file_path) : null;
    }

    public function getFileSizeHumanAttribute(): string
    {
        if (!$this->file_size) return '';
        $kb = $this->file_size / 1024;
        if ($kb < 1024) return round($kb, 1) . ' KB';
        return round($kb / 1024, 2) . ' MB';
    }

    public static function generateCode(): string
    {
        $last = self::orderByDesc('id')->value('code');
        $num  = $last ? ((int) substr($last, 3)) + 1 : 1;
        return 'CT-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    public static function relatedTypes(): array
    {
        return [
            'order'          => 'Đơn hàng',
            'customer'       => 'Khách hàng',
            'project'        => 'Dự án',
            'contract'       => 'Hợp đồng',
            'purchase_order' => 'Đơn mua hàng',
            'invoice'        => 'Hóa đơn',
            'ticket'         => 'Ticket kỹ thuật',
            'warranty'       => 'Bảo hành',
        ];
    }
}
