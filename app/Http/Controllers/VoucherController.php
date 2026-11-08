<?php

namespace App\Http\Controllers;

use App\Models\PaymentOrder;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class VoucherController extends Controller
{
    private const RESERVED_CLEAR_MINUTES = 5;

    public function portal()
    {
        $inventoryCounts = Voucher::query()
            ->selectRaw('package_name, COUNT(*) as total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as available', ['available'])
            ->groupBy('package_name')
            ->get()
            ->keyBy('package_name');

        $packages = collect(config('wifi.packages'))
            ->map(function (array $package) use ($inventoryCounts) {
                $inventory = $inventoryCounts->get($package['name']);

                return [
                    ...$package,
                    'currency' => 'TZS',
                    'available_count' => (int) ($inventory?->available ?? 0),
                    'stock_count' => (int) ($inventory?->total ?? 0),
                ];
            })
            ->values();

        return Inertia::render('portal', [
            'packages' => $packages,
            'payment_ready' => filled(config('services.sonicpesa.api_key'))
                && filled(config('services.sonicpesa.secret_key')),
        ]);
    }

    public function dashboard(Request $request)
    {
        $phone = trim((string) $request->query('phone', ''));
        $reservedClearCutoff = now()->subMinutes(self::RESERVED_CLEAR_MINUTES);
        $vouchers = Voucher::query()
            ->with('paymentOrder:id,voucher_id,status')
            ->latest();

        if ($phone !== '') {
            $vouchers->where(function ($query) use ($phone) {
                $query->where('assigned_to_phone', 'like', "%{$phone}%")
                    ->orWhere('used_by_phone', 'like', "%{$phone}%");
            });
        }

        $vouchers = $vouchers->paginate(25)->withQueryString();
        $vouchers->through(fn (Voucher $voucher) => [
            'code' => $voucher->code,
            'package_name' => $voucher->package_name,
            'status' => $voucher->status,
            'payment_status' => $voucher->paymentOrder?->status,
            'assigned_to_phone' => $voucher->assigned_to_phone,
            'assigned_at' => $voucher->assigned_at,
            'used_by_phone' => $voucher->used_by_phone,
        ]);
        $voucherStats = Voucher::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return Inertia::render('dashboard', [
            'packages' => config('wifi.packages'),
            'success' => $request->session()->get('success'),
            'phone_filter' => $phone,
            'reserved_clear_minutes' => self::RESERVED_CLEAR_MINUTES,
            'reserved_clearable' => PaymentOrder::query()
                ->whereIn('status', ['pending', 'unknown'])
                ->where('created_at', '<', $reservedClearCutoff)
                ->whereHas('voucher', fn ($query) => $query
                    ->where('status', 'reserved')
                    ->whereColumn('vouchers.assigned_to_phone', 'payment_orders.phone'))
                ->count(),
            'voucher_stats' => [
                'available' => (int) $voucherStats->get('available', 0),
                'reserved' => (int) $voucherStats->get('reserved', 0),
                'assigned' => (int) $voucherStats->get('assigned', 0),
                'used' => (int) $voucherStats->get('used', 0),
            ],
            'vouchers' => $vouchers,
        ]);
    }

    public function index()
    {
        return response()->json([
            'voucher_stats' => [
                'available' => Voucher::available()->count(),
                'assigned' => Voucher::query()->where('status', 'assigned')->count(),
                'used' => Voucher::query()->where('status', 'used')->count(),
            ],
            'vouchers' => Voucher::latest()->take(20)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $packageNames = collect(config('wifi.packages'))->pluck('name')->all();
        $data = $request->validate([
            'package_name' => ['required', 'string', 'in:'.implode(',', $packageNames)],
            'codes' => ['nullable', 'string', 'required_without:file'],
            'file' => ['nullable', 'file', 'mimes:xlsx', 'max:10240', 'required_without:codes'],
        ]);

        $codes = [];

        if (filled($data['codes'] ?? null)) {
            $codes = preg_split('/\r\n|\n|\r/', $data['codes']) ?: [];
        }

        if ($request->hasFile('file')) {
            $codes = array_merge($codes, $this->spreadsheetVoucherCodes($request->file('file')));
        }

        $codes = array_values(array_unique(array_filter(
            array_map(fn (string $code) => trim($code), $codes),
            fn (string $code) => $code !== '',
        )));

        if ($codes === []) {
            throw ValidationException::withMessages([
                'codes' => ['No voucher codes were found. Paste codes or upload an Excel file with codes in the first column.'],
            ]);
        }

        $package = collect(config('wifi.packages'))
            ->firstWhere('name', $data['package_name']);

        $created = DB::transaction(function () use ($codes, $package) {
            $created = 0;
            $timestamp = now();

            foreach (array_chunk($codes, 500) as $codeChunk) {
                $rows = array_map(fn (string $code) => [
                    'code' => $code,
                    'package_name' => $package['name'],
                    'price_cents' => $package['price'],
                    'currency' => 'TZS',
                    'status' => 'available',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ], $codeChunk);

                $created += DB::table('vouchers')->insertOrIgnore($rows);
            }

            return $created;
        });

        if ($request->expectsJson()) {
            return response()->json([
                'created' => $created,
                'package_name' => $data['package_name'],
            ], 201);
        }

        $message = $created > 0
            ? "{$created} voucher(s) added to the {$data['package_name']} package."
            : 'Those voucher codes were already in the inventory; no duplicates were added.';

        return redirect()->route('dashboard')->with('success', $message);
    }

    public function clearReserved()
    {
        $released = DB::transaction(function () {
            $cutoff = now()->subMinutes(self::RESERVED_CLEAR_MINUTES);
            $payments = PaymentOrder::query()
                ->whereIn('status', ['pending', 'unknown'])
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $released = 0;

            foreach ($payments as $payment) {
                $voucher = Voucher::query()
                    ->whereKey($payment->voucher_id)
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $voucher
                    || $voucher->status !== 'reserved'
                    || $voucher->assigned_to_phone !== $payment->phone
                ) {
                    continue;
                }

                $voucher->update([
                    'status' => 'available',
                    'assigned_to_phone' => null,
                ]);
                $payment->update(['status' => 'released']);
                $released++;
            }

            return $released;
        });

        $message = $released > 0
            ? "{$released} reservation(s) older than ".self::RESERVED_CLEAR_MINUTES.' minutes released. Check any related payments before reassigning vouchers.'
            : 'There are no reservations older than '.self::RESERVED_CLEAR_MINUTES.' minutes to release.';

        return redirect()->route('dashboard')->with('success', $message);
    }

    /**
     * @return list<string>
     */
    private function spreadsheetVoucherCodes(UploadedFile $file): array
    {
        $spreadsheet = null;
        $path = $file->getRealPath();

        if ($path === false) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded Excel file could not be accessed.'],
            ]);
        }

        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $worksheets = $reader->listWorksheetInfo($path);

            if ($worksheets === []) {
                throw ValidationException::withMessages([
                    'file' => ['The Excel file does not contain a worksheet.'],
                ]);
            }

            $reader->setLoadSheetsOnly($worksheets[0]['worksheetName']);
            $spreadsheet = $reader->load($path);
            $worksheet = $spreadsheet->getActiveSheet();
            $lastRow = $worksheet->getHighestDataRow('A');

            if ($lastRow > 10000) {
                throw ValidationException::withMessages([
                    'file' => ['The Excel file cannot contain more than 10,000 rows in the first column.'],
                ]);
            }

            $codes = [];
            for ($row = 1; $row <= $lastRow; $row++) {
                $code = trim($worksheet->getCell("A{$row}")->getFormattedValue());

                if ($row === 1 && in_array(strtolower($code), ['code', 'voucher code', 'voucher_code'], true)) {
                    continue;
                }

                if ($code !== '') {
                    $codes[] = $code;
                }
            }

            return $codes;
        } catch (SpreadsheetException $exception) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded file could not be read as an Excel workbook.'],
            ]);
        } finally {
            $spreadsheet?->disconnectWorksheets();
        }
    }

    public function assign(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'min:7', 'max:20'],
            'package_name' => ['nullable', 'string', 'max:100'],
            'voucher_code' => ['nullable', 'string', 'max:100'],
        ]);

        return DB::transaction(function () use ($data) {
            $voucher = $this->resolveVoucherForAssignment($data);

            $voucher->assignTo($data['phone']);

            return response()->json([
                'message' => 'Voucher assigned successfully.',
                'voucher' => $voucher->fresh(),
            ]);
        });
    }

    public function redeem(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'min:7', 'max:20'],
            'voucher_code' => ['required', 'string', 'max:100'],
        ]);

        $voucher = Voucher::query()->where('code', $data['voucher_code'])->first();

        if (! $voucher) {
            throw ValidationException::withMessages([
                'voucher_code' => ['Voucher not found.'],
            ]);
        }

        try {
            $voucher->redeemFor($data['phone']);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'voucher_code' => [$exception->getMessage()],
            ]);
        }

        return response()->json([
            'message' => 'Voucher redeemed successfully.',
            'voucher' => $voucher->fresh(),
        ]);
    }

    protected function resolveVoucherForAssignment(array $data): Voucher
    {
        if (! empty($data['voucher_code'])) {
            $voucher = Voucher::query()
                ->where('code', $data['voucher_code'])
                ->first();

            if (! $voucher) {
                throw ValidationException::withMessages([
                    'voucher_code' => ['Voucher not found.'],
                ]);
            }

            if ($voucher->status === 'used') {
                throw ValidationException::withMessages([
                    'voucher_code' => ['This voucher has already been used and cannot be assigned again.'],
                ]);
            }

            if ($voucher->assigned_to_phone && $voucher->assigned_to_phone !== $data['phone']) {
                throw ValidationException::withMessages([
                    'voucher_code' => ['This voucher belongs to another phone number.'],
                ]);
            }

            return $voucher;
        }

        $query = Voucher::query()->where('status', 'available');

        if (! empty($data['package_name'])) {
            $query->where('package_name', $data['package_name']);
        }

        $voucher = $query->lockForUpdate()->first();

        if (! $voucher) {
            throw ValidationException::withMessages([
                'package_name' => ['There are no available vouchers for the requested package.'],
            ]);
        }

        return $voucher;
    }
}
