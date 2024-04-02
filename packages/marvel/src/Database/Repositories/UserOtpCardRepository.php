<?php

namespace Marvel\Database\Repositories;

use Illuminate\Auth\AuthenticationException;
use Marvel\Database\Models\User;
use Illuminate\Support\Facades\Auth;
use Marvel\Database\Models\UserCardOtpToken;
use Marvel\Database\Models\UserOtpCard;
use PHPUnit\Logging\Exception;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;

class UserOtpCardRepository extends BaseRepository
{
    /**
     * @var array
     */
    protected $dataArray = [
        'user_id',
        'card_serial',
        'issue_at',
        'expire_at',
        'status',
    ];

    public function model()
    {
        // TODO: Implement model() method.
        return UserOtpCard::class;
    }

    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
            //
        }
    }

    //require admin permission
    public function bind($userId, $serial)
    {
        //find user by userId
        $user = User::where('id', $userId)->first();
        if (!$user) {
            throw new \Exception("User $userId not found", 404);
        }
        //find tokenCard with $serial
        $tokenCard = UserOtpCard::where('card_serial', $serial)
            ->first();
        if (!$tokenCard) {
            $tokenCard = UserOtpCard::create([
                'user_id' => null,
                'card_serial' => $serial,
                'issue_at' =>null,
                'status' => 'available',
                'card_token' => null,
                'expire_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if (!$tokenCard->isAvailable()) {
            throw new \Exception("Card $serial not available", 404);
        }

        //deactivate old card if exists and assign new card
        if ($user->otpCard) {
            $user->otpCard->update(['status' => 'deactivated']);
        }
        $tokenCard->update([
            'user_id' => $user->id,
            'status' => 'active',
            'issue_at' => now(),
            'expire_at' => now()->addYear(),
        ]);

        //generate card_token
        $tokenCard->update([
            'card_token' => $tokenCard->generateCardToken(),
        ]);

        return $tokenCard;
    }

//    public function showCards()
//    {
//        return OTPCard::where('status', 'available')->take(10)->get();
//    }

    private function isAuthenticated($data)
    {
        $user = Auth::user();
        return $user->id == $data['customer_id'] && $user->isActive();
    }

    //    private function validateData(Request $request)
//    {
//        return $request->validate([
//            'customer_id' => 'required',
//            'method' => 'required',
//            'stt' => 'required',
//            'token' => 'required',
//        ]);
//    }
    public function verifyOTP($validatedData)
    {
        if ($validatedData['method'] != 'card') {
            throw new \Exception("Invalid OTP type");
        }

        if (!$this->isAuthenticated($validatedData)) {
            throw new AuthenticationException("Invalid user");
        }


        //find the otpCard by userID
        $otpCard = UserOtpCard::where('user_id', $validatedData['customer_id'])
            ->where('status', 'active')
            ->first();
        if (!$otpCard) {
            throw new \Exception("The user does not have any active OTP card");
        }

        //validate the otp card is not edited
        if (!$otpCard->validate()) {
            throw new \Exception("OTP card has been modified");
        }

        //find the corresponding token
        $token = UserCardOtpToken::where('serial_number', $otpCard->card_serial)
            ->where('stt', $validatedData['stt'])
            ->where('token', $validatedData['token'])
            ->first();

        if (!$token) {
            throw new \Exception("Wrong OTP");
        }

        return isset($token);
    }
}
