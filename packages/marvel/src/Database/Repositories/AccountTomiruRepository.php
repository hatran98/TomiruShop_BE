<?php

namespace Marvel\Database\Repositories;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Marvel\Database\Models\User;
use Marvel\Enums\Role;
use Marvel\Enums\Permission;
use RuntimeException;
class AccountTomiruRepository extends BaseRepository
{


    public function processLogin($tokenApp ,$hashTokenShop)
    {
        $decryptToken = (json_decode(base64_decode(str_replace('_', '/', str_replace('-','+',explode('.', $tokenApp)[1])))));
        if($decryptToken->sub !== null) {
            $tomiruUser = User::where('id', $decryptToken->sub)->first();
            if ($tomiruUser->jwt_token !== $tokenApp && $tomiruUser->hash_token !== $hashTokenShop) {
                return response()->json(['error' => 'Missing required parameters'], 400);
            }
            if ($tomiruUser->jwt_token_shop === null) {
                return response()->json(['error' => 'TokenShop is invalid ,null'], 400);
            }
        return [
            'tokenShop' => $tomiruUser->jwt_token_shop
        ];
        }
        return response()->json(['error' => 'Token is invalid'], 400);
    }



    public function checkToken($tokenApp) {
        $decryptToken = (json_decode(base64_decode(str_replace('_', '/', str_replace('-','+',explode('.', $tokenApp)[1])))));
        if($decryptToken->sub !== null) {
            $tomiruUser = User::where('id', $decryptToken->sub)->first();
            if ($tomiruUser->jwt_token !== $tokenApp) {
                return response()->json(['error' => 'Missing required parameters'], 400);
            }
            if (!$tomiruUser->hasPermissionTo(Permission::CUSTOMER)) {
                $tomiruUser->givePermissionTo(Permission::CUSTOMER);
                $tomiruUser->assignRole(Role::CUSTOMER);
            }

            $tokenShop = $tomiruUser->createToken('auth_token')->plainTextToken;
            $hashTokenShop = Hash::make($tokenShop);
            $tomiruUser->update(['jwt_token_shop' => $tokenShop, 'hash_token' => $hashTokenShop]);


            return [
                'hashTokenShop' => $hashTokenShop
            ];
        }
        return response()->json(['error' => 'Token is invalid'], 400);

    }



    public function model()
    {
        return User::class;
    }
}


