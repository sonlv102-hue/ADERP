<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmallToolIssueItem extends Model
{
    protected $fillable = ['small_tool_issue_id', 'small_tool_id', 'quantity', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function issue(): BelongsTo { return $this->belongsTo(SmallToolIssue::class, 'small_tool_issue_id'); }
    public function tool(): BelongsTo  { return $this->belongsTo(SmallTool::class, 'small_tool_id'); }
}
