<?php

namespace Marvel\Database\Models;


use Illuminate\Database\Eloquent\Model;
class UserCardOtpToken extends Model
{
    protected $table = "user_card_otp_token";
    protected $fillable=["serial_number","stt","token",'created_at','updated_at'];

    public function card()
    {
        return $this->belongsTo(UserOtpCard::class, 'serial_number', 'card_serial');
    }

}
