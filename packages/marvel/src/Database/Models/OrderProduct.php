<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;


class OrderProduct extends Model
{


    protected $table = 'order_product';

    public $guarded = [];

    protected $casts = [
        'order_id'    => 'json',
        'product_id'     => 'json',
        'order_quantity' => 'json',
        'tomxu' => 'json',
        'tomxu_subtotal' => 'json',
    ];


    /**
     * @return BelongsTo
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
    /**
     * @return HasOne
     */
    public function product()
    {
        return $this->hasOne(Product::class, 'product_id');
    }
}
