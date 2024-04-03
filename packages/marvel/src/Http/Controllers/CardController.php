<?php

namespace Marvel\Http\Controllers;

use Dompdf\Dompdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\OTPCard;
use Marvel\Database\Models\UserCardOtpToken;
use Marvel\Database\Models\UserOtpCard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Marvel\Database\Models\User;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Repositories\UserOtpCardRepository;
use Marvel\Database\Repositories\UserCardOtpTokenRepository;

class CardController extends CoreController
{

    public UserOtpCardRepository $repository;

    public UserCardOtpTokenRepository $tokenRepository;

    public function __construct(UserOtpCardRepository $repository, UserCardOtpTokenRepository $tokenRepository)
    {
        $this->repository = $repository;
        $this->tokenRepository = $tokenRepository;
    }

    public function createdCard()
    {
        // Lấy serial cuối cùng từ cơ sở dữ liệu
        $latestSerial = UserCardOtpToken::latest()->first();

        // Khởi tạo giá trị ban đầu của serialNumber
        $serialNumber = 1000001;

        if ($latestSerial) {
            // Lấy số cuối cùng của serial hiện có
            $lastSerialNumber = (int)$latestSerial->serial;


            // Tăng giá trị của serialNumber lên một đơn vị so với số cuối cùng
            $serialNumber = $lastSerialNumber + 1;
        }

        // Khởi tạo mảng để lưu trữ token đã được sử dụng
        $usedTokens = [];

        // Tạo 100 serial mới, bắt đầu từ số cuối cùng lấy được từ cơ sở dữ liệu
        for ($i = 0; $i < 200; $i++) {
            // Tạo serial cho mỗi vòng lặp
            $serial = (string)$serialNumber;

            // Tạo 35 token
            for ($j = 0; $j < 35; $j++) {
                do {
                    // Tạo một token mới
                    $token = random_int(1001, 9999);
                } while (in_array($token, $usedTokens)); // Kiểm tra xem token đã được sử dụng chưa

                // Thêm token vào mảng usedTokens
                $usedTokens[] = $token;

                // Tạo một bản ghi mới trong cơ sở dữ liệu
                UserCardOtpToken::create([
                    'serial' => $serial,
                    'stt' => $j + 1,
                    'token' => $token,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // Tăng giá trị của serialNumber để tạo serial tiếp theo
            $serialNumber++;
        }
    }

    public function verify(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'customer_id' => 'required',
                'method' => 'required',
                'stt' => 'required',
                'token' => 'required',
            ]);

            $result = $this->repository->verifyOTP($validatedData);
            if ($result) {
                return response("success", 200);
            } else {
                return response("cannot verify otp", 422);
            }
        } catch (ValidationException $e) {
            return response($e->getMessage(), 422);
        } catch (\Exception $e) {
            return response($e->getMessage(), 403);
        }
    }

    public function bind(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'customer_id' => 'required',
                'serial' => 'required',
            ]);

            return $this->repository->bind($validatedData['customer_id'], $validatedData['serial']);
        } catch (ValidationException $e) {
            return response($e->getMessage(), 422);
        }
    }

    public function showCards(Request $request)
    {
        return $this->repository->showCards();
    }

}
