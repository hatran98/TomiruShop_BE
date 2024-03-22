<?php

namespace Marvel\Database\Repositories;

use Marvel\Database\Models\Tomxu;
use Prettus\Repository\Contracts\RepositoryInterface;

class TomxuRepository extends BaseRepository
{
    public function model()
    {
        return Tomxu::class;
    }

    public function getOneByProductId($productId)
    {
        $result = $this->model->where('product_id', $productId)->pluck('price_tomxu')->first();
        return $result;
    }

}
