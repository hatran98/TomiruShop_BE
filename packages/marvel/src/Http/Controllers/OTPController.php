<?php

namespace Marvel\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Marvel\Database\Models\OTP;
use App\Helpers\EncryptionHelper;
use Illuminate\Support\Facades\Auth;
use App\Listeners\SendOrderConfirmationEmail;
use Marvel\Database\Repositories\OTPRepository;

class OTPController extends CoreController
{
    const OTP_METHODS = ['email', 'sms', 'card'];
    /**
     * Handles OTP (One-Time Password) requests.
     *
     * This method validates the incoming request data, checks user authentication,
     * generates a random OTP, creates an OTP record in the database, and dispatches
     * an email with the OTP.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function requestOtp(Request $request){

        $validatedData = $request->validate([
            'user_id' => 'required|integer',
            'user_email' => 'required|email',
            'type_otp' => 'required|string',
            'method' => 'required|string',
            'secret_token' => 'required',
        ]);

        // Check user authentication
        $isAuth = $this->validateAuth($validatedData['user_id'],$validatedData['user_email'],$validatedData['secret_token'],$validatedData['type_otp']);

        if(!$isAuth){
            return response(['message' => 'Unauthorized', 'status' => false], 401);
        }

        // Check the method of OTP delivery
        if(!in_array($validatedData['method'], self::OTP_METHODS)){
            return response(['message' => 'Invalid OTP method', 'status' => false], 422);
        }

        // Create an OTP record in the database
        $OTPRepository= new OTPRepository();
        $otp = $OTPRepository->createOTP(
            $validatedData['type_otp'],
            $validatedData['method'],
            $validatedData['user_id']
        );

        // Check if the OTP record was successfully created
        if(!$otp || !$otp->otp){
            return response(['message' => 'Something went wrong.Try again', 'status' => false], 500);
        }

        // Prepare the data to be sent in the email
        $toEmailData = [
            'user_id' => $validatedData['user_id'],
            'user_email' => $validatedData['user_email'],
            'method' => $validatedData['method'],
            'otp' => $otp->otp,
            'type' => $validatedData['type_otp'],
            'title' => 'Mã xác nhận OTP',
            'template' => 'otpOrder'
        ];

        // Encrypt the data to be sent in the email
        $encryptedData =  EncryptionHelper::encrypt($toEmailData,env('CREATE_TOKEN_KEY'),env('CREATE_TOKEN_IV'));
        $endPoint = 'api/sendmail/otpOrder';
        SendOrderConfirmationEmail::dispatch($encryptedData, $endPoint);

        // Return a success response
        return response(['message' => 'Generate OTP success', 'status' => true], 201);

    }
    protected function validateAuth($user_id,$user_email,$secret_token,$type_otp): bool
    {
        $user = Auth::user();
        if (!$user || $user->id != $user_id) {
            return false;
        };
        if ($user['status'] == 'locked') {
            return false;
        };
        if ($user->email != $user_email) {
            return false;
        }
        $data = $type_otp . $user->id . $user->email;
        $secret =  EncryptionHelper::encrypt($data,env('CREATE_TOKEN_KEY'),env('CREATE_TOKEN_IV'));
        if ($secret_token != $secret) {
            return false;
        }
        return true;
    }

}
