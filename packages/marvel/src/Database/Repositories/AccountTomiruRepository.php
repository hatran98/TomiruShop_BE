<?php

namespace Marvel\Database\Repositories;

use Marvel\Database\Models\User;
use Marvel\Enums\Role;
use Marvel\Enums\Permission;
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
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
    public function processLogin($token, $user_id, $secret)
    {
        // Xử lý chuỗi secret để loại bỏ ký tự không hợp lệ
        $secret = str_replace(' ', '+', $secret);

        // Kiểm tra xem các tham số đầu vào có tồn tại không
        if ($token && $user_id && $secret) {
            // Giải mã secret bằng AES-256-ECB
            $decryptedSecret = openssl_decrypt($secret, "AES-256-ECB", env('SECRET_KEY_AES256'));

            // Kiểm tra xem secret giải mã có khớp với token không
            if ($decryptedSecret == $token) {
                // Decode thông tin từ token
                $decodedToken = json_decode(base64_decode(str_replace('_', '/', str_replace('-', '+', explode('.', $decryptedSecret)[1]))));

                // Kiểm tra xem user_id từ token có khớp với user_id được cung cấp không
                if ($decodedToken->sub == $user_id) {
                    // Lấy thông tin user từ database
                    $tomiruUser = User::where('id', $user_id)->firstOrFail();

                    // Kiểm tra xem user có quyền CUSTOMER hay không
                    if (!$tomiruUser->hasPermissionTo(Permission::CUSTOMER)) {
                        // Nếu không có, cấp quyền CUSTOMER và gán vai trò CUSTOMER cho user
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
            }
        }

        // Trả về null nếu không thành công
        return null;
    }





    public function model()
    {
        return User::class;
    }
}


