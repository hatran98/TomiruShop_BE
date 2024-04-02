<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Marvel\Database\Models\OTP;
use Carbon\Carbon;

class ExpireOtp extends Command
{
    protected $signature = 'otp:expire';

    protected $description = 'Expire OTPs that are older than 2 minutes';

    public function handle()
    {
        $activeOtps = OTP::where('status', 'active')->get();

        foreach ($activeOtps as $otp) {
            if ($otp->expires_at <= Carbon::now()->subMinutes(2)) {
                $otp->status = 'expired';
                $otp->save();
            }
        }

        $this->info('Expired OTPs have been updated.');
    }
}
