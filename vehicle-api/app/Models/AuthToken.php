<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class AuthToken extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'auth_tokens';

    protected $fillable = [
        'email',
        'token_hash',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    protected $hidden = [
        'token_hash',
    ];
}
