<?php

namespace Marvel\Database\Repositories;

use Illuminate\Http\Request;
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
