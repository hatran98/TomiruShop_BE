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
        // Lấy các tham số từ của request
        $tokenApp = $request->input('tokenApp');
        $hashtokenshop  =$request -> input('hashtokenshop');

        // Kiểm tra xác thực của các tham số
        if (!$tokenApp || !$hashtokenshop) {
            return response()->json(['error' => 'Missing required parameters'], 400);
        }

        // Gọi phương thức processLogin của repository để xử lý đăng nhập
        $processedData = $this->accountTomiruRepository->processLogin($tokenApp);

        return $processedData;
    }

public function checkToken(Request $request) {
        $tokenApp = $request -> input('tokenApp');

        if (!$tokenApp) {
            return response()->json(['error' => 'Missing required parameters'], 400);
        }

        $processedData = $this->accountTomiruRepository->checkToken($tokenApp);

        return $processedData;
}




}

