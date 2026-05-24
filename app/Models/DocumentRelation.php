<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRelation extends Model
{
    protected $fillable = ['document_id', 'related_type', 'related_id', 'related_label'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function relatedTypeLabel(): string
    {
        return Document::relatedTypes()[$this->related_type] ?? $this->related_type;
    }
}
