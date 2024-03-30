<?php

namespace App\Http\Controllers;

use Dompdf\Options;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\User;
use Marvel\Database\Models\UserCardOtpToken;
use Marvel\Database\Models\UserOtpCard;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
class PdfController extends Controller
{
    public function generatePdf(Request $request)
    {
        $response = $this->activateCardAndGetSerial($request);

        if (!$response) {
            return response()->json(['message' => 'Activation failed or invalid password'], 400);
        }

if ($response['statusCode'] === 200) {
    $responseJSON = $response['card_serial']->getContent();
    $card_serial = json_decode($responseJSON, true)['card_serial'];
    $password = $response['password'];
    $otpData = $this->getOtpData($card_serial);
    if (!$otpData) {
        return response()->json(['message' => 'OTP data not found'], 404);
    }
    $this->createPdfDirectoryIfNeeded();

    $pdf = $this->createPdfWithPassword($card_serial, $otpData, $password);

    if (!$pdf) {
        return response()->json(['message' => 'PDF creation failed'], 500);
    }

    // Trả về đường dẫn tới tệp PDF đã tạo
    $sendMail = $this->sendEmailWithPdf2($pdf, $card_serial);
    if (!$sendMail) {
        return response()->json(['message' => 'Email sending failed'], 500);
    }
    return response()->json(['pdf_path' => $pdf]);
}

        return $response;

//        $this->sendEmailWithPdf($pdf, $card_serial);
//
//        return response()->json(['message' => 'Email sent successfully'], 200);
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
        $password = $request->input('password');

        if (empty($password)) {
            return null;
        }

        $card_serial = $this->activateCard($request);
        if (!$card_serial) {
            return null;
        }


        return ['card_serial' => $card_serial, 'password' => $password,'statusCode' => $card_serial->getStatusCode()];

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
//
//            $tokenData = Crypt::encrypt($userDatabase -> user_id , $userDatabase-> email);
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
                    'issue_at' => now()->toDateTimeString(),
                    'expire_at' => now()->addDays(365)->toDateTimeString()
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







    private function createPdfDirectoryIfNeeded()
    {
        $pdfDirectory = storage_path('app/pdf');
        if (!file_exists($pdfDirectory)) {
            mkdir($pdfDirectory, 0777, true);
        }
    }

    private function sendEmailWithPdf2($pdf, $card_serial)
    {
        // Lấy địa chỉ email của người dùng từ cơ sở dữ liệu hoặc thông tin yêu cầu
        $email = "hatran3898@gmail.com"; // Hàm getUserEmail làm việc với cơ sở dữ liệu để lấy địa chỉ email

        // Kiểm tra xem có địa chỉ email hợp lệ hay không
        if ($email) {
            // Khởi tạo đối tượng PHPMailer
            $mail = new PHPMailer(true); // Đặt true để bật chế độ bắt lỗi

            try {
                // Cấu hình thông tin SMTP
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com'; // Đổi thành SMTP server của bạn
                $mail->SMTPAuth = true;
                $mail->Username = 'lowealpiner2@gmail.com'; // Đổi thành địa chỉ email và mật khẩu của bạn
                $mail->Password = 'krhi isdo kcfj tmyn';
                $mail->SMTPSecure = 'null'; // SSL hoặc TLS
                $mail->Port = 587; // Cổng SMTP

                // Cấu hình thông tin email
                $mail->setFrom('lowealpiner2@gmail.com', 'Hà Trần');
                $mail->addAddress($email); // Địa chỉ email của người nhận
                $mail->addAttachment($pdf); // Đính kèm tệp PDF
                $mail->isHTML(true);
                $mail->Subject = 'Subject of the Email';
                $mail->Body = 'Body of the Email';

                // Gửi email
                $mail->send();
                return true;
            } catch (Exception $e) {
                // Ghi lại lỗi nếu có
                return false;
            }
        } else {
            // Địa chỉ email không hợp lệ
            return false;
        }
    }
    private function sendEmailWithPdf($pdf, $card_serial)
    {
        // Lấy địa chỉ email của người dùng từ cơ sở dữ liệu hoặc thông tin yêu cầu
        $email = getUserEmail($card_serial); // Hàm getUserEmail làm việc với cơ sở dữ liệu để lấy địa chỉ email

        // Kiểm tra xem có địa chỉ email hợp lệ hay không
        if ($email) {
            // Chuẩn bị dữ liệu để gửi lên API
            $data = [
                'email' => $email,
                'pdf' => base64_encode($pdf), // Chuyển đổi PDF thành base64 để gửi lên API
                'card_serial' => $card_serial
            ];

            // Gửi dữ liệu lên API
            $apiUrl = 'http://example.com/send-email'; // URL của API gửi email
            $response = $this->callApi($apiUrl, $data); // Gọi API và lấy kết quả trả về

            if ($response && $response['success']) {
                // Gửi email thành công
                return true;
            } else {
                // Gửi email thất bại
                return false;
            }
        } else {
            // Địa chỉ email không hợp lệ
            return false;
        }
    }

    private function callApi($url, $data)
    {
        // Gửi yêu cầu API sử dụng cURL hoặc thư viện HTTP khác
        // Ví dụ: sử dụng cURL
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        // Giải mã kết quả trả về từ JSON
        $responseData = json_decode($response, true);

        return $responseData;
    }
}

