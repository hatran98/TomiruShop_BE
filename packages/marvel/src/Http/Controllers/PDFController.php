<?php

namespace Marvel\Http\Controllers;

use Dompdf\Options;
use Illuminate\Http\Request;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\User;
use Marvel\Database\Models\UserCardOtpToken;
use Marvel\Database\Models\UserOtpCard;
use App\Helpers\EncryptionHelper;
use Illuminate\Support\Facades\Http;
class PDFController extends CoreController
{
    public function generatePdf(Request $request)
    {

        // Kích hoạt thẻ và lấy số serial
        $response = $this->activateCardAndGetSerial($request);
        $password = $request->input('password');
        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        // Trích xuất số serial từ phản hồi
        $card_serial = $this ->extractCardSerialFromResponse($response);

        // Lấy dữ liệu OTP
        $otpData = $this->getOtpData($card_serial);

        if (!$otpData) {
            return response()->json(['message' => 'OTP data not found'], 404);
        }

        // Tạo thư mục lưu trữ PDF nếu cần
        $this->createPdfDirectoryIfNeeded();

        // Tạo và lưu PDF
        $pdf = $this->createPdfWithPassword($card_serial, $otpData, $password);

    if (!$pdf) {
        return response()->json(['message' => 'PDF creation failed'], 500);
    }

    $email = $this->getUserEmail($card_serial);

//         Gửi email với PDF đính kèm
        if ($email) {
            $this->sendEmailWithPdf($email, $pdf, $card_serial);
            return response()->json(['message' => 'Email sent successfully', 'pdf_path' => $pdf]);
        }

        return $response;


    }

    private function getUserEmail($card_serial)
    {
        $userOtpCard = UserOtpCard::where('card_serial', $card_serial)->first();

        if (!$userOtpCard) {
            return null;
        }

        // Lấy user_id từ bảng UserOtpCard
        $user_id = $userOtpCard->user_id;

        // Tìm user từ bảng User sử dụng user_id
        $user = User::find($user_id);

        // Kiểm tra nếu không tìm thấy user
        if (!$user) {
            return null;
        }

        // Trả về địa chỉ email của user
        return $user->email;
    }

    private function getOtpData($card_serial)
    {
        $otpData = UserCardOtpToken::where('serial_number', $card_serial)->select('token', 'stt')->get();
        if (!$otpData) {
            return null;
        }

        return $otpData;
    }
    private function activateCardAndGetSerial(Request $request)
    {

        $card_serial = $this->activateCard($request);
        if (!$card_serial) {
            return null;
        }

        return $card_serial;
    }

    public function activateCard(Request $request)
    {
        try {


            // Kiểm tra người dùng gửi yêu cầu
            $user = $request->user();
            if (!$user || $user->id != $request->user_id) {
                return response()->json(['message' => 'Người dùng không hợp lệ.'], 400);
            }

            // Kiểm tra token gửi lên
//            $encryptedToken = $request->input('token');
//            $user_id = $request->input('user_id');
//
//            $userDatabase = User::where('user_id', $user_id) -> first();
//            $key = "12345678901234567890123456789012";
//            $encIv = "1234567890123456";

//            $encryptedData = openssl_encrypt(json_encode($data), 'AES-256-CBC', $key, 0, $encIv);

//
//            if ($encryptedToken != $tokenData) {
//                return response()->json(['message' => 'Thiểu token.'], 400);
//            }



            // Kiểm tra xem người dùng có bị khóa không
            $inactiveUser = User::where('id', $user->id)->where('status', 'inactive')->first();

            if (!$inactiveUser) {
                return response()->json(['message' => 'Người dùng không hợp lệ hoặc bị khóa.'], 400);
            }


            DB::beginTransaction();

            try {
                // Kiểm tra xem còn thẻ nào có thể kích hoạt
                $serialNumber = UserCardOtpToken::whereNotIn('serial_number', function ($query) {
                    $query->select('card_serial')
                        ->from('user_otp_card');
                })->first();

                if (!$serialNumber) {
                    DB::rollBack();
                    return response()->json(['message' => 'Không có thẻ nào để kích hoạt.'], 404);
                }


                // Kiểm tra xem tài khoản đã kích hoạt thẻ chưa
                $activatedCard = UserOtpCard::where('user_id', $user->id)->first();

                if ($activatedCard) {
                    DB::rollBack();
                    return response()->json(['message' => 'Tài khoản đã kích hoạt thẻ.'], 409);
                }

                $issue_at = now()->toDateTimeString();
                $expire_at = now()->addDays(365)->toDateTimeString();
                $encryptedData = Crypt::encrypt([
                    'user_id' => $user->id,
                    'card_serial' => $serialNumber->serial_number,
                    'issue_at' => $issue_at,
                    'expire_at' => $expire_at,
                ]);

                    // Kích hoạt thẻ
                   $activatedCard = UserOtpCard::create([
                        'user_id' => $user->id,
                        'card_serial' => $serialNumber-> serial_number,
                        'issue_at' => $issue_at,
                         'status' => 'active',
                         'card_token' => $encryptedData,
                         'expire_at' => $expire_at,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $card_serial = $activatedCard->card_serial;

                DB::commit();

                return response()->json(['card_serial' => $card_serial, 'message' => 'Thẻ đã được kích hoạt thành công.'], 200);
            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json(['message' => 'Đã xảy ra lỗi trong quá trình kích hoạt thẻ.'], 500);
            }
        } catch (\Exception $e) {
            return response()->json(['message' => 'Đã xảy ra lỗi trong quá trình xử lý yêu cầu.'], 500);
        }
    }


    private function extractCardSerialFromResponse($response)
    {
        return json_decode($response->getContent(), true)['card_serial'];
    }

    private function createPdfDirectoryIfNeeded()
    {
        $pdfDirectory = storage_path('app/pdf');
        if (!file_exists($pdfDirectory)) {
            mkdir($pdfDirectory, 0777, true);
        }
    }

    private function createPdfWithPassword($card_serial,$otpData, $password)
    {
        // Khởi tạo Dompdf với các tùy chọn
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true); // Cho phép sử dụng HTML5
        $options->set('isRemoteEnabled', true); // Cho phép tải các tài nguyên từ URL
        $dompdf = new Dompdf($options);

        // Tạo HTML từ view blade
        $html = view('otp', compact('otpData'))->render();

        // Tải HTML vào Dompdf và render PDF
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait'); // Chọn kích thước giấy và hướng
        $dompdf->render();

        // Thêm mật khẩu vào tệp PDF
        $dompdf->getCanvas()->get_cpdf()->setEncryption($password, $password);

        // Lưu tệp PDF đã được thêm mật khẩu
        $pdfWithPasswordPath = storage_path('app/pdf/' . $card_serial . '.pdf');
        file_put_contents($pdfWithPasswordPath, $dompdf->output());

        return $pdfWithPasswordPath;
    }


    private function sendEmailWithPdf($email,$pdf, $card_serial)
    {
        // Lấy key trong env
        $key = env('CREATE_TOKEN_KEY');
        $iv = env('CREATE_TOKEN_IV');

        // Kiểm tra xem có địa chỉ email hợp lệ hay không
        if ($email) {
            // Chuẩn bị dữ liệu để gửi lên API
            $content = [
                'serial' => $card_serial,
                'email' => $email,
                'subject' => 'Kích hoạt thẻ thành công',
                'htmlBody' => 'Mã số thẻ : ' . $card_serial . ' đã được kích hoạt thành công </br> Vui lòng xem mã OTP của thẻ trong file pdf đính kém',
                'attachment' => base64_encode(file_get_contents($pdf)),
                'type' => 'pdf'
            ];

            $encryptToken = EncryptionHelper::encrypt($content, $key, $iv);
            $clientId = 'tomiruHaDong';

            // Gửi dữ liệu lên API bằng Laravel HTTP Client
            Http::withHeaders([
                'Content-Type' => 'application/json',
                'clientId' => $clientId,
            ])->post('http://192.168.102.11:8080/api/sendmail/pdf', [
                'content' => $encryptToken,
            ]);

            return true;
        } else {
            // Địa chỉ email không hợp lệ
            return false;
        }
    }


}

