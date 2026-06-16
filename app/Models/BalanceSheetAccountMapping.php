<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BalanceSheetAccountMapping extends Model
{
    protected $fillable = ['account_code', 'item_code', 'created_by'];
}
