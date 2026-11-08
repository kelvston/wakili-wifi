import { Head, router } from '@inertiajs/react';
import { Check, LoaderCircle, Wifi, X } from 'lucide-react';
import { useEffect, useState } from 'react';

type Payment = {
    id: string;
    status: string;
    package_name: string;
    phone: string;
    amount: number;
    currency: string;
    voucher_code: string | null;
};

type PaymentStatusProps = {
    payment: Payment;
};

type StatusResponse = {
    status: string;
    message?: string | null;
    voucher_code?: string | null;
};

function formatPrice(amount: number) {
    return `TSh ${new Intl.NumberFormat('en-TZ').format(amount)}`;
}

export default function PaymentStatus({ payment }: PaymentStatusProps) {
    const [status, setStatus] = useState(payment.status);
    const [voucherCode, setVoucherCode] = useState(payment.voucher_code);
    const [message, setMessage] = useState<string | null>(null);
    const [cancelling, setCancelling] = useState(false);

    useEffect(() => {
        setStatus(payment.status);
        setVoucherCode(payment.voucher_code);
        setMessage(null);
    }, [payment.status, payment.voucher_code]);

    useEffect(() => {
        if (status !== 'pending') {
            return;
        }

        let active = true;

        const checkStatus = async () => {
            try {
                const response = await fetch(`/payments/${payment.id}/status`, {
                    headers: { Accept: 'application/json' },
                });
                const result = (await response.json()) as StatusResponse;

                if (!active) {
                    return;
                }

                if (result.status) {
                    setStatus(result.status);
                }

                if (result.voucher_code) {
                    setVoucherCode(result.voucher_code);
                }

                setMessage(result.message ?? null);
            } catch {
                if (active) {
                    setMessage(
                        'Unable to check payment status. We will keep trying.',
                    );
                }
            }
        };

        void checkStatus();
        const interval = window.setInterval(() => void checkStatus(), 5000);

        return () => {
            active = false;
            window.clearInterval(interval);
        };
    }, [payment.id, status]);

    const paid = status === 'paid';
    const failed = status === 'failed';
    const expired = status === 'expired';
    const cancelled = status === 'cancelled';
    const released = status === 'released';
    const needsReview = status === 'needs_review';

    return (
        <>
            <Head title={paid ? 'Payment confirmed' : 'Payment status'} />
            <main className="flex min-h-screen items-center justify-center bg-[#f7f8f4] px-3 py-6 text-[#17231f] sm:px-5 sm:py-12">
                <section className="w-full max-w-lg min-w-0 rounded-2xl border border-[#e5eae4] bg-white p-4 shadow-xl shadow-emerald-950/5 sm:rounded-3xl sm:p-10">
                    <a
                        href="/"
                        className="mx-auto flex w-fit items-center gap-2 font-semibold"
                    >
                        <span className="flex size-9 items-center justify-center rounded-xl bg-emerald-700 text-white">
                            <Wifi className="size-5" />
                        </span>
                        Wakili Wi-Fi
                    </a>

                    <div
                        className={`mx-auto mt-8 flex size-14 items-center justify-center rounded-full ${
                            paid
                                ? 'bg-emerald-100 text-emerald-800'
                                : failed || expired || cancelled || released
                                  ? 'bg-red-100 text-red-700'
                                  : 'bg-amber-100 text-amber-800'
                        }`}
                    >
                        {paid ? (
                            <Check className="size-7" />
                        ) : failed || expired || cancelled || released ? (
                            <X className="size-7" />
                        ) : (
                            <LoaderCircle className="size-7 animate-spin" />
                        )}
                    </div>

                    <h1 className="mt-5 text-center text-2xl font-semibold">
                        {paid
                            ? 'Payment confirmed'
                            : failed
                              ? 'Payment not completed'
                              : expired
                                ? 'Payment window expired'
                                : cancelled
                                  ? 'Payment cancelled'
                                  : released
                                    ? 'Voucher reservation released'
                                    : needsReview
                                      ? 'Payment received'
                                      : status === 'unknown'
                                        ? 'Payment status unavailable'
                                        : 'Waiting for payment'}
                    </h1>
                    <p className="mt-2 text-center leading-6 text-[#68746d]">
                        {paid
                            ? 'Your voucher is ready. Keep it private and enter it on the Wi-Fi login page.'
                            : failed
                              ? 'This payment was not completed. You can return to the package list and try again.'
                              : expired
                                ? 'The 3-minute payment window expired and the voucher was released. If you completed payment, contact support before starting another checkout.'
                                : cancelled
                                  ? 'The voucher was released when you cancelled. If a payment prompt completes afterward, contact support before starting another checkout.'
                                  : released
                                    ? 'The reservation was cleared after 5 minutes. If you completed payment, contact support before starting another checkout.'
                                    : needsReview
                                      ? 'Your payment was confirmed, but we could not automatically attach a voucher. Please contact support with your order details.'
                                      : status === 'unknown'
                                        ? 'We could not confirm that the payment request was created. Please contact support before trying again.'
                                        : 'Complete the payment prompt on your phone. This page will update automatically once the payment is confirmed.'}
                    </p>

                    <dl className="mt-7 space-y-3 rounded-2xl bg-[#f7f8f4] p-4 text-sm">
                        <div className="flex justify-between gap-4">
                            <dt className="text-[#68746d]">Package</dt>
                            <dd className="min-w-0 text-right font-medium break-all">
                                {payment.package_name}
                            </dd>
                        </div>
                        <div className="flex justify-between gap-4">
                            <dt className="text-[#68746d]">Amount</dt>
                            <dd className="min-w-0 text-right font-medium break-all">
                                {formatPrice(payment.amount)}
                            </dd>
                        </div>
                        <div className="flex justify-between gap-4">
                            <dt className="text-[#68746d]">Phone</dt>
                            <dd className="text-right font-medium">
                                {payment.phone}
                            </dd>
                        </div>
                    </dl>

                    {status === 'pending' && (
                        <button
                            type="button"
                            disabled={cancelling}
                            onClick={() => {
                                if (
                                    window.confirm(
                                        'Cancel this checkout and release its voucher now? SonicPesa may still complete a payment already in progress. Contact support if you pay after cancelling.',
                                    )
                                ) {
                                    setCancelling(true);
                                    router.post(
                                        `/payments/${payment.id}/cancel`,
                                        {},
                                        {
                                            preserveScroll: true,
                                            onFinish: () =>
                                                setCancelling(false),
                                        },
                                    );
                                }
                            }}
                            className="mt-5 w-full rounded-full border border-red-300 px-5 py-3 font-medium text-red-700 transition hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {cancelling ? 'Cancelling…' : 'Cancel payment'}
                        </button>
                    )}

                    {voucherCode && paid && (
                        <div className="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-center">
                            <p className="text-sm font-medium text-emerald-900">
                                Your Wi-Fi voucher
                            </p>
                            <p className="mt-2 font-mono text-2xl font-bold tracking-wide break-all text-emerald-950">
                                {voucherCode}
                            </p>
                        </div>
                    )}

                    {message && (
                        <p
                            role="status"
                            className="mt-4 text-center text-sm text-[#68746d]"
                        >
                            {message}
                        </p>
                    )}

                    {(paid ||
                        failed ||
                        expired ||
                        cancelled ||
                        released ||
                        needsReview ||
                        status === 'unknown') && (
                        <a
                            href="/"
                            className="mt-6 block rounded-full bg-emerald-700 px-5 py-3 text-center font-medium text-white transition hover:bg-emerald-800"
                        >
                            Back to packages
                        </a>
                    )}
                </section>
            </main>
        </>
    );
}
