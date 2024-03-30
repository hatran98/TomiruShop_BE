<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

class UserOtpCard extends Model
{
    protected $table = 'user_otp_card';

    protected $fillable = [
        'user_id',
        'card_serial',
        'issue_at',
        'expire_at',
        'status',
        'card_token',
        'created_at',
        'updated_at',
    ];
}

