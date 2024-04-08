<?php


namespace Marvel\Database\Repositories;
use Carbon\Carbon;
use Marvel\Database\Models\OTP;

/**
 * Class OTPRepository
 *
 * @package Marvel\Database\Repositories
 */
class OTPRepository {
    /**
     * Creates a new OTP (One-Time Password) record in the database.
     *
     * This method generates a random OTP and creates a new OTP record in the database with the provided type, method, and user ID.
     *
     * @param string $type_otp The type of the OTP.
     * @param string $method The method of OTP delivery.
     * @param int $user_id The ID of the user for whom the OTP is generated.
     * @return OTP The newly created OTP record.
     */
    public function createOTP($type_otp, $method, $user_id){
        // Generate a random OTP
        $random_otp = mt_rand(100000, 999999);
        // Create an OTP record in the database
        return OTP::create([
            'type' =>$type_otp,
            'method' => $method,
            'user_id' => $user_id,
            'otp' => $random_otp,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }
    /**
     * Verifies the provided OTP (One-Time Password).
     *
     * This method retrieves the latest OTP generated for the user and checks if it matches
     * the provided OTP and if it has not expired. The OTP is considered expired if it was
     * generated more than 2 minutes ago.
     *
     * @param object $user  The user for whom to verify the OTP.
     * @param string $otp  The OTP to verify.
     * @param string $type_otp  The type of the OTP.
     * @return bool  Returns true if the OTP is valid; false otherwise.
     */
    public function verifyOtp(object $user, string $otp, string $type_otp): bool {
        // Retrieve the latest OTP generated for the user
        $OtpLatest = OTP::where('user_id', $user->id)
            ->where('type', $type_otp)
            ->latest('created_at')
            ->first();

        // Check if the provided OTP matches the latest OTP
        if ($OtpLatest->otp != $otp) {
            return false;
        }

        $currentTime = Carbon::now();
        $createdAt = Carbon::parse($OtpLatest->created_at);
        // OTP expires after 2 minutes
        if ($createdAt->diffInMinutes($currentTime) > 2) {
            return false;
        }

        // If the OTP matches and has not expired, it is valid
        return true;
    }


}
