<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class OtpCode extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'otp_codes';

    protected $fillable = [
        'email',
        'code_hash',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}