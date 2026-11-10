<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NextbyteSmsGateway
{
    public function sendVoucherCode(
        string $phone,
        string $packageName,
        string $voucherCode,
        string $paymentReference,
    ): void {
        $apiKey = config('services.nextbyte.api_key');
        $senderId = config('services.nextbyte.sender_id');

        if (! filled($apiKey) || ! filled($senderId)) {
            Log::error('Voucher SMS was not sent because Nextbyte is not configured.', [
                'payment_id' => $paymentReference,
            ]);

            return;
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($apiKey)
                ->timeout((int) config('services.nextbyte.timeout', 10))
                ->post(config('services.nextbyte.sms_url'), [
                    'from' => $senderId,
                    'to' => $phone,
                    'text' => "Hello, you have subscribed to Wakili Wi-Fi with the {$packageName} bundle. Your voucher code is {$voucherCode}. Come to me, I'll give the rest.",
                    'flash' => 0,
                    'reference' => (string) Str::uuid(),
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Nextbyte voucher SMS request could not be completed.', [
                'payment_id' => $paymentReference,
                'exception' => $exception::class,
            ]);

            return;
        }

        if (! $response->successful()) {
            Log::warning('Nextbyte rejected the voucher SMS request.', [
                'payment_id' => $paymentReference,
                'http_status' => $response->status(),
            ]);
        }
    }
}
