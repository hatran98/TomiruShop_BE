<?php

namespace Marvel\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Marvel\Database\Models\User;
use Marvel\Database\Models\UsersBalance;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\Balance;
use Marvel\Database\Models\UsersOtp;
use Carbon\Carbon;
use Marvel\Database\Models\UsersTransaction;
use Marvel\Http\Controllers\SendEmailController;
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
    public function transaction(Request $request)
    {
        $validatedData = $request->validate([
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

//        return $validatedData['products'];
        $user = $this->checkAuth($validatedData['customer_id']);
        if(!$user){
            return ['message' => 'Unauthorized','success' => false];
        }

        try {
            DB::transaction(function ()
            use ($validatedData,$user)
            {
                $userBalance = UsersBalance::where('user_id',$validatedData['customer_id'])
                    ->where('token_id', 1)
                    ->lockForUpdate()
                    ->first();
                $balanceUser = $userBalance->balance;
                //check balance of customer and minus
                if($balanceUser < floatval($validatedData['total_tomxu']) ){
                    throw new \Exception('Insufficient balance to complete the transaction');
                }
                $newBalance = floatval($balanceUser) - floatval($validatedData['total_tomxu']);
                $userBalance->update([
                    'balance'=> $newBalance,
                    'updated_at' => now(),
                ]);
                //verify otp
                $sendEmailController = new SendEmailController();
                $isOtp = $sendEmailController->verifyOtp($validatedData['type'], $validatedData['customer_id'], $validatedData['otp']);
                if(!$isOtp) {
                  throw new \Exception('Invalid OTP');
                }
                //update order
                $order = Order::where('tracking_number',$validatedData['tracking_number'])->first();
                if($order){
                    $order->update([
                        'order_status'=> 'order-completed',
                        'payment_status'=>'payment-success',
                        'updated_at' => now(),
                        ]);
                    //add tomxu for shop
                    foreach ($validatedData['products'] as $product) {
                        $balanceShop =  Balance::where('shop_id',$product['shop_id'])->first();
                        $current_balance= $balanceShop->current_balance;
                        $addTomxu = floatval($product['tomxu']) * $product['quantity'];
                        $balanceShop->update([
                            'current_balance'=> floatval($current_balance) + $addTomxu,
                            'updated_at' => now(),
                        ]);
                    }
                } else {
                    //if
//                    Order::create([
//                        'tracking_number' => floatval($validatedData['tracking_number']),
//                        'customer_id' => floatval($validatedData['customer_id']),
//                        'customer_contact' => floatval($validatedData['customer_contact']),
//                        'customer_name' => $validatedData['customer_name'],
//                        'total_tomxu' => $validatedData['total_tomxu'],
//                        'order_status' => 'order-completed',
//                        'payment_status' => 'payment-success',
//                        'updated_at' => now(),
//                        'created_at' => now(),
//                    ]);
//                    foreach ($validatedData['products'] as $product) {
//                        $balanceShop =  Balance::where('shop_id',$product['shop_id'])->first();
//                        $current_balance= $balanceShop->current_balance;
//                        $addTomxu = floatval($product['tomxu']) * $product['quantity'];
//                        $balanceShop->update([
//                            'current_balance'=> floatval($current_balance) + $addTomxu,
//                            'updated_at' => now(),
//                        ]);
//                        OrderProduct::create([
//                            'order_id' => $order->id,
//                            'product_id' => $product['product_id'],
//                            'order_quantity' =>  $product['quantity'],
//                            'tomxu' =>  $product['tomxu'],
//                            'tomxu_subtotal' =>  $product['tomxu_subtotal'],
//                            'updated_at' => now (),
//                            'created_at' => now(),
//                        ]);
//                    }
                }
                UsersTransaction::create([
                    'type' => 29,
                    'user_id' => floatval($validatedData['customer_id']),
                    'token_id' =>  1,
                    'status' =>  'success',
                    'value' =>  floatval($validatedData['total_tomxu']),
                    'pre_balance' => $balanceUser,
                    'post_balance' => $newBalance,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);

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
                  'total_tomxu' => $validatedData['total_tomxu'],
              ]
        ];
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
}

