<?php

namespace Marvel\Database\Repositories;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Marvel\Database\Models\User;
use Marvel\Enums\Role;
use Marvel\Enums\Permission;
use RuntimeException;
class AccountTomiruRepository extends BaseRepository
{

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
    public function processLogin($user_id, $secret)
    {
        // Xác thực người dùng hiện tại
        $user = Auth::user();

        // Kiểm tra xác thực người dùng
        if (!$user) {
            throw new AuthorizationException("User not authenticated");
        }

        // Lấy thông tin người dùng từ cơ sở dữ liệu
        $tomiruUser = User::findOrFail($user_id);

        // So sánh user_id gửi từ client với user_id của người dùng được xác thực
        if ($user->id != $user_id) {
            throw new AuthorizationException("Invalid user ID");
        }

        // Tạo chuỗi mã hóa từ thông tin người dùng
        $userEncrypt = [
            "user_id" => $tomiruUser->id,
            "email" => $tomiruUser->email
        ];
        $encodedData = json_encode($userEncrypt);
        $encryptToken = openssl_encrypt($encodedData, 'AES-256-CBC', env('SECRET_KEY_AES256'), 0, env('CREATE_TOKEN_IV'));

        // So sánh chuỗi mã hóa với secret được gửi từ client
        if ($encryptToken !== $secret) {
            throw new RuntimeException("Invalid token");
        }

        // Kiểm tra và cấp quyền cho user nếu cần
        if (!$tomiruUser->hasPermissionTo(Permission::CUSTOMER)) {
            $tomiruUser->givePermissionTo(Permission::CUSTOMER);
            $tomiruUser->assignRole(Role::CUSTOMER);
        }

        // Trả về thông tin cần thiết sau khi đăng nhập thành công
        return [
            "token" => $tomiruUser->createToken('auth_token')->plainTextToken,
            "permissions" => $tomiruUser->getPermissionNames(),
            "email_verified" => $tomiruUser->getEmailVerifiedAttribute(),
            "role" => $tomiruUser->getRoleNames()->first()
        ];
    }






    public function model()
    {
        return User::class;
    }
}


