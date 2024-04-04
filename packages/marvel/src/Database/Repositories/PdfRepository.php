<?php

namespace Marvel\Database\Repositories;

use App\Helpers\EncryptionHelper;
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

        // Tạo thư mục lưu trữ PDF nếu cần
        $this->createPdfDirectoryIfNeeded();

        // Tạo và lưu PDF
        $pdf = $this->createPdfWithPassword($card_serial, $otpData, $password);

        if (!$pdf) {
            return response()->json(['message' => 'PDF creation failed'], 500);
        }

        $email = $this->getUserEmail($card_serial);
        // Gửi email với PDF đính kèm
        if ($email) {
            $this->sendEmailWithPdf($email, $pdf, $card_serial);
            return response()->json(['message' => 'Email sent successfully', 'pdf_path' => $pdf]);
        }

        // Trả về đường dẫn PDF
        return response()->json(['message' => 'Successfully', 'pdf_path' => $pdf]);


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

    public function createPdfDirectoryIfNeeded()
    {
        $pdfDirectory = storage_path('app/pdf');
        if (!file_exists($pdfDirectory)) {
            mkdir($pdfDirectory, 0777, true);
        }
    }

    public function createPdfWithPassword($card_serial,$otpData, $password)
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

        $pdfWithPasswordPath = storage_path('app/pdf/' . $card_serial . '.pdf');
        file_put_contents($pdfWithPasswordPath, $dompdf->output());

        return $pdfWithPasswordPath;
    }

    public function sendEmailWithPdf($email,$pdf, $card_serial)
    {
        $key = env('CREATE_TOKEN_KEY');
        $iv = env('CREATE_TOKEN_IV');

        if ($email) {
            $content = [
                'serial' => $card_serial,
                'email' => $email,
                'subject' => 'Kích hoạt thẻ thành công',
                'htmlBody' => 'Mã số thẻ : ' . $card_serial . ' đã được kích hoạt thành công </br> Vui lòng xem mã OTP của thẻ trong file pdf đính kém',
                'attachment' => base64_encode(file_get_contents($pdf)),
                'type' => 'pdf',
                'template' => 'pdf'
            ];

            $encryptToken = EncryptionHelper::encrypt($content, $key, $iv);
            $clientId = 'tomiruHaDong';

            Http::withHeaders([
                'Content-Type' => 'application/json',
                'clientId' => $clientId,
            ])->post('http://192.168.102.11:8080/api/sendmail/pdf', [
                'content' => $encryptToken,
            ]);

            return true;
        } else {
            return false;
        }
    }
}
