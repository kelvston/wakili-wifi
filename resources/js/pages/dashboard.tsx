import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import {
    ArrowDownToLine,
    ArrowRight,
    Boxes,
    CheckCircle2,
    Clock3,
    Search,
    ShieldCheck,
    Sparkles,
    TicketCheck,
    Wifi,
} from 'lucide-react';
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
            icon: TicketCheck,
            iconClass:
                'bg-emerald-500/10 text-emerald-600 ring-emerald-500/15 dark:text-emerald-300',
            stripeClass: 'bg-emerald-500/70',
        },
        {
            label: 'Reserved vouchers',
            value: voucher_stats.reserved,
            description: 'Checkout is pending or payment needs confirmation.',
            icon: Clock3,
            iconClass:
                'bg-amber-500/10 text-amber-600 ring-amber-500/15 dark:text-amber-300',
            stripeClass: 'bg-amber-500/70',
        },
        {
            label: 'Assigned vouchers',
            value: voucher_stats.assigned,
            description: 'Payment confirmed; issued to a phone.',
            icon: CheckCircle2,
            iconClass:
                'bg-sky-500/10 text-sky-600 ring-sky-500/15 dark:text-sky-300',
            stripeClass: 'bg-sky-500/70',
        },
        {
            label: 'Used vouchers',
            value: voucher_stats.used,
            description:
                'Marked used after your Wi-Fi system reports redemption.',
            icon: Wifi,
            iconClass:
                'bg-violet-500/10 text-violet-600 ring-violet-500/15 dark:text-violet-300',
            stripeClass: 'bg-violet-500/70',
        },
    ];
    const totalVouchers = Object.values(voucher_stats).reduce(
        (total, count) => total + count,
        0,
    );

    return (
        <>
            <Head title="Dashboard" />
            <div className="mx-auto flex h-full w-full max-w-[1600px] min-w-0 flex-1 flex-col gap-6 overflow-x-hidden p-4 sm:p-6">
                <section className="relative isolate overflow-hidden rounded-3xl bg-gradient-to-br from-slate-950 via-slate-900 to-emerald-950 px-6 py-7 text-white shadow-xl shadow-slate-950/10 sm:px-8 sm:py-9">
                    <div className="pointer-events-none absolute -top-20 -right-12 -z-10 h-72 w-72 rounded-full bg-emerald-400/15 blur-3xl" />
                    <div className="pointer-events-none absolute right-1/3 -bottom-28 -z-10 h-56 w-56 rounded-full bg-sky-400/10 blur-3xl" />
                    <div className="flex flex-col justify-between gap-8 sm:flex-row sm:items-end">
                        <div className="max-w-2xl">
                            <span className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-medium tracking-wide text-emerald-100">
                                <Sparkles className="size-3.5" />
                                WI-FI OPERATIONS
                            </span>
                            <h1 className="mt-4 text-3xl font-semibold tracking-tight sm:text-4xl">
                                Your business, at a glance.
                            </h1>
                            <p className="mt-3 max-w-xl text-sm leading-6 text-slate-300 sm:text-base">
                                Keep voucher stock organized, track customer
                                activity, and stay on top of every checkout.
                            </p>
                        </div>
                        <a
                            href="#voucher-inventory"
                            className="inline-flex w-fit items-center gap-2 rounded-xl border border-white/20 bg-white/10 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-white/15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                        >
                            View inventory
                            <ArrowRight className="size-4" />
                        </a>
                    </div>
                    <div className="mt-8 flex flex-wrap items-center gap-x-8 gap-y-3 border-t border-white/10 pt-5 text-sm">
                        <div className="flex items-center gap-2 text-slate-300">
                            <Boxes className="size-4 text-emerald-300" />
                            <span>Total stock</span>
                            <span className="font-semibold text-white">
                                {new Intl.NumberFormat().format(totalVouchers)}
                            </span>
                        </div>
                        <div className="flex items-center gap-2 text-slate-300">
                            <ShieldCheck className="size-4 text-emerald-300" />
                            <span>Available to sell</span>
                            <span className="font-semibold text-white">
                                {new Intl.NumberFormat().format(
                                    voucher_stats.available,
                                )}
                            </span>
                        </div>
                    </div>
                </section>

                <section aria-label="Voucher statistics">
                    <div className="mb-3 flex items-center justify-between gap-3">
                        <div>
                            <h2 className="text-lg font-semibold tracking-tight">
                                Stock overview
                            </h2>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                A live snapshot of your voucher inventory
                            </p>
                        </div>
                        <span className="hidden rounded-full border border-border/70 bg-card px-3 py-1 text-xs font-medium text-muted-foreground sm:inline-flex">
                            {new Intl.NumberFormat().format(totalVouchers)}{' '}
                            total vouchers
                        </span>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {statCards.map((stat) => (
                            <article
                                key={stat.label}
                                className="group relative overflow-hidden rounded-2xl border border-border/70 bg-card p-5 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-md"
                            >
                                <div
                                    className={`mb-5 flex size-11 items-center justify-center rounded-xl ring-1 ${stat.iconClass}`}
                                >
                                    <stat.icon className="size-5" />
                                </div>
                                <p className="text-sm font-medium text-muted-foreground">
                                    {stat.label}
                                </p>
                                <p className="mt-1 text-3xl font-semibold tracking-tight tabular-nums">
                                    {new Intl.NumberFormat().format(stat.value)}
                                </p>
                                <p className="mt-2 text-xs leading-5 text-muted-foreground">
                                    {stat.description}
                                </p>
                                <div
                                    className={`absolute inset-x-0 bottom-0 h-0.5 opacity-0 transition group-hover:opacity-100 ${stat.stripeClass}`}
                                />
                            </article>
                        ))}
                    </div>
                </section>

                <section className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm">
                    <div className="flex items-start gap-4 border-b border-border/70 bg-gradient-to-r from-emerald-500/[0.07] via-transparent to-sky-500/[0.05] px-5 py-5 sm:px-6">
                        <div className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-700 ring-1 ring-emerald-500/15 dark:text-emerald-300">
                            <ArrowDownToLine className="size-5" />
                        </div>
                        <div>
                            <h2 className="text-lg font-semibold tracking-tight">
                                Add voucher stock
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Restock packages by pasting codes or importing
                                an Excel sheet.
                            </p>
                        </div>
                    </div>

                    <div className="p-5 sm:p-6">
                        <p className="text-sm leading-6 text-muted-foreground">
                            Paste one voucher code per line or upload an Excel
                            file with codes in the first column of the first
                            worksheet. Codes already in the inventory will be
                            skipped.
                        </p>

                        {success && (
                            <p
                                role="status"
                                className="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-200"
                            >
                                {success}
                            </p>
                        )}

                        {reserved_clearable > 0 && (
                            <div className="mt-5 flex flex-col gap-3 rounded-xl border border-amber-300/70 bg-amber-50/80 p-4 text-sm text-amber-950 sm:flex-row sm:items-center sm:justify-between dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
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
                            className="mt-6 grid gap-5 md:grid-cols-[minmax(0,0.9fr)_minmax(0,1.5fr)_minmax(0,1.2fr)] xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.5fr)_minmax(0,1.2fr)_auto] xl:items-end"
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
                                    className="h-11 rounded-xl border border-input bg-background px-3 text-sm shadow-sm transition outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                                >
                                    {packages.map((pkg) => (
                                        <option key={pkg.name} value={pkg.name}>
                                            {pkg.duration} — TSh{' '}
                                            {new Intl.NumberFormat(
                                                'en-TZ',
                                            ).format(pkg.price)}
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
                                <Label htmlFor="voucher-codes">
                                    Voucher codes
                                </Label>
                                <textarea
                                    id="voucher-codes"
                                    value={form.data.codes}
                                    onChange={(event) =>
                                        form.setData(
                                            'codes',
                                            event.target.value,
                                        )
                                    }
                                    rows={4}
                                    placeholder={'WIFI-CODE-001\nWIFI-CODE-002'}
                                    className="w-full rounded-xl border border-input bg-background px-3 py-2.5 text-sm shadow-sm transition outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50"
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
                                    className="w-full rounded-xl border border-dashed border-input bg-muted/30 px-3 py-2.5 text-sm transition file:mr-3 file:rounded-lg file:border-0 file:bg-primary file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-primary-foreground hover:bg-muted/60"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Put one code per row in column A. Format
                                    codes as text in Excel to preserve leading
                                    zeros.
                                </p>
                                {form.errors.file && (
                                    <p className="text-sm text-destructive">
                                        {form.errors.file}
                                    </p>
                                )}
                            </div>

                            <Button
                                type="submit"
                                className="h-11 rounded-xl px-5 shadow-sm xl:mb-0"
                                disabled={
                                    form.processing || packages.length === 0
                                }
                            >
                                {form.processing ? 'Adding…' : 'Add vouchers'}
                            </Button>
                        </form>
                    </div>
                </section>

                <section
                    id="voucher-inventory"
                    className="min-w-0 overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm"
                >
                    <div className="flex flex-col gap-4 border-b border-border/70 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div>
                            <div className="flex items-center gap-2">
                                <h2 className="text-lg font-semibold tracking-tight">
                                    Voucher inventory
                                </h2>
                                <span className="rounded-full bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground tabular-nums">
                                    {new Intl.NumberFormat().format(
                                        vouchers.total,
                                    )}
                                </span>
                            </div>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Search by phone to see vouchers reserved for,
                                assigned to, or used by that customer.
                            </p>
                        </div>
                        <form
                            className="flex w-full gap-2 sm:w-auto"
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
                                className="h-10 w-full rounded-xl border border-input bg-background px-3 text-sm shadow-sm transition outline-none focus-visible:ring-2 focus-visible:ring-ring/50 sm:w-60"
                            />
                            <Button type="submit" className="rounded-xl">
                                <Search className="mr-2 size-4" />
                                Search
                            </Button>
                        </form>
                    </div>

                    <div className="grid gap-3 p-4 md:hidden">
                        {vouchers.data.length > 0 ? (
                            vouchers.data.map((voucher) => (
                                <article
                                    key={voucher.code}
                                    className="min-w-0 rounded-xl border border-border/70 bg-background p-4 shadow-sm"
                                >
                                    <div className="flex min-w-0 items-start justify-between gap-2">
                                        <p className="min-w-0 font-medium break-all">
                                            {voucher.code}
                                        </p>
                                        <span
                                            className={`shrink-0 rounded-full px-2.5 py-1 text-right text-xs font-medium capitalize ${voucher.status === 'assigned' ? 'bg-sky-500/10 text-sky-700 dark:text-sky-300' : voucher.status === 'used' ? 'bg-violet-500/10 text-violet-700 dark:text-violet-300' : voucher.status === 'reserved' ? 'bg-amber-500/10 text-amber-700 dark:text-amber-300' : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'}`}
                                        >
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
                                <tr className="border-b border-border bg-muted/40 text-xs tracking-wide text-muted-foreground uppercase">
                                    <th className="px-6 py-3 font-medium">
                                        Code
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Package
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Customer phone
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {vouchers.data.length > 0 ? (
                                    vouchers.data.map((voucher) => (
                                        <tr
                                            key={voucher.code}
                                            className="border-b border-border/60 transition-colors last:border-0 hover:bg-muted/30"
                                        >
                                            <td className="px-6 py-3.5 font-medium">
                                                <span className="rounded-md bg-muted/70 px-2 py-1 font-mono text-xs">
                                                    {voucher.code}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3.5">
                                                {voucher.package_name}
                                            </td>
                                            <td className="px-4 py-3.5">
                                                <span
                                                    className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${voucher.status === 'assigned' ? 'bg-sky-500/10 text-sky-700 dark:text-sky-300' : voucher.status === 'used' ? 'bg-violet-500/10 text-violet-700 dark:text-violet-300' : voucher.status === 'reserved' ? 'bg-amber-500/10 text-amber-700 dark:text-amber-300' : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'}`}
                                                >
                                                    {voucher.status ===
                                                    'reserved'
                                                        ? voucher.payment_status ===
                                                          'unknown'
                                                            ? 'Reserved — payment outcome unknown'
                                                            : 'Reserved — checkout in progress'
                                                        : voucher.status}
                                                </span>
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
                                            <td className="px-4 py-3.5 text-muted-foreground">
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
                                            className="px-6 py-12 text-center text-muted-foreground"
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
                    <div className="flex flex-col gap-3 border-t border-border/70 px-5 py-4 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between sm:px-6">
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
                                className="rounded-xl"
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
                                className="rounded-xl"
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
                </section>
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
