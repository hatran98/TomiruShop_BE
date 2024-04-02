<?php

namespace Marvel\Database\Models;

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

    public function create_token()
    {
        $encryptedData = Crypt::encrypt([
            'user_id' => $this -> user_id,
            'card_serial' => $this->card_serial,
            'issue_at' => $this -> issue_at,
            'expire_at' => $this -> expire_at,
        ]);
    }
}

