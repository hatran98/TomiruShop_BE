<?php

namespace Marvel\Http\Controllers;

use Dompdf\Dompdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\UserCardOtpToken;
use Marvel\Database\Models\UserOtpCard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Marvel\Database\Models\User;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Repositories\PdfRepository;
use Marvel\Database\Repositories\UserOtpCardRepository;
use Marvel\Database\Repositories\UserCardOtpTokenRepository;
use Marvel\Enums\Permission;

class CardController extends CoreController
{

    public UserOtpCardRepository $repository;

    public UserCardOtpTokenRepository $tokenRepository;

    public PdfRepository $pdfRepository;

    public function __construct(UserOtpCardRepository $repository, UserCardOtpTokenRepository $tokenRepository , PdfRepository $pdfRepository)
    {
        $this->repository = $repository;
        $this->tokenRepository = $tokenRepository;
        $this->pdfRepository = $pdfRepository;
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

    public function createCard(Request $request)
    {
        $totalCard = $request->query('totalCard', 1);
     return $this -> tokenRepository -> createdCard($totalCard);
    }
public function showCardDetail(Request $request) {
        $id = $request->query('id');
return $this -> repository -> fetchSingleCard($id);
}


    /**
     * Hiển thị danh sách thẻ tồn tại dựa trên các yêu cầu của người dùng,
     * bao gồm cả tìm kiếm và sắp xếp.
     *
     * @param \Illuminate\Http\Request $request Đối tượng Request chứa các thông tin yêu cầu từ người dùng.
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function showExistingCards(Request $request)
    {
        // Lấy các tham số yêu cầu từ request
        $status = $request->query('status');
        $limit = $request->query('limit', 10);
        $orderBy = $request->query('orderBy', 'card_serial');
        $orderDirection = $request->query('orderDirection', 'asc');
        $search = $request->query('search');

        // Kiểm tra nếu có yêu cầu tìm kiếm
        if ($search) {
            // Gọi phương thức search từ repository với giá trị tìm kiếm và các tham số khác
            return $this->repository->search($search, $limit, $orderBy, $orderDirection);
        }


        // Nếu không có yêu cầu tìm kiếm, gọi phương thức show từ repository với các tham số khác
        return $this->repository->show($status, $limit, $orderBy, $orderDirection);
    }


    public function showCards(Request $request)
    {
        $limit = $request->query('limit', 10);
        $search = $request->query('search'); // Lấy tham số tìm kiếm từ request

        // Gọi phương thức show với tham số tìm kiếm nếu có
        if ($search) {
            return $this->tokenRepository->show($limit, $search);
        } else {
            return $this->tokenRepository->show($limit); // Gọi phương thức show với limit mặc định nếu không có tìm kiếm
        }
    }


    public function printCard(Request $request)
    {
        // Lấy thông tin người dùng hiện tại
        $user = $request->user();

        // Kiểm tra xem người dùng có tồn tại không
        if (!$user) {
            return response()->json(['message' => 'Người dùng không tồn tại.'], 404);
        }

        // Kiểm tra xem người dùng có quyền SUPERADMIN hay không
        if ($user->hasPermissionTo(Permission::SUPER_ADMIN)) {
            // Nếu có quyền, thực hiện hành động in thẻ
            $userId = $request->input('user_id');
            $password = $request->input('password');

            if (!$userId || !$password) {
                return response()->json(['message' => 'Vui lòng cung cấp cả ID và mật khẩu.'], 400);
            }

            // Kiểm tra xem người dùng đã có thẻ kích hoạt chưa
            $userOtpCard = UserOtpCard::where('user_id', $userId)->first();
            if ($userOtpCard) {
                // Nếu có thẻ đã kích hoạt, sử dụng thẻ đó để in
                $serialNumber = $userOtpCard->card_serial;
                $this -> repository -> updatePrinted($serialNumber);
                return $this->pdfRepository->generatePdfAndSendEmail($serialNumber, $password);
            } else {
                // Kiểm tra xem còn thẻ chưa kích hoạt trong kho hay không
                $availableCard = UserCardOtpToken::whereNotIn('serial_number', function ($query) {
                    $query->select('card_serial')->from('user_otp_card');
                })->first();

                if ($availableCard) {
                    // Nếu có thẻ chưa kích hoạt, sử dụng thẻ đó để in
                    $this->repository->active($userId, $availableCard->serial_number, true);
                    return $this->pdfRepository->generatePdfAndSendEmail($availableCard->serial_number, $password);
                } else {
                    // Nếu không có thẻ chưa kích hoạt, tạo mới một thẻ và sử dụng nó để in
                    $this->tokenRepository->createdCard(1);
                    // Lấy lại serialNumber sau khi đã tạo mới thẻ
                    $newCard = UserCardOtpToken::whereNotIn('serial_number', function ($query) {
                        $query->select('card_serial')->from('user_otp_card');
                    })->first();

                    if ($newCard) {
                        $this->repository->active($userId, $newCard->serial_number, true);
                        return $this->pdfRepository->generatePdfAndSendEmail($newCard->serial_number, $password);
                    }
                }
            }
        } else {
            // Nếu không có quyền, trả về thông báo lỗi
            return response()->json(['message' => 'Bạn không có quyền thực hiện hành động này.'], 403);
        }
    }

    public function updateCard(Request $request)
    {
        $user = $request->user();

        // Kiểm tra quyền của super admin
        if ($user && $user->hasPermissionTo(Permission::SUPER_ADMIN)) {
            return $this->updateStatus($request);
        }

        // Kiểm tra đăng nhập của người dùng
        if (!$user) {
            return response()->json(['message' => 'Vui lòng đăng nhập'], 403);
        }

        // Kiểm tra trạng thái của thẻ của người dùng
        $userOtpCard = UserOtpCard::where('user_id', $user->id)->first();
        if (!$userOtpCard) {
            return response()->json(['message' => 'Thẻ không tồn tại cho người dùng này'], 404);
        }

        return $this->updateStatus($request, $userOtpCard->card_serial);
    }

    private function updateStatus(Request $request, $serialNumber = null)
    {
        $serialNumber = $request->input('serial_number', $serialNumber);
        $status = $request->input('status');

        if (!$serialNumber || !$status) {
            return response()->json(['message' =>  'Vui lòng cung cấp serialNumber và status'], 400);
        }

        return $this->repository->updateStatus($serialNumber, $status);
    }


}
