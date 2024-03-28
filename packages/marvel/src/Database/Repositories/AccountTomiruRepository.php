<?php

namespace Marvel\Database\Repositories;

use Marvel\Database\Models\User;
use Marvel\Enums\Role;
use Marvel\Enums\Permission;
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
class AccountTomiruRepository extends BaseRepository
{

    public function checkLogin($queryData)
    {
        if (request()->hasCookie('tomiru_user')) {
            $userData = request()->cookie('tomiru_user');
            $user = json_decode($userData);
            $redirectUrl = 'http://shop.tomiru.com';
            return ['redirectUrl' => $redirectUrl, 'userData' => $user];
        } else {
            $redirectUrl = 'http://app.tomiru.com';
            return ['redirectUrl' => $redirectUrl, 'userData' => null];
        }
    }


//    public function processLogin($token , $user_id)
//    {
//        $decode_token = (json_decode(base64_decode(str_replace('_', '/', str_replace('-','+',explode('.', $token)[1])))));
//        $tomiruUser = User::where('id',$decode_token->sub)->first();
//
//        if ($tomiruUser && $decode_token->sub == $user_id) {
//            if (!$tomiruUser->hasPermissionTo(Permission::CUSTOMER)) {
//                $tomiruUser->givePermissionTo(Permission::CUSTOMER);
//                $tomiruUser->assignRole(Role::CUSTOMER);
//            }
//            $url = 'http://127.0.0.1:8000/api/get-token';
//            $params = [
//                'token' => $token,
//            ];
//            $url .= '?' . http_build_query($params);
//
//            $client = new \GuzzleHttp\Client();
//            $response = $client->get($url);
//
//
//            return $response->getBody()->getContents();
//        } else {
//            return redirect('http://app.tomiru.com');
//        }
//    }
//
//
//
//
public function processLogin($token , $user_id , $sceret)
{
    $sceret = str_replace(' ', '+', $sceret);
    if ($token && $user_id && $sceret) {
        $decryptScret = openssl_decrypt($sceret, "AES-256-ECB", env('SECRET_KEY_AES256'));
        if ($decryptScret == $token) {
            $decode = (json_decode(base64_decode(str_replace('_', '/', str_replace('-', '+', explode('.', $decryptScret)[1])))));
            if ($decode->sub == $user_id) {
                $tomiruUser = User::where('id', $user_id)->firstOrFail();
                if (!$tomiruUser->hasPermissionTo(Permission::CUSTOMER)) {
                    $tomiruUser->givePermissionTo(Permission::CUSTOMER);
                    $tomiruUser->assignRole(Role::CUSTOMER);
                }
                return [
                    "token" => $tomiruUser->createToken('auth_token')->plainTextToken,
                    "permissions" => $tomiruUser->getPermissionNames(),
                    "email_verified" => $tomiruUser->getEmailVerifiedAttribute(),
                    "role" => $tomiruUser->getRoleNames()->first()
                ];
            }
        }
    }
}




    public function model()
    {
        return User::class;
    }
}


