<?php

namespace App\Listeners;


use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class SendOrderConfirmationEmail implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    protected $data;
    protected $endpoint;

    public function __construct($data, $endpoint)
    {
        $this->data = $data;
        $this->endpoint = $endpoint;
    }

    public static function dispatch($data, $endpoint)
    {
        return app('Illuminate\Contracts\Bus\Dispatcher')->dispatch(new static(...func_get_args()));
    }

    public function handle()
    {
        Http::withHeaders([
            'Content-Type' => 'application/json',
            'secret' => 'tomiruHaDong',
            'client_id'  =>'hieu-dev.com'
        ])->post('http://192.168.102.11:8080/' . $this->endpoint, [
            'content' => $this->data,
        ]);

    }
}
