<?php

namespace Marvel\Database\Repositories;

use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Marvel\Database\Models\User;
use Illuminate\Support\Facades\Auth;
use Marvel\Database\Models\UserCardOtpToken;
use Marvel\Database\Repositories\BaseRepository;
use PHPUnit\Logging\Exception;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;
use Safe\Exceptions\XdiffException;

class UserCardOtpTokenRepository extends BaseRepository
{
    /**
     * @var array
     */
    protected $dataArray = [
        'id',
        'serial_number',
        'stt',
        'token',
    ];

    public function createdCard($totalCard)
    {
        // Lấy serial cuối cùng từ cơ sở dữ liệu
        $latestSerial = UserCardOtpToken::latest()->first();

        // Khởi tạo giá trị ban đầu của serialNumber
        $serialNumber = 1000001;

        if ($latestSerial) {
            // Lấy số cuối cùng của serial hiện có
            $lastSerialNumber = (int)$latestSerial->serial_number;


            // Tăng giá trị của serialNumber lên một đơn vị so với số cuối cùng
            $serialNumber = $lastSerialNumber + 1;
        }

        // Khởi tạo mảng để lưu trữ token đã được sử dụng
        $usedTokens = [];

        // Tạo 100 serial mới, bắt đầu từ số cuối cùng lấy được từ cơ sở dữ liệu
        for ($i = 0; $i < $totalCard; $i++) {
            // Tạo serial cho mỗi vòng lặp
            $serial = (string)$serialNumber;
            // Tạo 35 token
            for ($j = 0; $j < 35; $j++) {
                do {
                    // Tạo một token mới
                    $token = random_int(1001, 9999);
                } while (in_array($token, $usedTokens)); // Kiểm tra xem token đã được sử dụng chưa

                // Thêm token vào mảng usedTokens
                $usedTokens[] = $token;
                // Tạo một bản ghi mới trong cơ sở dữ liệu
                UserCardOtpToken::create([
                    'serial_number' => $serial,
                    'stt' => $j + 1,
                    'token' => $token,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // Tăng giá trị của serialNumber để tạo serial tiếp theo
            $serialNumber++;

        }
        return response()->json(['message' => 'Tạo serial thành công'], 200);

    }

    public function show($limit , $search = null)
    {
        // Lấy ra các serial riêng biệt
        $serials = UserCardOtpToken::distinct()->pluck('serial_number')->take($limit)->toArray();

        if ($search) {
            $serials = UserCardOtpToken::distinct()->where('serial_number', 'like', '%' . $search . '%')->pluck('serial_number')->take($limit)->toArray();
        }
        // Lấy ra các token và stt tương ứng với mỗi serial
        $tokensBySerial = UserCardOtpToken::whereIn('serial_number', $serials)
            ->orderBy('serial_number')
            ->get()
            ->reduce(function ($carry, $item) {
                $carry[$item->serial_number]['tokens'][] = $item->token;
                $carry[$item->serial_number]['stt'][] = $item->stt;
                return $carry;
            }, []);

        // Tạo một collection từ mảng kết quả
        $collection = collect([]);
        foreach ($serials as $serial) {
            $collection->push([
                'serial_number' => $serial,
                'tokens' => $tokensBySerial[$serial]['tokens'] ?? [],
                'stt' => $tokensBySerial[$serial]['stt'] ?? [],
            ]);
        }

        // Tạo một trang mới từ collection với số lượng phần tử mỗi trang là $limit
        $page = Paginator::resolveCurrentPage() ?: 1;
        $paginateResult = new Paginator($collection->forPage($page, $limit), $limit, $page, [
            'path' => Paginator::resolveCurrentPath(),
        ]);

        return $paginateResult;
    }





    public function model()
    {
        // TODO: Implement model() method.
        return UserCardOtpToken::class;
    }

    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
            //
        }
    }


}
