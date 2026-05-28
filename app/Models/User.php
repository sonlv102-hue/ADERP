<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'password', 'phone', 'avatar',
        'is_active', 'base_salary', 'allowance',
        'dependents_count', 'pit_tax_code',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'  => 'datetime',
            'password'           => 'hashed',
            'is_active'          => 'boolean',
            'base_salary'        => 'decimal:2',
            'allowance'          => 'decimal:2',
            'dependents_count'   => 'integer',
        ];
    }
}
