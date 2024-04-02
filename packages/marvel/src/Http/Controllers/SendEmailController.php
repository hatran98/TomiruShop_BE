<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;
use App\Mail\SampleEmail;
use Marvel\Database\Models\OTP;
use Marvel\Database\Models\User;
use Carbon\Carbon;
class SendEmailController extends CoreController
{
    public function sendEmail(Request $request)
    {

        $userAuth = Auth::user();
        if (!$userAuth || $userAuth->email != $request->input('email')) {
            return "Unauthorized access!";
        }

        $recipientEmail = $request->input('email');
        $user = User::where('email', $recipientEmail)->first();
        $type = $request->input('type');

        if ($user) {
            $otp = mt_rand(100000, 999999);
            $expiresAt = Carbon::now()->addMinutes(2);
            Mail::to($recipientEmail)->send(new SampleEmail($otp,''));

            $this->storeOTP($user->id, $type, $otp, $expiresAt);

           return response('Email sent successfully!', 200);
        } else {
            return "Email not found in database!";
        }
    }
    public function sendOrderTomxu($email,$content)
    {

        $recipientEmail = $email;
        $user = User::where('email', $recipientEmail)->first();

        if ($user) {
            Mail::to($recipientEmail)->send(new SampleEmail('', $content));

        }
    }

    protected function storeOTP($userId,$type, $otp, $expiresAt)
    {
        OTP::create([
            'type' => $type,
            'user_id' => $userId,
            'otp' => $otp,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    public function verifyOtp($type, $user_id, $otp )
    {

        $otpRecord = OTP::where('user_id', $user_id)
            ->where('type', $type)
            ->where('status', 'active')
            ->latest()
            ->first();

        if (!$otpRecord) {
            return false;
        }

        if ($otpRecord->otp == $otp && !($otpRecord->isExpired()) && $otpRecord->status == 'active') {
            $otpRecord->status = 'used';
            $otpRecord->save();
            return true;
        } else {
            return false;
        }
    }


}


