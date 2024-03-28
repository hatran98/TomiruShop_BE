<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
class OTP extends Model
{
    protected $table = 'users_otp';
    protected $fillable = [
        'user_id',
        'type',
        'otp',
        'expires_at',
        'status'
    ];

    protected $dates = [
        'expires_at'
    ];

    public function isExpired()
    {
        return Carbon::parse($this->expires_at)->isPast();
    }
}
