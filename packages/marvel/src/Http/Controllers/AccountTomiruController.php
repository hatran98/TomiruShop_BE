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
       $token = request()->query('token');
       $user_id = request()->query('user_id');
       $sceret = request()->query('sceret');
        $processedData = $this->accountTomiruRepository->processLogin($token , $user_id , $sceret);
        return $processedData;
    }


}

