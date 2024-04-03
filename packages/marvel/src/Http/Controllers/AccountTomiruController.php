<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Marvel\Database\Repositories\AccountTomiruRepository;

class AccountTomiruController extends CoreController
{
    protected $accountTomiruRepository;

    public function __construct(AccountTomiruRepository $accountTomiruRepository)
    {
        $this->accountTomiruRepository = $accountTomiruRepository;
    }

    public function login(Request $request)
    {
        // Lấy các tham số từ query string của request
        $user_id = $request->query('user_id');
        $secret = $request->query('secret');

        // Kiểm tra xác thực của các tham số
        if (!$user_id || !$secret) {
            return response()->json(['error' => 'Missing required parameters'], 400);
        }

        // Gọi phương thức processLogin của repository để xử lý đăng nhập
        $processedData = $this->accountTomiruRepository->processLogin($user_id, $secret);

        // Kiểm tra xem processData có thành công không
        if ($processedData) {
            // Nếu thành công, redirect về domain shop.tomiru.com
            return redirect('http://shop.tomiru.com');
        } else {
            // Nếu không thành công, trả về thông báo lỗi
            return response()->json(['error' => 'Login failed'], 400);
        }
    }




}

