<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Một lần chạy audit "Kiểm soát sai lệch tồn kho và kế toán" (chỉ đọc dữ liệu nghiệp vụ). */
class InventoryAuditRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'summary' => 'array'];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function lastSuccess(): ?self
    {
        return static::where('status', 'success')->orderByDesc('id')->first();
    }
}
