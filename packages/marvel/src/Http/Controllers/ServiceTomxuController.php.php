<?php

namespace Marvel\Http\Controllers;

use Carbon\Carbon;
use DateTime;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Response;
use Marvel\Database\Models\OTP;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Tomxu;
use Marvel\Database\Models\UsersBalance;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\Balance;
use Marvel\Database\Models\UsersTransaction;
use Marvel\Database\Models\OrderProduct;
use Illuminate\Support\Facades\Queue;
class ServiceTomxuController extends CoreController
{
    private int $clientId ;
    private string $secretKey ;
    private string $secretIV ;

    public function __construct()
    {
        $this->clientId = 458875;
        $this->secretKey = '12345678901234567890123456789012';
//        $this->secretKey = env(SECRET_KEY);
        $this->secretIV = '1234567890123456';
//        $this->secretIV = env(SECRET_IV);
    }
    public function checkAuth($user_id, $user_email, $clientId, $secret_token):bool|object
    {
        $user = Auth::user();
        if (!$user || $user->id != $user_id) {
            return false;
        };
        if ($user['is_active'] != 1) {
            return false;
        };
        //check email
        if ($user->email != $user_email) {
            return false;
        }
        //clientId
        if($clientId != $this->clientId){
            return false;
        }
        //check secret
        $secret= $this->encrypted($clientId . $user->id . $user->email);
        if($secret_token != $secret){
            return false;
        }
        return $user;
    }
    public function isMakeTransaction($user_id, $type): array|bool
    {
        $userBalance = UsersBalance::where('user_id', $user_id)
            ->where('token_id', 1)
            ->first();
        //check  balance is_locked?
        if (!$userBalance || $userBalance->is_locked == 1) {
            return ['message'=> 'Your balance not exist or is locked'];
        }
        if ($type != 'verify_order') {
            return ['message'=> 'Type otp not match'];
        }
        return true;
    }
    public function createOtp($userId, $type)
    {
        $random_otp = mt_rand(100000, 999999);
        $otp = OTP::create([
            'type' => $type,
            'user_id' => $userId,
            'otp' => $random_otp,
            'created_at' => now(),
            'updated_at' => now()
        ]);
        return $otp->otp;
    }
    public function decrypted($encrypt): string
    {
        $secretKey = '12345678901234567890123456789012';
        $secretIV = '1234567890123456';
        $encMethod = 'aes-256-cbc';

        return openssl_decrypt($encrypt, $encMethod, $secretKey, 0,$secretIV);
    }
    public function encrypted($data): string
    {
        $secretKey = $this->secretKey;
        $secretIV = $this->secretIV;
        $encMethod = 'aes-256-cbc';
        return openssl_encrypt(json_encode($data), $encMethod, $secretKey, 0, $secretIV);
    }

    public function requestOtp(Request $request)
    {
        $validatedData = $request->validate([
            'user_id' => 'required',
            'user_email' => 'required|email',
            'secret_token' => 'required',
            'type_otp' => 'required',
            'total_tomxu' => 'required',
        ]);

//        return response(['message' => 'Failed to create OTP. Please retry', 'status' => false], 422);//
        $user_email = $validatedData['user_email'];
        $user_id = $validatedData['user_id'];
        $clientId = $request->header('clientId');
        $secret_token = $validatedData['secret_token'];

        //check Auth , check clientKey and secret_token
        $user = $this->checkAuth($user_id, $user_email, $clientId, $secret_token);
        if (!$user) {
            return response(['message' => 'Unauthorized', 'status' => false], 401);
        }

        //check domain
        $domain = $request->header('domain');
        //$domain = $request->getHost();
        if ($domain !== 'shop.tomiru.com') {
            return response(['message' => 'Unsupported', 'status' => false], 422);
        }

        //check balance is_locked?
        $isMakeTransaction = $this->isMakeTransaction($user_id, $validatedData['type_otp']);
        if (isset($isMakeTransaction['message'])) {
            return response(['message' => $isMakeTransaction['message'], 'status' => false], 422);
        }

        //check balance has more than total tomxu of order
        $userBalance = UsersBalance::where('user_id', $user_id)
            ->where('token_id', 1)
            ->first();
        if ($userBalance->balance < $validatedData['total_tomxu']) {
            return response(['message' => 'Insufficient balance to complete the transaction', 'status' => false], 422);
        }

        //Generate OTP
        $otp = $this->createOtp($user_id, 'verify_order');
        if (!$otp) {
            return response(['message' => 'Failed to create OTP. Please retry', 'status' => false], 422);//500
        }

        //send email
        $data =[
            'user_id'=>$user_id,
            'user_email'=>$user_email,
            'otp'=>$otp,
            'type'=> 'otpOrder',
            'template'=>'otpOrder'
        ];
        $dataEncrypted = $this->encrypted($data) ;

        $this->callApi('http://192.168.102.11:8080/api/sendmail/otpOrder',$dataEncrypted,  'tomiruHaDong');

        // response to user
        return response(['message' => 'OTP has sent your email', 'status' => true], 201);

    }

    public function confirmTransactionWithOTP(Request $request)
    {
        $validatedData = $request->validate([
            'from_id' => 'required',
            'from_user_email' => 'required|email',
            'type_otp' => 'required',
            'tracking_number' => 'required',
            'secret_token' =>'required|string',
            'total_tomxu' => 'required',
            'otp' => 'required',
            'products' => 'required|array',
            'products.*.product_id' => 'required',
            'products.*.shop_id' => 'required',
            'products.*.quantity' => 'required|integer',
            'products.*.tomxu' => 'required',
            'products.*.tomxu_subtotal' => 'required',
        ]);

        $from_id = $validatedData['from_id'];
        $from_user_email = $validatedData['from_user_email'];
        $clientId = $request->header('clientId');
        $secret_token = $validatedData['secret_token'];

        //check domain
        $domain = $request->header('domain');
        //         $domain = $request->getHost();
        if ($domain !== 'shop.tomiru.com') {
            return response(['message' => 'Unsupported', 'status' => false], 405);
        }

        //check Auth, check clientKey and secret_token
        $user = $this->checkAuth($from_id, $from_user_email, $clientId, $secret_token);
        if (!$user) {
            return response(['message' => 'Unauthorized', 'status' => false], 401);
        }

        //verify otp
        $isOtp = $this->verifyOTP($user,$validatedData['otp']);
        if(!$isOtp){
            return response(['message' => 'OTP incorrect', 'status' => false], 400);
        }

        //check balance is locked ?
        $isMakeTransaction = $this->isMakeTransaction($from_id, $validatedData['type_otp'], $validatedData['tracking_number']);
        if (isset($isMakeTransaction['message'])) {
            return response(['message' => $isMakeTransaction['message'], 'status' => false], 403);
        }

        //check order of user requests
        $order = Order::where('tracking_number', $validatedData['tracking_number'])
            ->where('order_status', 'order-pending')
            ->where('payment_status', 'payment-pending')
            ->first();
        if (!$order || $order->customer_id != $from_id || $order->total_tomxu != $validatedData['total_tomxu']) {
            return response(['message' => 'You cannot make this transaction', 'status' => false], 403);
        }
        $userBalance = UsersBalance::where('user_id', $from_id)->where('token_id', 1)->first();
        $preBalance =$userBalance->balance;
        if ($preBalance < $validatedData['total_tomxu']) {
            return response(['message' => 'Insufficient balance to complete the transaction', 'status' => false], 403);
        }

        //check information order_product
        $products = $validatedData['products'];

        //check Seller
        $sellers = $this->checkSellerAndGetTomxuForSeller($products,$user);
        if (isset($sellers['message'])) {
            return response(['message' => $sellers['message'], 'status' => false], 403);
        }
        //check if order_product request matches order_product in DB
        $isCheckInfOrderProducts = $this->checkInfOrderProducts($products, $order);
        if (isset($isCheckInfOrderProducts['message'])) {
            return response(['message' => $isCheckInfOrderProducts['message'], 'status' => false], 403);
        }

        //create transaction
        try {
            DB::transaction(function () use ($order, $user, $sellers, $products) {

                //update payment_status of order
                $order->update([
                    'payment_status' => 'payment-success',
                    'updated_at' => now(),
                ]);

                //create transaction for seller and buyer
                $this->updateBalanceAndCreateTransaction($user, $sellers, $order);

                //update quantity,sold_quantity for product
                 foreach ($products as $product) {
                    $updateProduct = Product::where('id', $product['product_id'])->lockForUpdate()->firstOrFail();
                    if ($updateProduct->quantity < $product['quantity']) {
                        throw new \Exception('Not enough quantity of products');
                    }
                    $currentQuantity = $updateProduct->quantity;
                    $sold_quantity = $updateProduct->sold_quantity;
                    $updateProduct->update([
                        'quantity' => $currentQuantity - floatval($product['quantity']),
                        'sold_quantity' => $sold_quantity + floatval($product['quantity']),
                        'updated_at' => now(),
                    ]);
                }

            });
            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            return response(['message' => $e->getMessage(), 'status' => false], 403);
        }

        //call server send mail
        $ids = UsersTransaction::where('order_id',$order->id)->where('type','payment_order_tomxu')->pluck('id')->toArray();

        //data of buyer to send mail
        $buyer = [
            'buyer_id' => $user->id,
            'buyer_email' => $user->email,
            'title' => 'Payment Confirmation',
            'tracking_number' => $order->tracking_number,
            'tomxu_paid' => $order->total_tomxu,
            'pre_balance' => $preBalance,
            'post_balance' =>  $preBalance - floatval($order->total_tomxu),
            'id_transactions' => $ids,
        ];

        //data of seller to send mail
        $infSellers = [];

        foreach ($sellers as $seller){
            $id = UsersTransaction::where('order_id',$order->id)->where('type','receive_order_payment_tomxu')->pluck('id')->first();
            $infSeller = [
                'seller_id' => $seller['sellerId'],
                'seller_email' => $seller['email'],
                'title' => 'Payment received',
                'tracking_number' => $order->tracking_number,
                'tomxu_paid' => $seller['tomxuAdd'],
                'pre_balance' =>  $seller['pre_balance'],
                'post_balance' => floatval($seller['pre_balance']) + floatval($seller['tomxuAdd']),
                'id_transaction' => $id,
            ];

            $infSellers[]= $infSeller;
        }

        $data = [
            'buyer' => $buyer,
            'seller'=>$infSellers,
            'template' => 'ecommerce',
            'type' => 'ecommerce'
        ];

        $dataEncrypted = $this->encrypted($data);
        $this->callApi('http://192.168.102.11:8080/api/sendmail/ecommerce',$dataEncrypted,  'tomiruHaDong');


        //response to user
        return response([
            'message' => 'Transaction success.',
            'status' => true,
            'data' =>[
                'tracking_number'=>$order->tracking_number,
                'tomxu_paid' => $order->total_tomxu,
                'pre_balance' =>  $preBalance,
                'post_balance' => $preBalance + floatval($order->total_tomxu),
                'id_transactions' => $ids
            ]
        ], 200);
    }

    public function verifyOTP($user,$otp): bool
    {
        $currentOtp = OTP::where('user_id',$user->id)->where('type','verify_order')->latest('created_at')->first();
        if($currentOtp->otp != $otp){
            return false;
        }
        $currentTime =  Carbon::now();
        $createdAt =Carbon::parse($currentOtp->created_at);
        if($createdAt->diffInMinutes($currentTime) > 10000){
            return false;
        }
        return true;
    }
    public function checkSellerAndGetTomxuForSeller($products,$user): array|object
    {
        $sellers = [];
        foreach ($products as $product) {
            //check  shop is exist and is_active ?
            $shop = Shop::where('id', $product['shop_id'])->where('is_active', 1)->first();
            if (!$shop) {
                return ['message'=> 'Shop not exist'];
            }
            //check  seller exist and , is_active and email verify ?
            $sellerId = $shop->owner_id;
            //check  user has buy item yourself
            if($sellerId == $user->id){
                return ['message'=> 'You cannot buy your own products'];
            }
            $seller = User::where('id', $sellerId)->where('is_active', 1)->first();
            if (!$seller || !$seller->hasVerifiedEmail()) {
                return ['message'=> 'Has error from seller'];
            }
            //check balance have is_locked?
            $sellerBalance = UsersBalance::where('user_id', $sellerId)->where('token_id', 1)->first();
            if (!$sellerBalance || $sellerBalance->is_locked == 1) {
                return ['message'=> 'Has error from seller'];
            }
            $pre_balance = $sellerBalance->balance;
            //add tôtal tomxu for seller
            $tomxuOfOrderProduct = floatval($product['tomxu_subtotal']);
            if (isset($sellers[$sellerId])) {
                $sellers[$sellerId]['tomxuAdd'] += $tomxuOfOrderProduct;
            } else {
                $sellers[$sellerId] = [
                    'sellerId' => $sellerId,
                    'email' => $seller->email,
                    'tomxuAdd' => $tomxuOfOrderProduct,
                    'pre_balance' => $pre_balance,
                ];
            }
        }
        return array_values($sellers);
    }

    public function checkInfOrderProducts($products, $order): bool|array
    {
        $orderId = $order->id;
        //check count
        if (count($products) != count($order->products)) {
            return ['message'=> 'Quantity of order product not match'];
        }
        foreach ($products as $product) {

            //check quantity of product less than zero ?
            if (floatval($product['quantity']) < 0) {
                return ['message'=> 'Quantity of product less than zero'];
            }

            //check if product_order  request match product_order in DB
            $correspondingProduct = OrderProduct::where('order_id', $orderId)
                ->where('product_id', $product['product_id'])
                ->where('order_quantity', $product['quantity'])
                ->where('tomxu', $product['tomxu'])
                ->where('tomxu_subtotal', $product['tomxu_subtotal'])
                ->first();
            if (!$correspondingProduct) {
                return ['message'=> 'Order product not exist'];
            }
        }
        return true;
    }

    public function updateBalanceAndCreateTransaction($user, $sellers, $order): void
    {
        foreach ($sellers as $seller) {
            // update for buyer
            $userBalanceSend = UsersBalance::where('user_id', $user->id)
                ->where('token_id', 1)
                ->lockForUpdate()
                ->first();
            $currentBalanceSend = floatval($userBalanceSend->balance);
            $newBalanceSend = $currentBalanceSend - floatval($seller['tomxuAdd']);
            $userBalanceSend->update([
                'balance' => $newBalanceSend,
                'updated_at' => now(),
            ]);
            //'payment_order_tomxu'
            $sendTransaction = UsersTransaction::create([
                'type' => 29,
                'user_id' => $user->id,
                'token_id' => 1,
                "order_id" => $order->id,
                'from_id' => $user->id,
                'to_id' => $seller['sellerId'],
                'status' => 'success',
                'value' => floatval($seller['tomxuAdd']),
                'pre_balance' => $currentBalanceSend,
                'post_balance' => $newBalanceSend,
                'updated_at' => now(),
                'created_at' => now(),
            ]);

            // update for seller
            $userBalanceReceive = UsersBalance::where('user_id', $seller['sellerId'])
                ->where('token_id', 1)
                ->lockForUpdate()
                ->first();
            $currentBalanceReceive = floatval($userBalanceReceive->balance);
            $newBalanceReceive = $currentBalanceReceive + floatval($seller['tomxuAdd']);
            $userBalanceReceive->update([
                'balance' => $newBalanceReceive,
                'updated_at' => now(),
            ]);
            //           'receive_order_payment_tomxu'
            $receiveTransaction = UsersTransaction::create([
                'type' => 30,
                'user_id' => $seller['sellerId'],
                'token_id' => 1,
                'to_id' => $user->id,
                "order_id" => $order->id,
                'from_id' => $seller['sellerId'],
                'status' => 'success',
                'value' => floatval($seller['tomxuAdd']),
                'pre_balance' => $currentBalanceReceive,
                'post_balance' => $newBalanceReceive,
                'updated_at' => now(),
                'created_at' => now(),
            ]);

        }
    }
    private function callApi($url, $content, $clientId)
    {
        // Tạo header cho yêu cầu
        $headers = [
            'Content-Type: application/json',
            'clientid: ' . $clientId,
        ];

        // Khởi tạo một yêu cầu cURL
        $ch = curl_init($url);
        $data = ['content' => $content];
        // Thiết lập các tùy chọn cho yêu cầu
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS,json_encode($data) );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Thực hiện yêu cầu cURL và lấy kết quả
        $response = curl_exec($ch);

        // Kiểm tra lỗi nếu có
        if ($response === false) {
            $error = curl_error($ch);
            // Xử lý lỗi ở đây, có thể ghi log hoặc trả về một phản hồi lỗi
            return $error;
        }

        // Đóng phiên cURL
        curl_close($ch);

        // Trả về dữ liệu phản hồi
        return $response;
    }

    public function balanceTomxu(Request $request): array
    {

        $validatedData = $request->validate([
            'customer_id' => 'required',
            'email' => 'required',
            'type' => 'required',
            'secret_token' => 'required',
        ]);
        $secret_token = $validatedData['secret_token'];
        $clientId = $request->header('clientId');

        $user = $this->checkAuth($validatedData['customer_id'],$validatedData['email'],$clientId,$secret_token);
        if (!$user) {
            return ['message' => 'Unauthorized', 'success' => false];
        }
        $usersBalance = UsersBalance::where('user_id', $validatedData['customer_id'])
            ->where('token_id', $validatedData['type'])
            ->first();
        return [
            'message' => 'Get data success',
            'success' => true,
            'data' => [
                'id' => $validatedData['customer_id'],
                'balance' => $usersBalance->balance,
            ]
        ];
    }
}
