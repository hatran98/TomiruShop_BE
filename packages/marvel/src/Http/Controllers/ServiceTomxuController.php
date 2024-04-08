<?php

namespace Marvel\Http\Controllers;

use Carbon\Carbon;
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
use App\Listeners\SendOrderConfirmationEmail;
use Marvel\Database\Repositories\OTPRepository;

/**
 * Class ServiceTomxuController
 *
 * This class is responsible for handling requests related to the Tomxu service.
 * It extends the CoreController class.
 *
 * @package Marvel\Http\Controllers
 */
class ServiceTomxuController extends CoreController
{
    private $clientId;
    private string $secretKey;
    private string $secretIV;

    public function __construct()
    {
        $this->clientId = floatval(env('CLIENT_KEY'));
        $this->secretKey = env('CREATE_TOKEN_KEY');
        $this->secretIV = env('CREATE_TOKEN_IV');
    }
    /**
     * Handles the request for OTP.
     *
     * This method validates the request data, checks the user's authentication and balance,
     * generates an OTP, and sends it to the user's email.
     *
     */
    public function requestOtp(Request $request)
    {

         // Validate the incoming request data
         $validatedData = $request->validate([
             'user_id' => 'required',
             'user_email' => 'required|email',
             'secret_token' => 'required',
             'type_otp' => 'required',
             'method' => 'required',
             'total_tomxu' => 'required',
         ]);

        // Check user authentication and client key
        $user = $this->validateAuth(
            $validatedData['user_id'],
            $validatedData['user_email'],
            $request->header('clientId'),
            $validatedData['secret_token']
        );
        if (!$user) {
            return response(['message' => 'Unauthorized', 'status' => false], 401);
        }
        //check domain
        // $domain = $request->getHost();
        // if ($domain != env('DOMAIN_SHOP')) {
        //     return response(['message' => 'Unsupported', 'status' => false], 403);
        // }

        // Check if the balance is locked.
        $isBuyerBalance  = $this->checkBuyerBalance(
            $validatedData['user_id'],
            $validatedData['type_otp'],
            $validatedData['total_tomxu']
        );
        if (isset($isBuyerBalance ['message'])) {
            return response(['message' => $isBuyerBalance ['message'], 'status' => false], 422);
        }

        //Generate OTP
        $OTPRepository= new OTPRepository();
        $otp = $OTPRepository->createOTP(
            $validatedData['type_otp'],
            $validatedData['method'],
            $validatedData['user_id']
        );
        // Prepare the data to be sent in the email
        $toEmailData = [
            'user_id' => $validatedData['user_id'],
            'user_email' => $validatedData['user_email'],
            'otp' => $otp->otp,
            'type' => 'otpOrder',
            'title' => 'Payment Confirmation',
            'template' => 'otpOrder'
        ];
        // Encrypt the data to be sent in the email
        $encryptedData = $this->encrypt($toEmailData);
        $endPoint = 'api/sendmail/otpOrder';
        SendOrderConfirmationEmail::dispatch($encryptedData, $endPoint);

        // Return a success response
        return response(['message' => 'OTP has sent your email', 'status' => true], 201);

    }
    /**
     * Confirm order payment by OTP.
     *
     * This method validates the request data, checks the user's authentication,
     * verifies the OTP, checks the order and sellers, validates the order products,
     * and processes the order payments.
     *
     * @param Request $request The incoming HTTP request.
     */
    public function confirmOrderPaymentWithOTP(Request $request)
    {
        // Validate the incoming request data
        $validatedData = $request->validate([
            'from_id' => 'required',
            'from_user_email' => 'required|email',
            'type_otp' => 'required',
            'tracking_number' => 'required',
            'secret_token' => 'required|string',
            'total_tomxu' => 'required',
            'otp' => 'required',
            'products' => 'required|array',
            'products.*.product_id' => 'required',
            'products.*.shop_id' => 'required',
            'products.*.quantity' => 'required|integer',
            'products.*.tomxu' => 'required',
            'products.*.tomxu_subtotal' => 'required',
        ]);

        $fromId = $validatedData['from_id'];
        $fromUserEmail = $validatedData['from_user_email'];
        $clientId = $request->header('clientId');
        $secretToken = $validatedData['secret_token'];

        //validate domain
        // $domain = $request->getHost();
        // if ($domain != env('DOMAIN_SHOP')) {
        //     return response(['message' => 'Unsupported', 'status' => false], 405);
        // }

        // Validate the user's authentication
        $user = $this->validateAuth($fromId, $fromUserEmail, $clientId, $secretToken);
        if (!$user) {
            return response(['message' => 'Unauthorized', 'status' => false], 401);
        }

        // Verify the OTP
        if ($validatedData['type_otp'] != 'verify_order') {
            return response(['message' => 'Invalid OTP type', 'status' => false], 422);
        }
        $OTPRepository= new OTPRepository();
        $isOtpValid = $OTPRepository->verifyOtp($user, $validatedData['otp'], 'verify_order');
//        if (!$isOtpValid) {
//            return response(['message' => 'OTP incorrect', 'status' => false], 422);
//        }

        // Check the balance for the buyer
        $balanceBuyer = $this->checkBuyerBalance($fromId, $validatedData['total_tomxu']);
        if (isset($isBalanceLockedForBuyer ['message'])) {
            return response(['message' => $balanceBuyer ['message'], 'status' => false], 422);
        }
        $preBalance = $balanceBuyer['balance'];

        // Check the order of user requests
        $order = Order::where('tracking_number', $validatedData['tracking_number'])
            ->where('order_status', 'order-pending')
            ->where('total_tomxu', $validatedData['total_tomxu'])
            ->where('customer_id', $fromId)
            ->where('payment_status', 'payment-pending')
            ->first();

        if (!$order) {
            return response(['message' => 'You cannot make this transaction', 'status' => false], 422);
        }

        // Check sellers and calculate total tomxu receipt.
        $products = $validatedData['products'];
        $sellers = $this->checkSellersAndCalculateTotalTomxuReceipt($products, $user);
        return $sellers;
        if (isset($sellers['message'])) {
            return response(['message' => $sellers['message'], 'status' => false], 422);
        }
        if(count($sellers) == 0){
            return response(['message' => 'No seller found', 'status' => false], 422);
        }

        //check if order_product request matches order_product in DB
        $isOrderProductsValid = $this->validateOrderProducts($products, $order);
        if (isset($isOrderProductsValid['message'])) {
            return response(['message' => $isOrderProductsValid['message'], 'status' => false], 422);
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
                $this->processOrderPayments($user, $sellers, $order);

                //update quantity,sold_quantity for product
                $this->updateProductQuantity($products);

            });
            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            return response(['message' => $e->getMessage(), 'status' => false], 422);
        }

        //send mail
        $data = $this->getBuyerAndSellerDataToSendMail($order, $user, $preBalance, $sellers);
        $dataEncrypted = $this->encrypt($data);
        $endpoint = 'api/sendmail/ecommerce';
        SendOrderConfirmationEmail::dispatch($dataEncrypted, $endpoint);

        //response to user
        return response([
            'message' => 'Transaction success.',
            'status' => true,
            'data' => [
                'buyer_id' => $user->id,
                'buyer_email' => $user->email,
                'tracking_number' => $order->tracking_number,
                'tomxu_paid' => $order->total_tomxu,
                'pre_balance' => $preBalance,
                'post_balance' => $preBalance - $this->floatval($order->total_tomxu),
            ]
        ], 200);
    }

    public function getBalanceTomxu(Request $request)
    {

        $validatedData = $request->validate([
            'customer_id' => 'required',
            'email' => 'required',
            'type' => 'required',
            'secret_token' => 'required',
        ]);
        $secret_token = $validatedData['secret_token'];
        $clientId = $request->header('clientId');

        $user = $this->validateAuth($validatedData['customer_id'], $validatedData['email'], $clientId, $secret_token);
        if (!$user) {
            return response(['message' => 'Unauthorized', 'success' => false], 403);
        }
        $usersBalance = UsersBalance::where('user_id', $validatedData['customer_id'])
            ->where('token_id', floatval($validatedData['type']))
            ->first();
        return response([
            'message' => 'Get success',
            'success' => true,
            'data' => [
                'id' => $validatedData['customer_id'],
                'balance' => $usersBalance->balance,
            ]
        ], 200);
    }

    /**
     * This method is used to validate the authentication of a user.
     *
     * @param int $user_id The ID of the user to be authenticated.
     * @param string $user_email The email of the user to be authenticated.
     * @param int $clientId The client ID from the request header.
     * @param string $secret_token The secret token from the request.
     *
     * @return bool|object Returns the authenticated user object if the user is authenticated successfully, otherwise returns false.
     */
    protected function validateAuth( $user_id, $user_email, $clientId, $secret_token): bool|object
    {
        $user = Auth::user();
        if (!$user || $user->id != $user_id) {
            return false;
        };
        if ($user['status'] == 'locked') {
            return false;
        };
        if ($user->email != $user_email) {
            return false;
        }
        if ($clientId != $this->encrypt($this->clientId)) {
            return false;
        }
        $secret = $this->encrypt($this->clientId . $user->id . $user->email);
        if ($secret_token != $secret) {
            return false;
        }
        return $user;
    }

    /**
     * Checks the balance for the buyer.
     *
     * This method checks if the buyer's balance exists and is not locked.
     * It also checks if the buyer's balance is sufficient to complete the transaction.
     * Finally, it checks if the type of OTP matches 'verify_order'.
     *
     * @param int $user_id The ID of the user whose balance is to be checked.
     * @param float $total_tomxu The total amount of Tomxu for the transaction.
     *
     * @return array|object Returns the user's balance if all checks pass, otherwise returns an array with a 'message' key.
     */
    protected function checkBuyerBalance(int $user_id, float $total_tomxu): array|object
    {
        $userBalance = UsersBalance::where('user_id', $user_id)
            ->where('token_id', 1)
            ->firstOrFail();
        //check  balance is_locked?
        if ($userBalance->is_locked == 1) {
            return ['message' => 'Your balance not exist or is locked'];
        }
         //check balance has more than total tomxu of order
        if ($userBalance->balance < $total_tomxu) {
            return ['message' => 'Insufficient balance to complete the transaction'];
        }
        return $userBalance;
    }

    protected function decrypt($encrypted): string
    {

        return openssl_decrypt($encrypted, 'aes-256-cbc', $this->secretKey, 0, $this->secretIV);
    }


    protected function encrypt($data): string
    {
        return openssl_encrypt(json_encode($data), 'aes-256-cbc', $this->secretKey, 0, $this->secretIV);
    }

    /**
     * Checks sellers and calculates total Tomxu receipt.
     *
     * This method iterates over each product in the provided products array.
     * For each product, it checks if the shop exists and is active, and if the seller exists, is active, and has a verified email.
     * It also checks if the seller's balance is not locked and if the buyer is not buying their own products.
     * Then, it calculates the total Tomxu for each seller by adding the Tomxu of each product.
     * If a seller already exists in the sellers array, it adds the Tomxu of the current product to the existing total Tomxu for that seller.
     * Otherwise, it creates a new entry in the sellers array for the new seller and initializes their total Tomxu with the Tomxu of the current product.
     *
     * @param array $products The array of products involved in the transaction. Each product is an associative array with keys 'product_id', 'shop_id', 'quantity', 'tomxu', and 'tomxu_subtotal'.
     * @param object $user The user object representing the buyer.
     *
     * @return array|object Returns an array of sellers if all checks pass, otherwise returns an array with a 'message' key.
     */
    protected function checkSellersAndCalculateTotalTomxuReceipt(array $products, object $user): array|object
    {
        // Get all unique shop IDs from the products
        $shopIds = array_unique(array_column($products, 'shop_id'));

        // Fetch all shops and their owners
        $shops = Shop::whereIn('id', $shopIds)
            ->with('owner')
            ->get()
            ->keyBy('id');
        // Fetch all sellers' balances
        $sellerIds = $shops->pluck('owner_id')->toArray();
        $sellerBalances = UsersBalance::whereIn('user_id', $sellerIds)
            ->where('token_id', 1)
            ->get()
            ->keyBy('user_id');

        $sellers = [];
        foreach ($products as $product) {
            $shop = $shops->get($product['shop_id']);

            // Check if the shop exists
            if (!$shop) {
                return ['message' => 'Shop not exist'];
            }

            $seller = $shop->owner;
            $sellerBalance = $sellerBalances->get($seller->id);
            // Check if the seller exists, is active, and has a verified email
            // Check if the seller's balance is not locked
            // Check if the buyer is not buying their own products
            if (!$seller || $seller->status =="locked" || !$seller->hasVerifiedEmail() ||
                !$sellerBalance || $sellerBalance->is_locked == 1 ||
                $seller->id == $user->id) {
                return ['message' => 'Has error from seller'];
            }

            // Calculate the total Tomxu for each seller
            $tomxuOfOrderProduct = floatval($product['tomxu_subtotal']);
            if (isset($sellers[$seller->id])) {
                $sellers[$seller->id]['tomxuAdd'] += $tomxuOfOrderProduct;
            } else {
                $sellers[$seller->id] = [
                    'sellerId' => $seller->id,
                    'email' => $seller->email,
                    'date' => now(),
                    'tomxuAdd' => $tomxuOfOrderProduct,
                    'pre_balance' => $sellerBalance->balance,
                ];
            }
        }
        return array_values($sellers);
    }

    /**
     * Validates the products in an order.
     *
     * This method checks if the quantity and details of each product in the order match the corresponding data in the database.
     * It performs the following checks:
     * - Checks if the number of products in the request matches the number of products in the order.
     * - Checks if the quantity of each product is not less than zero.
     * - Checks if each product in the request matches the corresponding product in the database.
     *
     * @param array $products The array of products involved in the transaction. Each product is an associative array with keys 'product_id', 'shop_id', 'quantity', 'tomxu', and 'tomxu_subtotal'.
     * @param object $order The order object.
     *
     * @return bool|array Returns true if all checks pass, otherwise returns an array with a 'message' key.
     */
    protected function validateOrderProducts($products, $order): bool|array
    {
        $orderId = $order->id;
        //check count
        if (count($products) != count($order->products)) {
            return ['message' => 'Quantity of order product not match'];
        }
        foreach ($products as $product) {

            //check quantity of product less than zero ?
            if (floatval($product['quantity']) < 0) {
                return ['message' => 'Quantity of product less than zero'];
            }

            //check if product_order  request match product_order in DB
            $correspondingProduct = OrderProduct::where('order_id', $orderId)
                ->where('product_id', $product['product_id'])
                ->where('order_quantity', $product['quantity'])
                //->where('tomxu', $product['tomxu'])
                ->where('tomxu_subtotal', $product['tomxu_subtotal'])
                ->first();
            if (!$correspondingProduct) {
                return ['message' => 'Order product not exist'];
            }
        }
        return true;
    }

/**
* Processes the order payments for both the buyer and the sellers.
*
* This method performs the following steps for each seller in the order:
* - Updates the buyer's balance by subtracting the amount of Tomxu paid to the seller.
 * - Creates a transaction record for the buyer with the details of the payment.
 * - Updates the seller's balance by adding the amount of Tomxu received from the buyer.
* - Creates a transaction record for the seller with the details of the payment received.
*
* @param object $buyer The user object representing the buyer.
* @param array $sellers An array of associative arrays, each containing the details of a seller and the amount of Tomxu they are to receive.
* @param object $order The order object.
*/
    protected function processOrderPayments($buyer, $sellers, $order): void
    {
        foreach ($sellers as $seller) {

            // Update buyer's balance
            $buyerBalance = UsersBalance::where('user_id', $buyer->id)
                ->where('token_id', 1)
                ->lockForUpdate()
                ->first();
            $currentBuyerBalance = floatval($buyerBalance->balance);
            $newBuyerBalance = $currentBuyerBalance - floatval($seller['tomxuAdd']);
            $buyerBalance->update([
                'balance' => $newBuyerBalance,
                'updated_at' => now(),
            ]);

            // Create transaction for buyer
            UsersTransaction::create([
                'type' => 29, //'payment_order_tomxu'
                'user_id' => $buyer->id,
                'token_id' => 1,
                "order_id" => $order->id,
                'from_id' => $buyer->id,
                'to_id' => $seller['sellerId'],
                'status' => 'success',
                'value' => floatval($seller['tomxuAdd']),
                'pre_balance' => $currentBuyerBalance,
                'post_balance' => $newBuyerBalance,
                'updated_at' => now(),
                'created_at' => now(),
            ]);

            // Update seller's balance
            $sellerBalance = UsersBalance::where('user_id', $seller['sellerId'])
                ->where('token_id', 1)
                ->lockForUpdate()
                ->first();
            $currentSellerBalance = floatval($sellerBalance->balance);
            $newSellerBalance = $currentSellerBalance + floatval($seller['tomxuAdd']);
            $sellerBalance->update([
                'balance' => $newSellerBalance,
                'updated_at' => now(),
            ]);

            // Create transaction for seller
            UsersTransaction::create([
                'type' => 30,//'receive_order_payment_tomxu'
                'user_id' => $seller['sellerId'],
                'token_id' => 1,
                'to_id' => $buyer->id,
                "order_id" => $order->id,
                'from_id' => $seller['sellerId'],
                'status' => 'success',
                'value' => floatval($seller['tomxuAdd']),
                'pre_balance' => $currentSellerBalance,
                'post_balance' => $newSellerBalance,
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Prepares the data to be sent in the email to the buyer and sellers after a successful transaction.
     *
     * This method performs the following steps:
     * - Retrieves the IDs of transactions related to the order.
     * - Extracts product names from the order.
     * - Prepares the data for the buyer.
     * - Iterates over each seller, retrieves the IDs of their shops, and prepares the data for each seller.
     *
     * @param object $order The order object.
     * @param object $user The user object representing the buyer.
     * @param float $preBalance The buyer's balance before the transaction.
     * @param array $sellers An array of associative arrays, each containing the details of a seller and the amount of Tomxu they received.
     *
     * @return array Returns an array containing the data for the buyer and sellers, and the template and type for the email.
     */
    protected function getBuyerAndSellerDataToSendMail($order, $user, $preBalance, $sellers)
    {
        // Get IDs of transactions related to the order
        $transactionIds = UsersTransaction::where('order_id', $order->id)
                                           ->where('type', 'payment_order_tomxu')
                                           ->pluck('id')
                                           ->toArray();

        // Extract product names from the order
        $productNames = [];
        foreach ($order->products as $product) {
            $productNames[] = $product['name'];
        }
        // Data for the buyer
        $buyer = [
            'product_name' => $productNames,
            'buyer_id' => $user->id,
            'buyer_email' => $user->email,
            'title' => 'Thanh toán thành công',
            'tracking_number' => $order->tracking_number,
            'tomxu_paid' => $order->total_tomxu,
            'date' => $order->created_at,
            'pre_balance' => $preBalance,
            'post_balance' => $preBalance - floatval($order->total_tomxu),
            'id_transactions' => $transactionIds,
            'status_payment' => 'payment_success'
        ];

        //data of seller to send mail
        $infSellers = [];

        foreach ($sellers as $seller) {
            $shop_ids= Shop::where('owner_id', $seller['sellerId'])->pluck('id')->toArray();
            $product_name = [];
            foreach ($order->products as $product) {
                if (in_array($product['shop_id'], $shop_ids)) {
                    $product_name[]= $product['name'];
                }
            }
            $id = UsersTransaction::where('order_id', $order->id)->where('type', 'receive_order_payment_tomxu')->pluck('id')->first();
            $infSeller = [
                'product_name' => $product_name ,
                'seller_id' => $seller['sellerId'],
                'seller_email' => $seller['email'],
                'title' => 'Nhận thanh toán thành công',
                'date' => $seller['date'],
                'tracking_number' => $order->tracking_number,
                'tomxu_paid' => $seller['tomxuAdd'],
                'payment_method' => 'Tomxu',
                'pre_balance' => $seller['pre_balance'],
                'post_balance' => floatval($seller['pre_balance']) + floatval($seller['tomxuAdd']),
                'id_transaction' => $id,
            ];

            $infSellers[] = $infSeller;
        }
        return [
            'buyer' => $buyer,
            'seller' => $infSellers,
            'template' => 'ecommerce',
            'type' => 'ecommerce'
        ];
    }

    /**
     * Updates the quantity and sold quantity of the products in an order.
     *
     * This method performs the following steps for each product in the order:
     * - Retrieves the product from the database and locks it for update.
     * - Checks if the product's quantity is less than the quantity ordered. If so, it throws an exception.
     * - Decreases the product's quantity by the quantity ordered.
     * - Increases the product's sold quantity by the quantity ordered.
     * - Updates the product's updated_at timestamp to the current time.
     *
     * @param array $products An array of associative arrays, each containing the details of a product in the order.
     *
     * @throws \Exception If a product's quantity is less than the quantity ordered.
     *
     * @return void
     */
    protected function updateProductQuantity($products): void
    {
        foreach ($products as $product) {
            $updateProduct = Product::where('id', $product['product_id'])->lockForUpdate()->firstOrFail();
            if ($updateProduct->quantity < $product['quantity']) {
                throw new \Exception('Not enough quantity of products');
            }
            $updateProduct->update([
                'quantity' =>  $updateProduct->quantity - $product['quantity'],
                'sold_quantity' => $updateProduct->sold_quantity + $product['quantity'],
                'updated_at' => now(),
            ]);
        }
    }
}
