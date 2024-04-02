<?php

namespace Marvel\Http\Controllers;

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


        $users = Auth::user();
        if(!$users || $users->id != $validatedData['user_id']){
            return response()->json(['message' => 'Unauthorized','success' => false], 401);
        }

        $userReceive  = User::find($validatedData['to_id']);
        if(!$userReceive){
            return response()->json(['message' => 'Recipient does not exist', 'success' => false], 401);
        }

        try {
            DB::transaction(function ()
            use ($validatedData)
            {
                $date = Carbon::now();
                $millisecondsNow = $date->timestamp * 1000;
                $balanceUserReceive = UsersBalance::where('user_id',$validatedData['to_id'])
                    ->where('token_id', 1)
                    ->lockForUpdate()
                    ->first();

                $balanceUserSend = UsersBalance::where('user_id',$validatedData['from_id'])
                    ->where('token_id', 1)
                    ->lockForUpdate()
                    ->first();

                $currentBalanceSend =  $balanceUserSend->balance;
                $currentBalanceReceive =  $balanceUserReceive->balance;
               //check balance
                if($currentBalanceSend < floatval($validatedData['value']) ){
                    throw new \Exception('Insufficient balance to complete the transaction');
                }
                $newBalanceSend = $currentBalanceSend - floatval($validatedData['value']);
                $newBalanceReceive =$currentBalanceReceive + floatval($validatedData['value']);
                $balanceUserSend->update([
                    'balance'=> $newBalanceSend,
                    'updated_at' => $millisecondsNow,
                ]);
                $balanceUserReceive->update([
                    'balance'=> $newBalanceReceive,
                    'updated_at' => $millisecondsNow,
                ]);
                //check otp
                $user_otp = DB::table('users_otp')
                    ->where('user_id', $validatedData['from_id'])
                    ->where('type', 3)
                    ->orderBy('created_at', 'desc')
                    ->first();
                if(!$user_otp){
                    throw new \Exception('Invalid OTP');
                }
                $otp = $user_otp->otp;
                $isExp = $user_otp->created_at;
                if( $otp!= $validatedData['otp'] || $isExp + 160*1000 < $millisecondsNow){
                    throw new \Exception('Invalid OTP');
                }

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
                    'updated_at' => $millisecondsNow,
                    'created_at' => $millisecondsNow,
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
                    'updated_at' => $millisecondsNow,
                    'created_at' => $millisecondsNow,
               ]);
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
