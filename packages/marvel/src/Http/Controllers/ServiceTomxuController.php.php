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
            'otp' => 'required',
        ]);

        $user = $this->checkAuth($validatedData['customer_id']);
        if(!$user){
            return ['message' => 'Unauthorized','success' => false];
        }
        $order = Order::where('tracking_number',$validatedData['tracking_number'])->first();
        if(!$order){
            return ['message' => 'Order not exist','success' => false];
        }
        $products = $order->products;
        if($order->payment_status == 'payment-success'){
            return ['message' => 'The order has been paid','success' => false];
        }
        try {
            DB::transaction(function ()
            use ($validatedData,$user,$order,$products)
            {
                //verify otp
                $sendEmailController = new SendEmailController();
                $isOtp = $sendEmailController->verifyOtp('verify_order', $validatedData['customer_id'], $validatedData['otp']);
                if(!$isOtp) {
                    throw new \Exception('Invalid OTP');
                }
                //check balance
                $userBalance = UsersBalance::where('user_id',$validatedData['customer_id'])
                    ->where('token_id', 1)
                    ->lockForUpdate()
                    ->first();
                $balanceUser = $userBalance->balance;
                $totalTomxu = $order->total_tomxu;
                if(floatval($balanceUser) < floatval($totalTomxu) ){
                    throw new \Exception('Insufficient balance to complete the transaction');
                }
                $newBalance = floatval($balanceUser) - floatval($totalTomxu);
                //create transaction
                UsersTransaction::create([
                    'type' => 29,
                    'user_id' => floatval($validatedData['customer_id']),
                    'token_id' =>  1,
                    'status' =>  'success',
                    'value' =>  $totalTomxu,
                    'pre_balance' => $balanceUser,
                    'post_balance' => $newBalance,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);
                // minus for customer
                $userBalance->update([
                    'balance'=> $newBalance,
                    'updated_at' => now(),
                ]);
                //update order
                $order->update([
//                    'order_status'=> 'order-completed',
                    'payment_status'=>'payment-success',
                    'updated_at' => now(),
                ]);
                $totalTomxuFromProducts = 0;
                //add tomxu for shop pivot
                foreach ($products as $product) {

                    $balanceShop =  Balance::where('shop_id',$product->shop_id)->first();
                    $current_balance= $balanceShop->current_balance;
                    $addTomxu = $product->pivot['tomxu_subtotal'];
                    $totalTomxuFromProducts += floatval($addTomxu);
                        $balanceShop->update([
                        'current_balance'=> floatval($current_balance) + floatval($addTomxu),
                        'updated_at' => now(),
                    ]);
                }
                if($totalTomxu != $totalTomxuFromProducts){
                    throw new \Exception('Total tomxu of order and total tomxu of products not match');
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

