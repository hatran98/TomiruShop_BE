<?php

namespace Marvel\Database\Models;


use Illuminate\Database\Eloquent\Model;
class UserCardOtpToken extends Model
{
    protected $table = "user_card_otp_token";
    protected $fillable=["serial","stt","token",'created_at','updated_at'];

}
