<?php

namespace App\Http\Controllers;

use App\Models\PaymentOrder;
use App\Models\Voucher;
use App\Services\SonicPesaGateway;
use App\Services\SonicPesaPaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PaymentController extends Controller
{
    public function __construct(
        private readonly SonicPesaGateway $sonicPesa,
        private readonly SonicPesaPaymentStatus $paymentStatus,
    ) {}

    public function start(Request $request)
    {
        $packages = collect(config('wifi.packages'));
        $data = $request->validate([
            'package_name' => ['required', 'string', Rule::in($packages->pluck('name')->all())],
            'phone' => ['required', 'string', 'regex:/^\+?255\d{9}$/'],
        ]);

        $apiKey = config('services.sonicpesa.api_key');
        $secretKey = config('services.sonicpesa.secret_key');

        if (! filled($apiKey) || ! filled($secretKey)) {
            throw ValidationException::withMessages([
                'phone' => 'Online payment is not configured. Please contact the Wi-Fi provider.',
            ]);
        }

        $phone = ltrim($data['phone'], '+');
        $package = $packages->firstWhere('name', $data['package_name']);

        $payment = DB::transaction(function () use ($package, $phone) {
            $voucher = Voucher::query()
                ->where('package_name', $package['name'])
                ->where('status', 'available')
                ->lockForUpdate()
                ->first();

            if (! $voucher) {
                throw ValidationException::withMessages([
                    'package_name' => 'This package is sold out. Please choose another package.',
                ]);
            }

            $voucher->update([
                'status' => 'reserved',
                'assigned_to_phone' => $phone,
            ]);

            return PaymentOrder::query()->create([
                'public_id' => (string) Str::uuid(),
                'voucher_id' => $voucher->id,
                'package_name' => $package['name'],
                'phone' => $phone,
                'amount' => $package['price'],
                'currency' => 'TZS',
                'status' => 'creating',
            ]);
        });

        $order = $this->sonicPesa->createOrder([
            'buyer_email' => 'noemail@sonicpesa.com',
            'buyer_name' => 'Wi-Fi Customer',
            'buyer_phone' => $phone,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
        ]);

        $order = is_array($order['data'] ?? null) ? $order['data'] : $order;
        $providerOrderId = $order['order_id'] ?? null;
        $sdkError = is_string($order['error'] ?? null) ? $order['error'] : null;
        $providerHttpStatus = $sdkError && preg_match('/\b([45]\d{2})\b/', $sdkError, $matches)
            ? (int) $matches[1]
            : null;

        if (
            ($order['success'] ?? true) === false
            || ! is_string($providerOrderId)
            || $providerOrderId === ''
        ) {
            Log::warning('SonicPesa returned an unrecognized order response.', [
                'payment_id' => $payment->public_id,
                'sdk_error' => ($order['success'] ?? true) === false,
                'provider_http_status' => $providerHttpStatus,
            ]);

            $payment->update(['status' => 'unknown']);

            $statusMessage = $providerHttpStatus
                ? " (HTTP {$providerHttpStatus})"
                : '';

            throw ValidationException::withMessages([
                'phone' => 'SonicPesa could not confirm checkout creation'.$statusMessage.'. The payment may still have been received. Do not retry yet; contact support with reference '.$payment->public_id.'.',
            ]);
        }

        $payment->update([
            'provider_order_id' => $providerOrderId,
            'status' => 'pending',
        ]);

        return to_route('payments.show', $payment);
    }

    public function show(PaymentOrder $payment)
    {
        return Inertia::render('payment-status', [
            'payment' => [
                'id' => $payment->public_id,
                'status' => $payment->status,
                'package_name' => $payment->package_name,
                'phone' => $payment->phone,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'voucher_code' => $payment->status === 'paid'
                    ? $payment->voucher()->value('code')
                    : null,
            ],
        ]);
    }

    public function status(PaymentOrder $payment)
    {
        $message = $this->paymentStatus->refresh($payment);
        $payment = $payment->fresh();

        if ($message !== null) {
            return response()->json([
                'status' => $payment->status,
                'message' => $message,
            ], 503);
        }

        return $this->statusResponse($payment);
    }

    public function cancel(PaymentOrder $payment)
    {
        $payment = DB::transaction(function () use ($payment) {
            $lockedPayment = PaymentOrder::query()->lockForUpdate()->findOrFail($payment->id);

            if ($lockedPayment->status !== 'pending') {
                return $lockedPayment;
            }

            $voucher = Voucher::query()->lockForUpdate()->findOrFail($lockedPayment->voucher_id);

            if (
                $voucher->status !== 'reserved'
                || $voucher->assigned_to_phone !== $lockedPayment->phone
            ) {
                Log::error('Pending checkout could not release its reserved voucher on cancellation.', [
                    'payment_id' => $lockedPayment->public_id,
                ]);

                $lockedPayment->update(['status' => 'needs_review']);

                return $lockedPayment->fresh();
            }

            $voucher->update([
                'status' => 'available',
                'assigned_to_phone' => null,
            ]);
            $lockedPayment->update(['status' => 'cancelled']);

            return $lockedPayment->fresh();
        });

        if ($payment->status === 'cancelled') {
            $message = 'Payment cancelled and the voucher was released. If you completed payment, contact support before starting another checkout.';
        } elseif ($payment->status === 'needs_review') {
            $message = 'The voucher could not be safely released. Please contact support about this payment.';
        } else {
            $message = null;
        }

        if (request()->expectsJson()) {
            return response()->json([
                'status' => $payment->status,
                'message' => $message,
            ]);
        }

        return to_route('payments.show', $payment);
    }

    private function statusResponse(PaymentOrder $payment)
    {
        return response()->json([
            'status' => $payment->status,
            'message' => match ($payment->status) {
                'needs_review' => 'Payment received. Please contact support to retrieve your voucher.',
                'expired' => 'The 3-minute payment window expired and the voucher was released. If you completed payment, contact support before starting another checkout.',
                'cancelled' => 'Payment cancelled and the voucher was released. If you completed payment, contact support before starting another checkout.',
                'released' => 'The voucher reservation was cleared after 5 minutes. If you completed payment, contact support before starting another checkout.',
                default => null,
            },
            'voucher_code' => $payment->status === 'paid'
                ? $payment->voucher()->value('code')
                : null,
        ]);
    }
}
