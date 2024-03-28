<?php

namespace Marvel\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Marvel\Database\Models\User;
use Marvel\Database\Models\UsersBalance;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
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
            return true;
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
            'products.*.quantity' => 'required',
            'products.*.tomxu' => 'required',
            'products.*.tomxu_subtotal' => 'required',
        ]);

        $isAuth = $this->checkAuth($validatedData['customer_id']);
        if(!$isAuth){
            return ['message' => 'Unauthorized','success' => false];
        }

        try {
            DB::transaction(function ()
            use ($validatedData)
            {
                $user = UsersBalance::where('user_id',$validatedData['customer_id'])
                    ->where('token_id', 1)
                    ->lockForUpdate()
                    ->first();
                $balanceUser =  $user->balance;
                //check balance
                if($balanceUser < floatval($validatedData['total_tomxu']) ){
                    throw new \Exception('Insufficient balance to complete the transaction');
                }
                $newBalance = $balanceUser - floatval($validatedData['total_tomxu']);
                $user->update([
                    'balance'=> $newBalance,
                    'updated_at' => now(),
                ]);
                $sendEmailController = new SendEmailController();
                $isOtp = $sendEmailController->verifyOtp($validatedData['type'], $validatedData['customer_id'], $validatedData['otp']);

                if(!$isOtp) {
                  throw new \Exception('Invalid OTP');
                }

                $order = Order::where('tracking_number',$validatedData['tracking_number'])->first();
                if($order){
                    $order->update([
                        'order_status'=> 'order-completed',
                        'payment_status'=>'payment-success',
                        'updated_at' => now(),
                        ]);
                }else {
                    Order::create([
                        'tracking_number' => floatval($validatedData['tracking_number']),
                        'customer_id' => floatval($validatedData['customer_id']),
                        'customer_contact' => floatval($validatedData['customer_contact']),
                        'customer_name' => $validatedData['customer_name'],
                        'total_tomxu' => $validatedData['total_tomxu'],
                        'order_status' => 'order-completed',
                        'payment_status' => 'payment-success',
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]);
                    foreach ($validatedData['products'] as $product) {
                        OrderProduct::create([
                            'order_id' => $order->id,
                            'product_id' => $product['product_id'],
                            'order_quantity' =>  $product['quantity'],
                            'tomxu' =>  $product['tomxu'],
                            'tomxu_subtotal' =>  $product['tomxu_subtotal'],
                            'updated_at' => now (),
                            'created_at' => now(),
                        ]);
                    }
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

            });
            DB::commit();

        } catch (\Exception $e) {
            DB::rollback();
            return ['message' => $e->getMessage(), 'success' => false];
        }

        return ['message' =>'Transaction success.', 'success' => true,
            'data'=> [
                'customer_id' => $validatedData['customer_id'],
                'total_tomxu' => $validatedData['total_tomxu'],
                ]
        ];
    }

    public function balanceTomxu(Request $request){
        $validatedData = $request->validate([
            'customer_id' => 'required',
            'type' => 'required'
        ]);
        $isAuth = $this->checkAuth($validatedData['customer_id']);
        if(!$isAuth){
            return ['message' => 'Unauthorized','success' => false];
        }
        $usersBalance = UsersBalance::where('user_id',$validatedData['customer_id'])
            ->where('token_id', $validatedData['type'])
            ->first();
        return  $usersBalance->balance;
    }
}


