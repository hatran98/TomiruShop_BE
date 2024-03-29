<?php

namespace Marvel\Http\Controllers;

use DateTime;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Marvel\Database\Models\User;
use Marvel\Database\Models\UsersBalance;
use Marvel\Database\Models\UsersTransaction;
use Marvel\Database\Models\UsersOtp;
use Carbon\Carbon;
class PaymentTomxuController extends CoreController
{
    public function transaction(Request $request)
    {
        $validatedData = $request->validate([
            'user_id' => 'required',
            'from_id' => 'required',
            'to_id' => 'required',
            'message' => 'required',
            'value' => 'required',
            'otp' => 'required',
        ]);

        $abc =
        //check user
        $users = Auth::user();
        if(!$users || $users->id != $validatedData['user_id']){
            return ['message' => 'Unauthorized','success' => false];
        }
        $userReceive  = User::find($validatedData['to_id']);
        if(!$userReceive){
            return ['message' => 'Recipient does not exist', 'success' => false];
        }
        //verify otp
        $sendEmailController = new SendEmailController();
        $isOtp = $sendEmailController->verifyOtp('verify_send_token', $validatedData['user_id'], $validatedData['otp']);
        if(!$isOtp) {
            return ['message' => 'Invalid OTP','success' => false];
        }

        try {
            DB::transaction(function ()
            use ($validatedData)
            {
                //get balance of user
                $balanceUserReceive = UsersBalance::where('user_id',$validatedData['to_id'])
                    ->where('token_id', 1)
                    ->lockForUpdate()
                    ->first();
                $balanceUserSend = UsersBalance::where('user_id',$validatedData['from_id'])
                    ->where('token_id', 1)
                    ->lockForUpdate()
                    ->first();
                $currentBalanceSend =  floatval($balanceUserSend->balance);
                $currentBalanceReceive =  floatval($balanceUserReceive->balance);
               //check balance
                if(($currentBalanceSend) < floatval($validatedData['value']) ){
                    throw new \Exception('Insufficient balance to complete the transaction');
                }
                $newBalanceSend = $currentBalanceSend - floatval($validatedData['value']);
                $newBalanceReceive =$currentBalanceReceive + floatval($validatedData['value']);


                $balanceUserSend->update([
                    'balance'=> $newBalanceSend,
                    'updated_at' => now(),
                ]);
                $balanceUserReceive->update([
                    'balance'=> $newBalanceReceive,
                    'updated_at' =>  now(),
                ]);

                // send
               UsersTransaction::create([
                    'type' => 5,
                    'user_id' => floatval($validatedData['from_id']),
                    'from_id' =>  floatval($validatedData['from_id']),
                    'to_id' =>  floatval($validatedData['to_id']),
                    'token_id' =>  1,
                    'status' =>  'success',
                    'message' =>  $validatedData['message'],
                    'value' =>  floatval($validatedData['value']),
                    'fee' => 0,
                    'pre_balance' => $currentBalanceSend,
                    'post_balance' => $newBalanceSend,
                    'updated_at' =>  now(),
                    'created_at' =>  now(),
               ]);
                //receive
               UsersTransaction::create([
                    'type' => 6,
                    'user_id' => floatval($validatedData['to_id']),
                    'from_id' =>  floatval($validatedData['from_id']),
                    'to_id' =>  floatval($validatedData['to_id']),
                    'token_id' =>  1,
                    'status' =>  'success',
                    'message' =>  $validatedData['message'],
                    'value' =>  floatval($validatedData['value']),
                    'fee' => 0,
                    'pre_balance' => $currentBalanceReceive,
                    'post_balance' => $newBalanceReceive,
                    'updated_at' =>  now(),
                    'created_at' =>  now(),
               ]);

//                $sendEmail = new SendEmailController();
//                $content = "<h3>Xin chào $user->name </h3>
//                            <p> Mã đơn hàng: $order->tracking_number </p>
//                            <p> Đã thanh toán thành công </p>";
//                $sendEmail->sendOrderTomxu($user->email,$content);

            });
            DB::commit();

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['message' => $e->getMessage(), 'success' => false], 403);
        }

        return response()->json(['message' =>'Transaction success.', 'success' => true,
            'data'=> [
                'from_id' => $validatedData['from_id'],
                'to_id' => $validatedData['to_id'],
                'message' => $validatedData['message'],
                'value' => $validatedData['value'],
                ],
        ], 200);
    }

}
