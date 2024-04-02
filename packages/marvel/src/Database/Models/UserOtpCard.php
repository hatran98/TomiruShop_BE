<?php

namespace Marvel\Database\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class UserOtpCard extends Model
{
    protected $table = 'user_otp_card';

    protected $fillable = [
        'user_id',
        'card_serial',
        'issue_at',
        'expire_at',
        'status',
        'card_token',
        'created_at',
        'updated_at',
    ];

//    public function create_token()
//    {
//        $encryptedData = Crypt::encrypt([
//            'user_id' => $this -> user_id,
//            'card_serial' => $this->card_serial,
//            'issue_at' => $this -> issue_at,
//            'expire_at' => $this -> expire_at,
//        ]);
//    }

    public function isExpired(): bool
    {
        return Carbon::parse($this->expire_at)->isPast();
    }

    public function isAvailable():bool
    {
        return $this->status == 'available';
    }

    public function isActive():bool
    {
        return $this->status == 'active';
    }

    public function isDeactivated():bool
    {
        return $this->status == 'deactivated';
    }

    public function isLocked():bool
    {
        return $this->status == 'locked';
    }
    public function generateCardToken()
    {
        $toEncryptData = [
            'user_id' => $this->user_id,
            'card_serial' =>$this->card_serial,
            'issue_at' => $this->issue_at,
            'expire_at' => $this->expire_at,
        ];

        return Crypt::encrypt(json_encode($toEncryptData));
    }


    //make sure the token is not forged or expired
    public function validate():bool
    {
        $a = $this->generateCardToken();
        $b = $this->card_token;
        return $a = $b
            && !$this->isExpired()
            && $this->isActive();
    }

}

