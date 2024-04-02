<?php

namespace App\Helpers;

class EncryptionHelper
{
    public static function encrypt($data, $key, $encIv)
    {
        $encryptedData = openssl_encrypt(json_encode($data), 'AES-256-CBC', $key, 0, $encIv);

        return $encryptedData;
    }
}

