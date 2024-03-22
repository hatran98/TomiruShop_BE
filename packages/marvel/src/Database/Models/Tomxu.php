<?php
namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

class Tomxu extends Model {
    protected $table = 'product_tomxu';
    protected $fillable = [
        'product_id',
        'price_tomxu',
    ];
}
