<?php

namespace Marvel\Database\Repositories;

use App\Helpers\EncryptionHelper;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\User;
use Marvel\Database\Models\UserOtpCard;
use Marvel\Database\Models\UserCardOtpToken;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Http;

class PdfRepository
{

    public function generatePdfAndSendEmail($card_serial, $password)
    {
        // Lấy dữ liệu OTP
        $otpData = $this->getOtpData($card_serial);

        if (!$otpData) {
            return response()->json(['message' => 'OTP data not found'], 404);
        }

        // Tạo và lưu PDF
        $pdf = $this->createPdfWithPassword($card_serial, $otpData, $password);

        if (!$pdf) {
            return response()->json(['message' => 'PDF creation failed'], 500);
        }


        $email = $this->getUserEmail($card_serial);

        // Gửi email với PDF đính kèm
        if ($email) {
            $sendMail = $this->sendEmailWithPdf($email, $pdf, $card_serial);
            if ($sendMail !== true) {
                // Nếu gửi email thất bại, chỉ trả về thông báo thành công
                return response()->json(['message' => 'Email not sent, but card activated successfully'], 200);
            }
            return response()->json(['message' => 'Email sent successfully'], 200);
        }

        // Trả về đường dẫn PDF
        return response()->json(['message' => 'PDF created successfully'], 200);
    }



    public function getUserEmail($card_serial)
    {
        $userOtpCard = UserOtpCard::where('card_serial', $card_serial)->first();

        if (!$userOtpCard) {
            return null;
        }

        $user_id = $userOtpCard->user_id;
        $user = User::find($user_id);

        if (!$user) {
            return null;
        }

        return $user->email;
    }

    public function getOtpData($card_serial)
    {
        $otpData = UserCardOtpToken::where('serial_number', $card_serial)->select('token', 'stt')->get();
        if (!$otpData) {
            return null;
        }

        return $otpData;
    }

    public function extractCardSerialFromResponse($response)
    {
        return json_decode($response->getContent(), true)['card_serial'];
    }


    public function createPdfWithPassword($card_serial, $otpData, $password)
    {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($options);

        $html = view('otp', compact('otpData'))->render();

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $dompdf->getCanvas()->get_cpdf()->setEncryption($password, $password);

        $pdfFileName = $card_serial . '.pdf';

        // Tạo tệp PDF trực tiếp từ nội dung và lưu trực tiếp lên S3
        Storage::disk('vultrobjects')->put('pdf/' . $pdfFileName, $dompdf->output(), 'public');


        // Trả về URL của tệp PDF trên S3
        return Storage::disk('vultrobjects')->url('pdf/' . $pdfFileName);

    }


    public function sendEmailWithPdf($email, $pdf, $card_serial)
    {
        $key = env('CREATE_TOKEN_KEY');
        $iv = env('CREATE_TOKEN_IV');

        if ($email) {
            $content = [
                'serial' => $card_serial,
                'email' => $email,
                'subject' => 'Kích hoạt thẻ thành công',
                'htmlBody' => 'Mã số thẻ : ' . $card_serial . ' đã được kích hoạt thành công </br> Vui lòng xem mã OTP của thẻ trong file pdf đính kém',
                'attachment' => base64_encode($pdf),
                'type' => 'pdf',
                'template' => 'pdf'
            ];

            $encryptToken = EncryptionHelper::encrypt($content, $key, $iv);
            $clientId = 'ha-dev.com';

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'clientId' => $clientId,
                'clientSecret' => 'tomiruHaDong'
            ])->post(env('API_SENDMAIL'), [
                'content' => $encryptToken,
            ]);

            if ($response->successful()) {
                return true;
            } else {
                // Nếu gửi email thất bại, trả về thông báo thất bại
                return false;
            }
        } else {
            return false;
        }
    }



}
