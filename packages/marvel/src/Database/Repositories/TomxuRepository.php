<?php

namespace Marvel\Database\Repositories;

use Carbon\Carbon;
use Marvel\Database\Models\Tomxu;

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
    public function deleteOneByProductId($productId)
    {
        return $this->model->where('product_id', $productId)->update(['deleted_at' => Carbon::now()]);
    }

    public function updateTomxu($id, $request)
    {
        if (isset($request['tomxu'])) {
            $product_tomxu = $this->model->where('product_id', $id)->first();
            $product_tomxu->price_tomxu = $request['tomxu'];
            $product_tomxu->save();
        }
    }
}
