<?php

namespace Marvel\Database\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


class ShopTransaction extends Model
{

    /**
     * @return BelongsTo
     */
    protected $table = 'shop_transaction';
    protected $fillable = ['shop_id', 'value', 'type', 'status', 'created_at', 'updated_at' , 'token_id', 'pre_balance', 'post_balance' ,'from_id','to_id'];


    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }
}
