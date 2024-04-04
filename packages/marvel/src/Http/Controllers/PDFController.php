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
use Marvel\Database\Repositories\PdfRepository;
use Marvel\Database\Repositories\UserCardOtpTokenRepository;
use Marvel\Database\Repositories\UserOtpCardRepository;
use App\Helpers\EncryptionHelper;
use Illuminate\Support\Facades\Http;

class PDFController extends CoreController
{
    public UserCardOtpTokenRepository $tokenRepository;

    public UserOtpCardRepository $cardRepository;

    public PdfRepository $pdfRepository;

    public function __construct(UserCardOtpTokenRepository $tokenRepository, UserOtpCardRepository $cardRepository , PdfRepository $pdfRepository)
    {
        $this->tokenRepository = $tokenRepository;
        $this->cardRepository = $cardRepository;
        $this->pdfRepository = $pdfRepository;
    }


    public function generatePdf(Request $request)
    {
        // Kích hoạt thẻ và lấy số serial
        $response = $this->activateCardAndGetSerial($request);
        $card_serial = $this -> pdfRepository -> ExtractCardSerialFromResponse($response);
        $password = $request->input('password');
        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        // Gọi phương thức mới từ repository để tạo PDF và gửi email
        return $this->pdfRepository->generatePdfAndSendEmail($card_serial, $password);
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

                    // Gọi hàm createdCard để tạo thẻ mới
                    $this -> tokenRepository->createdCard(1);
                    // Lấy lại serialNumber sau khi đã tạo mới thẻ
                    $serialNumber = UserCardOtpToken::whereNotIn('serial_number', function ($query) {
                        $query->select('card_serial')->from('user_otp_card');
                    })->first();
                }

                // Kiểm tra xem tài khoản đã kích hoạt thẻ chưa
                $activatedCard = UserOtpCard::where('user_id', $user->id)->first();

                if ($activatedCard) {
                    DB::rollBack();
                    return response()->json(['message' => 'Tài khoản đã kích hoạt thẻ.'], 409);
                }
                $activatedCard = $this -> cardRepository->active($user->id, $serialNumber->serial_number,false);
                $card_serial = $activatedCard->card_serial;

                DB::commit();

                return response(['card_serial' => $card_serial, 'message' => 'Thẻ đã được kích hoạt thành công.'], 200);
            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json(['message' => 'Đã xảy ra lỗi trong quá trình kích hoạt thẻ.'], 500);
            }
        } catch (\Exception $e) {
            return response()->json(['message' => 'Đã xảy ra lỗi trong quá trình xử lý yêu cầu.'], 500);
        }
    }

}

