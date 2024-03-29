<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class  UsersTransaction extends Model
{
    protected $table = 'users_transaction';

    public $guarded = [];

    protected $casts = [
        'type' => 'json',
        'user_id' => 'json',
        'token_id' => 'json',
        'from_id' => 'json',
        'to_id' => 'json',
        'message' => 'json',
        'value' => 'json',
        'fee' => 'json',
        'pre_balance' => 'json',
        'post_balance' => 'json',

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
