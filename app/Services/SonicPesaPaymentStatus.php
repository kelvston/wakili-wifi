<?php

namespace App\Services;

use App\Models\PaymentOrder;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SonicPesaPaymentStatus
{
    public const RESERVATION_MINUTES = 3;

    public function __construct(private readonly SonicPesaGateway $sonicPesa) {}

    public function refresh(PaymentOrder $payment): ?string
    {
        if ($payment->status !== 'pending' || ! $payment->provider_order_id) {
            return null;
        }

        $order = $this->sonicPesa->orderStatus($payment->provider_order_id);
        if (($order['success'] ?? true) === false) {
            Log::warning('SonicPesa order status could not be checked.', [
                'payment_id' => $payment->public_id,
            ]);

            return 'Payment status is temporarily unavailable. Checking again shortly.';
        }

        $order = is_array($order['data'] ?? null) ? $order['data'] : $order;

        if (
            ! is_array($order)
            || ($order['success'] ?? true) === false
            || ($order['order_id'] ?? null) !== $payment->provider_order_id
        ) {
            Log::warning('SonicPesa returned a mismatched order status response.', [
                'payment_id' => $payment->public_id,
            ]);

            return 'Payment status is temporarily unavailable. Checking again shortly.';
        }

        if (! is_numeric($order['amount'] ?? null) || (int) $order['amount'] !== $payment->amount) {
            Log::warning('SonicPesa order amount did not match the local order.', [
                'payment_id' => $payment->public_id,
            ]);

            return 'Payment status could not be verified. Please contact support.';
        }

        if (! is_string($order['currency'] ?? null) || strtoupper($order['currency']) !== $payment->currency) {
            Log::warning('SonicPesa order currency did not match the local order.', [
                'payment_id' => $payment->public_id,
            ]);

            return 'Payment status could not be verified. Please contact support.';
        }

        $providerStatus = strtolower((string) (
            $order['payment_status']
            ?? $order['order_status']
            ?? $order['status']
            ?? ''
        ));

        if (in_array($providerStatus, ['paid', 'completed', 'successful', 'success'], true)) {
            $this->markPaid($payment);
        } elseif (in_array($providerStatus, ['failed', 'cancelled', 'canceled', 'expired', 'rejected'], true)) {
            $this->releaseVoucher($payment, 'failed');
        } elseif (
            $providerStatus === 'pending'
            && $payment->created_at->lte(now()->subMinutes(self::RESERVATION_MINUTES))
        ) {
            $this->releaseVoucher($payment, 'expired');
        }

        return null;
    }

    private function markPaid(PaymentOrder $payment): void
    {
        DB::transaction(function () use ($payment) {
            $lockedPayment = PaymentOrder::query()->lockForUpdate()->findOrFail($payment->id);

            if ($lockedPayment->status !== 'pending') {
                return;
            }

            $voucher = Voucher::query()->lockForUpdate()->findOrFail($lockedPayment->voucher_id);

            if (
                $voucher->status !== 'reserved'
                || $voucher->assigned_to_phone !== $lockedPayment->phone
            ) {
                Log::error('Paid SonicPesa order did not have its reserved voucher.', [
                    'payment_id' => $lockedPayment->public_id,
                ]);

                $lockedPayment->update(['status' => 'needs_review']);

                return;
            }

            $voucher->assignTo($lockedPayment->phone);
            $lockedPayment->update(['status' => 'paid']);
        });
    }

    private function releaseVoucher(PaymentOrder $payment, string $paymentStatus): void
    {
        DB::transaction(function () use ($payment, $paymentStatus) {
            $lockedPayment = PaymentOrder::query()->lockForUpdate()->findOrFail($payment->id);

            if ($lockedPayment->status !== 'pending') {
                return;
            }

            $voucher = Voucher::query()->lockForUpdate()->findOrFail($lockedPayment->voucher_id);

            if (
                $voucher->status === 'reserved'
                && $voucher->assigned_to_phone === $lockedPayment->phone
            ) {
                $voucher->update([
                    'status' => 'available',
                    'assigned_to_phone' => null,
                ]);
            }

            $lockedPayment->update(['status' => $paymentStatus]);
        });
    }
}
