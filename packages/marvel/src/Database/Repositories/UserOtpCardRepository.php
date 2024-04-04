<?php

namespace Marvel\Database\Repositories;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Crypt;
use Marvel\Database\Models\User;
use Illuminate\Support\Facades\Auth;
use Marvel\Database\Models\UserCardOtpToken;
use Marvel\Database\Models\UserOtpCard;
use Marvel\Enums\Permission;
use PHPUnit\Logging\Exception;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;

class UserOtpCardRepository extends BaseRepository
{
    /**
     * @var array
     */
    protected $dataArray = [
        'user_id',
        'card_serial',
        'issue_at',
        'expire_at',
        'status',
    ];

    public function model()
    {
        // TODO: Implement model() method.
        return UserOtpCard::class;
    }

    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
            //
        }
    }

    public function active($userId , $serialNumber , $boolean) {
        $issue_at = now()->toDateTimeString();
        $expire_at = now()->addYear()->toDateTimeString();
        $encryptedData = Crypt::encrypt([
            'user_id' => $userId,
            'card_serial' => $serialNumber,
            'issue_at' => $issue_at,
            'expire_at' => $expire_at,
        ]);

        // Kích hoạt thẻ
        $activatedCard = UserOtpCard::create([
            'user_id' => $userId,
            'card_serial' => $serialNumber,
            'issue_at' => $issue_at,
            'status' => 'active',
            'card_token' => $encryptedData,
            'expire_at' => $expire_at,
            'created_at' => now(),
            'updated_at' => now(),
            'printed' => $boolean
        ]);

        return $activatedCard;
    }

    //require admin permission
    public function bind($userId, $serial)
    {
        //find user by userId
        $user = User::where('id', $userId)->first();
        if (!$user) {
            throw new \Exception("User $userId not found", 404);
        }
        //find tokenCard with $serial
        $tokenCard = UserOtpCard::where('card_serial', $serial)
            ->first();
        if (!$tokenCard) {
            $tokenCard = UserOtpCard::create([
                'user_id' => null,
                'card_serial' => $serial,
                'issue_at' =>null,
                'status' => 'available',
                'card_token' => null,
                'expire_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if (!$tokenCard->isAvailable()) {
            throw new \Exception("Card $serial not available", 404);
        }

        //deactivate old card if exists and assign new card
        if ($user->otpCard) {
            $user->otpCard->update(['status' => 'deactivated']);
        }
        $tokenCard->update([
            'user_id' => $user->id,
            'status' => 'active',
            'issue_at' => now(),
            'expire_at' => now()->addYear(),
        ]);

        //generate card_token
        $tokenCard->update([
            'card_token' => $tokenCard->generateCardToken(),
        ]);

        return $tokenCard;
    }

    /**
     * Hiển thị danh sách các thẻ OTP dựa trên trạng thái và các yêu cầu khác của người dùng.
     *
     * @param string|null $status       Trạng thái của thẻ OTP (hoặc null nếu không có).
     * @param int         $limit        Số lượng thẻ hiển thị trên mỗi trang.
     * @param string      $orderBy      Trường để sắp xếp theo.
     * @param string      $orderDirection Hướng sắp xếp (asc hoặc desc).
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function show($status, $limit, $orderBy, $orderDirection)
    {
        // Nếu không có trạng thái, hiển thị tất cả các thẻ và sắp xếp theo yêu cầu
        if (!$status) {
            return UserOtpCard::orderBy($orderBy, $orderDirection)->paginate($limit);
        }

        // Nếu có trạng thái, chỉ hiển thị các thẻ có trạng thái tương ứng và sắp xếp theo yêu cầu
        return UserOtpCard::where('status', $status)
            ->orderBy($orderBy, $orderDirection)
            ->paginate($limit);
    }


    public function search($searchTerm, $limit, $orderBy, $orderDirection)
    {
        // Bắt đầu một truy vấn mới
        $query = UserOtpCard::query();

        // Thêm điều kiện tìm kiếm vào truy vấn
        $query->where('card_serial', 'like', "%$searchTerm%")
            ->orWhere('user_id', 'like', "%$searchTerm%")
            ->orWhere('status', 'like', "%$searchTerm%");

        // Sắp xếp kết quả và phân trang
        return $query->orderBy($orderBy, $orderDirection)->paginate($limit);
    }






    private function isAuthenticated($data)
    {
        $user = Auth::user();
        return $user->id == $data['customer_id'] && $user->isActive();
    }

    //    private function validateData(Request $request)
//    {
//        return $request->validate([
//            'customer_id' => 'required',
//            'method' => 'required',
//            'stt' => 'required',
//            'token' => 'required',
//        ]);
//    }
    public function verifyOTP($validatedData)
    {
        if ($validatedData['method'] != 'card') {
            throw new \Exception("Invalid OTP type");
        }

        if (!$this->isAuthenticated($validatedData)) {
            throw new AuthenticationException("Invalid user");
        }


        //find the otpCard by userID
        $otpCard = UserOtpCard::where('user_id', $validatedData['customer_id'])
            ->where('status', 'active')
            ->first();
        if (!$otpCard) {
            throw new \Exception("The user does not have any active OTP card");
        }

        //validate the otp card is not edited
        if (!$otpCard->validate()) {
            throw new \Exception("OTP card has been modified");
        }

        //find the corresponding token
        $token = UserCardOtpToken::where('serial_number', $otpCard->card_serial)
            ->where('stt', $validatedData['stt'])
            ->where('token', $validatedData['token'])
            ->first();

        if (!$token) {
            throw new \Exception("Wrong OTP");
        }

        return isset($token);
    }

    public function updatePrinted($serialNumber) {
        $otpCard = UserOtpCard::where('card_serial', $serialNumber)->first();
        if ($otpCard) {
            $otpCard->update([
                'printed' => true,
                'updated_at' => now()
            ]);
        }
        return $otpCard;
    }

    public function updateStatus($serialNumber, $status) {

        $otpCard = UserOtpCard::where('card_serial', $serialNumber)->first();
        if ($otpCard) {
            $otpCard->update([
                'status' => $status,
                'updated_at' => now()
            ]);
        }
        return $otpCard;
    }

    public function fetchSingleCard($id) {
        $otpCard = UserOtpCard::where('id', $id)->first();
        return $otpCard;
    }

}
