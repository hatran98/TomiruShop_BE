<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class UsersOtp extends Model
{
    protected $table = 'users_otp';

    public $guarded = [];

    protected $casts = [
        'user_id' => 'json',
        'otp' => 'json',
        'type' => 'json',
    ];

//    public $timestamps = true;

    /**
     * @return BelongsTo
     */

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
