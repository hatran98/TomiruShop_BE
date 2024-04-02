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
    ];

    public function isExpired()
    {
        return Carbon::parse($this->created_at)->isPast();
    }
}
