<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class UsersBalance extends Model
{
    protected $table = 'users_balance';

    public $guarded = [];

    protected $casts = [
        'id' => 'json',
        'user_id' => 'json',
        'token_id' => 'json',
        'balance' => 'json',
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
