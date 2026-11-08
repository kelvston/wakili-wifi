import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { dashboard } from '@/routes';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type VoucherStats = {
    available: number;
    reserved: number;
    assigned: number;
    used: number;
};

type VoucherSummary = {
    code: string;
    package_name: string;
    status: string;
    payment_status: string | null;
    assigned_to_phone: string | null;
    assigned_at: string | null;
    used_by_phone: string | null;
};

type VoucherPage = {
    data: VoucherSummary[];
    current_page: number;
    last_page: number;
    total: number;
};

type PackageOption = {
    name: string;
    duration: string;
    price: number;
};

export default function Dashboard() {
    const {
        voucher_stats,
        vouchers,
        packages,
        success,
        phone_filter,
        reserved_clear_minutes,
        reserved_clearable,
    } = usePage<{
        voucher_stats: VoucherStats;
        vouchers: VoucherPage;
        packages: PackageOption[];
        success?: string;
        phone_filter: string;
        reserved_clear_minutes: number;
        reserved_clearable: number;
    }>().props;
    const form = useForm({
        package_name: packages[0]?.name ?? '',
        codes: '',
        file: null as File | null,
    });
    const fileInput = useRef<HTMLInputElement>(null);
    const [phoneSearch, setPhoneSearch] = useState(phone_filter);

    const statCards = [
        {
            label: 'Available vouchers',
            value: voucher_stats.available,
            description: 'Ready to be purchased.',
        },
        {
            label: 'Reserved vouchers',
            value: voucher_stats.reserved,
            description: 'Checkout is pending or payment needs confirmation.',
        },
        {
            label: 'Assigned vouchers',
            value: voucher_stats.assigned,
            description: 'Payment confirmed; issued to a phone.',
        },
        {
            label: 'Used vouchers',
            value: voucher_stats.used,
            description:
                'Marked used after your Wi-Fi system reports redemption.',
        },
    ];

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex h-full w-full min-w-0 flex-1 flex-col gap-4 overflow-x-hidden rounded-xl p-3 sm:p-4">
                <section className="rounded-xl border border-sidebar-border/70 bg-white p-4 shadow-sm dark:border-sidebar-border dark:bg-[#161615]">
                    <h2 className="text-lg font-medium">Add voucher stock</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Paste one voucher code per line or upload an Excel file
                        with codes in the first column of the first worksheet.
                        Codes already in the inventory will be skipped.
                    </p>

                    {success && (
                        <p
                            role="status"
                            className="mt-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200"
                        >
                            {success}
                        </p>
                    )}

                    {reserved_clearable > 0 && (
                        <div className="mt-4 flex flex-col gap-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 sm:flex-row sm:items-center sm:justify-between dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
                            <p>
                                Release {reserved_clearable} reservation(s)
                                older than {reserved_clear_minutes} minutes.
                                This can include payments with an unknown
                                outcome; check for late payments before
                                reassigning released vouchers.
                            </p>
                            <Button
                                type="button"
                                variant="destructive"
                                className="shrink-0"
                                onClick={() => {
                                    if (
                                        window.confirm(
                                            `Release ${reserved_clearable} reservation(s) older than ${reserved_clear_minutes} minutes? This can include payments with unknown outcomes. A late payment may not receive its original voucher.`,
                                        )
                                    ) {
                                        router.post(
                                            '/vouchers/clear-reserved',
                                            {},
                                            { preserveScroll: true },
                                        );
                                    }
                                }}
                            >
                                Clear expired reservations
                            </Button>
                        </div>
                    )}

                    <form
                        className="mt-5 grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_minmax(0,1fr)_auto] md:items-end"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post('/vouchers', {
                                preserveScroll: true,
                                onSuccess: () => {
                                    form.reset('codes', 'file');
                                    if (fileInput.current) {
                                        fileInput.current.value = '';
                                    }
                                },
                            });
                        }}
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="voucher-package">Package</Label>
                            <select
                                id="voucher-package"
                                value={form.data.package_name}
                                onChange={(event) =>
                                    form.setData(
                                        'package_name',
                                        event.target.value,
                                    )
                                }
                                required
                                className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                            >
                                {packages.map((pkg) => (
                                    <option key={pkg.name} value={pkg.name}>
                                        {pkg.duration} — TSh{' '}
                                        {new Intl.NumberFormat('en-TZ').format(
                                            pkg.price,
                                        )}
                                    </option>
                                ))}
                            </select>
                            {form.errors.package_name && (
                                <p className="text-sm text-destructive">
                                    {form.errors.package_name}
                                </p>
                            )}
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="voucher-codes">Voucher codes</Label>
                            <textarea
                                id="voucher-codes"
                                value={form.data.codes}
                                onChange={(event) =>
                                    form.setData('codes', event.target.value)
                                }
                                rows={4}
                                placeholder={'WIFI-CODE-001\nWIFI-CODE-002'}
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50"
                            />
                            {form.errors.codes && (
                                <p className="text-sm text-destructive">
                                    {form.errors.codes}
                                </p>
                            )}
                        </div>

                        <div className="grid content-start gap-2">
                            <Label htmlFor="voucher-file">
                                Excel file (.xlsx)
                            </Label>
                            <input
                                ref={fileInput}
                                id="voucher-file"
                                type="file"
                                accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                onChange={(event) =>
                                    form.setData(
                                        'file',
                                        event.target.files?.[0] ?? null,
                                    )
                                }
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            />
                            <p className="text-xs text-muted-foreground">
                                Put one code per row in column A. Format codes
                                as text in Excel to preserve leading zeros.
                            </p>
                            {form.errors.file && (
                                <p className="text-sm text-destructive">
                                    {form.errors.file}
                                </p>
                            )}
                        </div>

                        <Button
                            type="submit"
                            disabled={form.processing || packages.length === 0}
                        >
                            {form.processing ? 'Adding…' : 'Add vouchers'}
                        </Button>
                    </form>
                </section>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {statCards.map((stat) => (
                        <div
                            key={stat.label}
                            className="rounded-xl border border-sidebar-border/70 bg-white p-4 shadow-sm dark:border-sidebar-border dark:bg-[#161615]"
                        >
                            <p className="text-sm text-muted-foreground">
                                {stat.label}
                            </p>
                            <p className="mt-2 text-3xl font-semibold">
                                {stat.value}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {stat.description}
                            </p>
                        </div>
                    ))}
                </div>

                <div className="rounded-xl border border-sidebar-border/70 bg-white p-4 shadow-sm dark:border-sidebar-border dark:bg-[#161615]">
                    <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h2 className="text-lg font-medium">
                                Voucher inventory
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Search by phone to see vouchers reserved for,
                                assigned to, or used by that customer.
                            </p>
                        </div>
                        <form
                            className="flex gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                router.get(
                                    '/dashboard',
                                    { phone: phoneSearch.trim() || undefined },
                                    {
                                        preserveState: true,
                                        preserveScroll: true,
                                        replace: true,
                                    },
                                );
                            }}
                        >
                            <input
                                type="search"
                                value={phoneSearch}
                                onChange={(event) =>
                                    setPhoneSearch(event.target.value)
                                }
                                placeholder="Phone number"
                                aria-label="Search voucher inventory by phone number"
                                className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm sm:w-56"
                            />
                            <Button type="submit" variant="outline">
                                Search
                            </Button>
                        </form>
                    </div>

                    <div className="grid gap-3 md:hidden">
                        {vouchers.data.length > 0 ? (
                            vouchers.data.map((voucher) => (
                                <article
                                    key={voucher.code}
                                    className="min-w-0 rounded-lg border border-border/80 p-3"
                                >
                                    <div className="flex min-w-0 items-start justify-between gap-2">
                                        <p className="min-w-0 font-medium break-all">
                                            {voucher.code}
                                        </p>
                                        <span className="shrink-0 rounded-full bg-muted px-2 py-1 text-right text-xs capitalize">
                                            {voucher.status === 'reserved'
                                                ? voucher.payment_status ===
                                                  'unknown'
                                                    ? 'Payment unknown'
                                                    : 'Checkout pending'
                                                : voucher.status}
                                        </span>
                                    </div>
                                    {voucher.status === 'assigned' &&
                                        voucher.assigned_at && (
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                Purchased{' '}
                                                <time
                                                    dateTime={
                                                        voucher.assigned_at
                                                    }
                                                >
                                                    {new Date(
                                                        voucher.assigned_at,
                                                    ).toLocaleString()}
                                                </time>
                                            </p>
                                        )}
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {voucher.package_name}
                                    </p>
                                    <p className="mt-1 text-sm break-all">
                                        {voucher.used_by_phone ??
                                            voucher.assigned_to_phone ??
                                            'No customer assigned'}
                                    </p>
                                </article>
                            ))
                        ) : (
                            <p className="py-6 text-center text-sm text-muted-foreground">
                                {phone_filter
                                    ? 'No vouchers found for that phone number.'
                                    : 'No vouchers have been created yet.'}
                            </p>
                        )}
                    </div>

                    <div className="hidden overflow-x-auto md:block">
                        <table className="w-full text-left text-sm">
                            <thead>
                                <tr className="border-b border-border text-muted-foreground">
                                    <th className="px-2 py-2">Code</th>
                                    <th className="px-2 py-2">Package</th>
                                    <th className="px-2 py-2">Status</th>
                                    <th className="px-2 py-2">
                                        Customer phone
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {vouchers.data.length > 0 ? (
                                    vouchers.data.map((voucher) => (
                                        <tr
                                            key={voucher.code}
                                            className="border-b border-border/80"
                                        >
                                            <td className="px-2 py-2 font-medium">
                                                {voucher.code}
                                            </td>
                                            <td className="px-2 py-2">
                                                {voucher.package_name}
                                            </td>
                                            <td className="px-2 py-2 capitalize">
                                                {voucher.status === 'reserved'
                                                    ? voucher.payment_status ===
                                                      'unknown'
                                                        ? 'Reserved — payment outcome unknown'
                                                        : 'Reserved — checkout in progress'
                                                    : voucher.status}
                                                {voucher.status ===
                                                    'assigned' &&
                                                    voucher.assigned_at && (
                                                        <span className="mt-1 block text-xs font-normal text-muted-foreground normal-case">
                                                            Purchased{' '}
                                                            <time
                                                                dateTime={
                                                                    voucher.assigned_at
                                                                }
                                                            >
                                                                {new Date(
                                                                    voucher.assigned_at,
                                                                ).toLocaleString()}
                                                            </time>
                                                        </span>
                                                    )}
                                            </td>
                                            <td className="px-2 py-2">
                                                {voucher.used_by_phone ??
                                                    voucher.assigned_to_phone ??
                                                    '—'}
                                            </td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td
                                            colSpan={4}
                                            className="px-2 py-6 text-center text-muted-foreground"
                                        >
                                            {phone_filter
                                                ? 'No vouchers found for that phone number.'
                                                : 'No vouchers have been created yet.'}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                    <div className="mt-4 flex items-center justify-between text-sm text-muted-foreground">
                        <span className="min-w-0 text-xs sm:text-sm">
                            {vouchers.total} voucher(s)
                            {vouchers.last_page > 1
                                ? ` · Page ${vouchers.current_page} of ${vouchers.last_page}`
                                : ''}
                        </span>
                        <div className="flex shrink-0 gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                disabled={vouchers.current_page <= 1}
                                onClick={() =>
                                    router.get(
                                        '/dashboard',
                                        {
                                            phone: phone_filter || undefined,
                                            page: vouchers.current_page - 1,
                                        },
                                        {
                                            preserveState: true,
                                            preserveScroll: true,
                                        },
                                    )
                                }
                            >
                                Previous
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={
                                    vouchers.current_page >= vouchers.last_page
                                }
                                onClick={() =>
                                    router.get(
                                        '/dashboard',
                                        {
                                            phone: phone_filter || undefined,
                                            page: vouchers.current_page + 1,
                                        },
                                        {
                                            preserveState: true,
                                            preserveScroll: true,
                                        },
                                    )
                                }
                            >
                                Next
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
