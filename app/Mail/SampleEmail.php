<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SampleEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $otp;
    public $content;

    public function __construct($otp,$content)
    {
        $this->otp = $otp;
        $this->content = $content;
    }

    public function build()
    {
        if($this->otp){
            return $this->subject('Your verification code')
                ->html('<h2>Xin chào bạn</h2><p>Mã xác thực của bạn là: <strong>' . $this->otp . '</strong></p>');
        }
        if($this->content){
            return $this->subject('Your transaction')
                ->html($this->content);
        }
        if($this->content->title){
            return $this->subject($this->content->title)
                ->html($this->content->content);
        }
    }

    public function buildOtherContent($content)
    {
        return $this->subject('Other subject')
            ->html('<h2>Other Content</h2><p>' . $content . '.</p>');
    }
}
