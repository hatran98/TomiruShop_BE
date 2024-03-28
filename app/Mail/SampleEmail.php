<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SampleEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $otp;

    public function __construct($otp)
    {
        $this->otp = $otp;
    }

    public function build()
    {
        return $this->subject('Your verification code')
            ->html('<h2>Xin chào bạn</h2><p>Mã xác thực của bạn là: <strong>' . $this->otp . '</strong></p>');
    }
}
