<?php

namespace Marvel\Http\Controllers;

use Marvel\Database\Models\UserCardOtpToken;
use Marvel\Database\Models\UserOtpCard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Marvel\Database\Models\User;
use Illuminate\Support\Facades\DB;
class CardController extends CoreController
{
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


public function createtoken(Request $request) {

    $key = "12345678901234567890123456789012";

// IV tùy chỉnh
    $encIv = "1234567890123456";

// Dữ liệu cần mã hóa
   $data = $request->input('check1');

// Mã hóa dữ liệu
    $encryptedData = openssl_encrypt(json_encode($data), 'AES-256-CBC', $key, 0, $encIv);

// Giải mã dữ liệu
    $decryptedData = json_decode(openssl_decrypt($encryptedData, 'AES-256-CBC', $key, 0, $encIv), true);

// In ra kết quả
    var_dump($encryptedData, $decryptedData);

    return $encryptedData;
}





}
