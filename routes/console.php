<?php

use App\Models\PaymentOrder;
use App\Services\SonicPesaPaymentStatus;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('payments:expire-pending', function (SonicPesaPaymentStatus $paymentStatus) {
    $cutoff = now()->subMinutes(SonicPesaPaymentStatus::RESERVATION_MINUTES);
    $expired = 0;

    PaymentOrder::query()
        ->where('status', 'pending')
        ->whereNotNull('provider_order_id')
        ->where('created_at', '<=', $cutoff)
        ->orderBy('id')
        ->chunkById(50, function ($payments) use ($paymentStatus, &$expired) {
            foreach ($payments as $payment) {
                $paymentStatus->refresh($payment);

                if ($payment->fresh()->status === 'expired') {
                    $expired++;
                }
            }
        });

    $this->info("Expired {$expired} unpaid checkout(s).");
})->purpose('Expire pending checkouts after the reservation window');
