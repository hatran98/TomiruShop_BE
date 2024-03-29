<?php

namespace Marvel\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Tomxu;
use Marvel\Database\Models\UsersBalance;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\Balance;
use Marvel\Database\Models\UsersTransaction;
use Marvel\Database\Models\ShopTransaction;
class ServiceTomxuController extends CoreController
{
    public function checkAuth($customer_id){
        $users = Auth::user();
        if(!$users || $users->id != $customer_id){
            return false;
        } else {
            return $users;
        }
    }
    public function transaction1(Request $request)
    {
        $validatedData = $this->validateData($request);
        $user = $this->checkAuth($validatedData['customer_id']);
        if (!$user) {
            return ['message' => 'Unauthorized', 'success' => false];
        }
        if($user['is_active'] != 1){
            return ['message' => 'User not active', 'success' => false];
        }
        // Tạo một thể hiện của SendEmailController check otp
        $sendEmailController = new SendEmailController();
        // Kiểm tra và xác nhận OTP từ SendEmailController
        $otpValid = $sendEmailController->verifyOtp($validatedData['type'], $validatedData['customer_id'], $validatedData['otp']);
//        if (!$otpValid) {
//            return ['message' => 'Invalid OTP', 'success' => false];
//        }
        // Lấy số dư của các cửa hàng
        $shopBalances = $this->getShopBalances($validatedData['products']);

        try {
            DB::transaction(function () use ($validatedData, $user, $shopBalances) {
//                // Cập nhật số dư của người dùng
                $userBalance = $this->updateUserBalance($validatedData);
//
//                // Xử lý đơn hàng
                $order = $this->processOrder($validatedData);
//
//                // Tạo giao dịch cho từng cửa hàng
                $this->createShopTransactions($validatedData, $shopBalances );
//
//                // Tạo giao dịch cho người dùng
//                $this->createUserTransaction($validatedData, $userBalance, $user);
//
//                // Gửi email thông báo
//                $this->sendEmailNotification($user, $order);
//
//                return $order;
            });
            DB::commit();
//
        } catch (\Exception $e) {
            DB::rollback();
            return ['message' => $e->getMessage(), 'success' => false];
        }

        return [
                'message' =>'Transaction success.',
                'success' => true,
                'data'=> [
                    'customer_id' => $validatedData['customer_id'],
                    'customer_name' => $validatedData['customer_name'],
                    'customer_contact' => $validatedData['customer_contact'],
                    'tracking_number' => $validatedData['tracking_number'],
                    'total_tomxu' => $validatedData['total_tomxu'],
                ]
            ];

    }

    public function transaction(Request $request)
    {
        $validatedData = $request->validate([
            'type'=> 'required' ,
            'tracking_number' => 'required',
            'customer_id' => 'required',
            'customer_contact' => 'required',
            'customer_name' => 'required',
            'otp' => 'required',
        ]);
        //verify otp
//        $sendEmailController = new SendEmailController();
//        $isOtp = $sendEmailController->verifyOtp('verify_order', $validatedData['customer_id'], $validatedData['otp']);
//        if(!$isOtp) {
//            return ['message' => 'Invalid OTP','success' => false];
//        }
        $order = Order::where('tracking_number',$validatedData['tracking_number'])->first();
        if(!$order){
            return ['message' => 'Order not exist','success' => false];
        }
        if($order->payment_status == 'payment-success'){
            return ['message' => 'The order has been paid','success' => false];
        }
        $user = $this->checkAuth($validatedData['customer_id']);
        if(!$user){
            return ['message' => 'Unauthorized','success' => false];
        }
        if($user['is_active'] != 1){
            return ['message' => 'User not active', 'success' => false];
        }
        $products = $order->products;
//        return $products;
        try {
            DB::transaction(function ()
            use ($validatedData,$user,$order,$products)
            {

                //check balance
                $userBalance = UsersBalance::where('user_id',$validatedData['customer_id'])
                    ->where('token_id', 1)
                    ->first();
                $balanceUser = $userBalance->balance;
                $totalTomxu = $order->total_tomxu;
                if(floatval($balanceUser) < floatval($totalTomxu) ){
                    throw new \Exception('Insufficient balance to complete the transaction');
                }

                //update order
                $order->update([
                    'payment_status'=>'payment-success',
                    'updated_at' => now(),
                ]);
                //add tomxu for shop pivot
                foreach ($products as $product) {
                    $shop = Shop::where('id',$product->shop_id)->firstOrFail();
                    $updateProduct = Product::where('id',$product->pivot->product_id)
                        ->lockForUpdate()
                        ->first();
                    $userId = $shop->owner_id;
                    $user = User::where('id',$userId)->firstOrFail();
                    if($user['is_active'] != 1){
                         throw new \Exception('Receive not active');
                    }
                    if($userId ===  $validatedData['customer_id']){
                        throw new \Exception('Invalid transaction');
                    }
                    $price = $product->pivot->tomxu_subtotal;
                    //nguowif nhan
                    $userBalanceReceive = UsersBalance::where('user_id',$userId)
                        ->where('token_id', 1)
                        ->lockForUpdate()
                        ->first();
                    $balanceUserReceive= $userBalanceReceive->balance;
                    $newBalanceUserReceive = floatval($balanceUserReceive) + floatval($price);
                    //nguoi gui
                    $userBalanceSend = UsersBalance::where('user_id', $validatedData['customer_id'])
                        ->where('token_id', 1)
                        ->lockForUpdate()
                        ->first();
                    $balanceUserSend= $userBalanceReceive->balance;
                    $newBalanceUserSend = $balanceUserSend - floatval($price);
                    ///update
                    UsersTransaction::create([
                       'type' => 'payment_order_tomxu',
                       'user_id' => floatval($validatedData['customer_id']),
                       'token_id' =>  1,
                       'from_id'=>floatval($validatedData['customer_id']),
                       'to_id'=>$userId,
                       'status' =>  'success',
                       'value' =>  $price,
                       'pre_balance' => $balanceUserSend,
                       'post_balance' => $newBalanceUserSend,
                       'updated_at' => now(),
                       'created_at' => now(),
                     ]);
                    UsersTransaction::create([
                        'type' => 'payment_order_tomxu',
                        'user_id' => $userId,
                        'token_id' =>  1,
                        'from_id'=>floatval($validatedData['customer_id']),
                        'to_id'=>$userId,
                        'status' =>  'success',
                        'value' =>  $price,
                        'pre_balance' => $balanceUserReceive,
                        'post_balance' => $newBalanceUserReceive,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]);

                    $userBalanceReceive->update([
                       'balance'=> $newBalanceUserReceive,
                       'updated_at' => now(),
                    ]);
                    $userBalanceSend->update([
                        'balance'=> $newBalanceUserSend,
                        'updated_at' => now(),
                    ]);

                }

                $sendEmail = new SendEmailController();
                $content = "<h3>Xin chào $user->name </h3>
                            <p> Mã đơn hàng: $order->tracking_number </p>
                            <p> Đã thanh toán thành công </p>";
                $sendEmail->sendOrderTomxu($user->email,$content);
            });
            DB::commit();

        } catch (\Exception $e) {
            DB::rollback();
            return ['message' => $e->getMessage(), 'success' => false];
        }
        return [
            'message' =>'Transaction success.',
            'success' => true,
            'data'=> [
                'customer_id' => $validatedData['customer_id'],
                'customer_name' => $validatedData['customer_name'],
                'customer_contact' => $validatedData['customer_contact'],
                'tracking_number' => $validatedData['tracking_number'],
                'total_tomxu' => $order->total_tomxu,
            ]
        ];
    }

    private function validateData(Request $request)
    {
        return $request->validate([
            'type'=> 'required' ,
            'tracking_number' => 'required',
            'customer_id' => 'required',
            'customer_contact' => 'required',
            'customer_name' => 'required',
            'total_tomxu' => 'required',
            'otp' => 'required',
            'products' => 'required|array',
            'products.*.product_id' => 'required',
            'products.*.shop_id' => 'required',
            'products.*.quantity' => 'required',
            'products.*.tomxu' => 'required',
            'products.*.tomxu_subtotal' => 'required',
        ]);
    }


    private function updateUserBalance($validatedData)
    {
        $userBalance = UsersBalance::where('user_id', $validatedData['customer_id'])
            ->where('token_id', 1)
            ->lockForUpdate()
            ->firstOrFail();
        $balanceUser = floatval($userBalance->balance);
        if ($balanceUser < floatval($validatedData['total_tomxu'])) {
            throw new \Exception('Insufficient balance to complete the transaction');
        }
        $newBalance = floatval($balanceUser) - floatval($validatedData['total_tomxu']);
        $userBalance->update([
            'balance' => $newBalance,
            'updated_at' => now(),
        ]);
        return $userBalance;
    }

    private function createUserTransaction($validatedData, $userBalance, $user)
    {
        $newBalance = floatval($userBalance->balance) - floatval($validatedData['total_tomxu']);

        UsersTransaction::create([
            'type' => 29,
            'user_id' => floatval($validatedData['customer_id']),
            'token_id' => 1,
            'status' => 'success',
            'value' => floatval($validatedData['total_tomxu']),
            'pre_balance' => $userBalance->balance,
            'post_balance' => $newBalance,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    private function processOrder($validatedData)
    {

        $order = Order::where('tracking_number', $validatedData['tracking_number'])
            ->where('order_status', 'order-pending')
            ->where('payment_status', 'payment-pending')
            ->firstOrFail();
        $orderProducts = $order->products;
        if (count($orderProducts) !== count($validatedData['products'])) {
            throw new \Exception('Number of products does not match');
        }

        foreach ($validatedData['products'] as $product) {
            $shop = Shop::where('id',$product['shop_id'])->firstOrFail();
            if($shop->is_active != 1){
                throw new \Exception('Shop not active');
            }
            $productDB = Product::where('id', $product['product_id'])->firstOrFail();
            $orderProduct = $orderProducts->firstWhere('id', $product['product_id']);
            if (!$orderProduct) {
                throw new \Exception('Product not found in the order');
            }
            $tomxu = Tomxu::where('product_id', $orderProduct->id)->firstOrFail();
            $tomxuPrice = $tomxu->price_tomxu;
            if ($tomxuPrice != $product['tomxu'] || $orderProduct->pivot->order_quantity != $product['quantity']) {
                throw new \Exception('Product details do not match');
            }
            if ($product['quantity'] > $orderProduct->quantity) {
                throw new \Exception('Order quantity exceeds available inventory');
            }
            $balanceShop = Balance::where('shop_id', $product['shop_id'])->firstOrFail();
            $current_balance = $balanceShop->current_balance;
            $addTomxu = floatval($product['tomxu']) * $product['quantity'];
            $balanceShop->update([
                'current_balance' => floatval($current_balance) + $addTomxu,
                'updated_at' => now(),
            ]);
            $productDB->update([
                'quantity' => floatval($productDB -> quantity)- floatval($product['quantity']),
                'updated_at' => now(),
                'sold_quantity' => floatval($productDB->sold_quantity) + floatval($product['quantity']),
            ]);
        }

        $order->update([
            'order_status' => 'order-completed',
            'payment_status' => 'payment-success',
            'updated_at' => now(),
        ]);



        return $order;
    }


    private function createShopTransactions($validatedData, $pre_balance)
    {
        $processedShopIds = [];
        foreach ($validatedData['products'] as $product) {
            $shopId = $product['shop_id'];
            if (in_array($shopId, $processedShopIds)) {
                continue;
            }
            $change_in_balance = 0;
            foreach ($validatedData['products'] as $item) {
                if ($item['shop_id'] === $shopId) {
                    $change_in_balance += floatval($item['tomxu']) * $item['quantity'];
                }
            }
            $post_balance = $pre_balance[$shopId] + $change_in_balance;
            ShopTransaction::create([
                'shop_id' => $shopId,
                'value' => $change_in_balance,
                'token_id' => 1,
                'from_id' => $validatedData['customer_id'],
                'to_id' => $shopId,
                'pre_balance' => $pre_balance[$shopId],
                'post_balance' => $post_balance,
                'type' => 29,
                'status' => 'success',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $processedShopIds[] = $shopId;
        }
    }


    private function sendEmailNotification($user, $order)
    {
        $content = "<h3>Xin chào $user->name </h3>
            <p> Mã đơn hàng: $order->tracking_number </p>
            <p> Đã thanh toán thành công </p>";
        (new SendEmailController())->sendOrderTomxu($user->email, $content);
    }

    public function balanceTomxu(Request $request){

        $validatedData = $request->validate([
            'customer_id' => 'required',
            'type' => 'required'
        ]);
        $user = $this->checkAuth($validatedData['customer_id']);
        if(!$user){
            return ['message' => 'Unauthorized','success' => false];
        }
        $usersBalance = UsersBalance::where('user_id',$validatedData['customer_id'])
            ->where('token_id', $validatedData['type'])
            ->first();
        return  [
            'message' =>'Get data success',
            'success' => true,
            'data'=> [
                'id' => $validatedData['customer_id'],
                'balance'=> $usersBalance->balance,
            ]
        ];
    }

    private function getShopBalances($products)
    {
        $shopBalances = [];
        foreach ($products as $product) {
            $shopId = $product['shop_id'];
            $balance = Balance::where('shop_id', $shopId)->value('current_balance');
            $shopBalances[$shopId] = $balance;
        }

        return $shopBalances;
    }
}
