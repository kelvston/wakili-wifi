<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class VoucherPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_add_vouchers_for_a_catalog_package(): void
    {
        $this->actingAs(User::factory()->create());

        $this->from(route('dashboard'))
            ->post(route('vouchers.store'), [
                'package_name' => '5 hours',
                'codes' => "WIFI-5H-001\nWIFI-5H-002",
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success', '2 voucher(s) added to the 5 hours package.');

        $this->assertDatabaseHas('vouchers', [
            'code' => 'WIFI-5H-001',
            'package_name' => '5 hours',
            'price_cents' => 500,
            'currency' => 'TZS',
            'status' => 'available',
        ]);
        $this->assertDatabaseCount('vouchers', 2);
    }

    public function test_admin_added_voucher_codes_keep_their_original_case(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(route('vouchers.store'), [
            'package_name' => '5 hours',
            'codes' => 'swala1',
        ])->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('vouchers', [
            'code' => 'swala1',
            'package_name' => '5 hours',
        ]);
    }

    public function test_admin_can_import_voucher_codes_from_an_excel_file(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(route('vouchers.store'), [
            'package_name' => '5 hours',
            'codes' => '',
            'file' => $this->excelUpload([
                ['Voucher Code', 'Notes'],
                ['swala1', 'This column is ignored'],
                ['00123', null],
                [null, null],
            ]),
        ])->assertRedirect(route('dashboard'))
            ->assertSessionHas('success', '2 voucher(s) added to the 5 hours package.');

        $this->assertDatabaseHas('vouchers', [
            'code' => 'swala1',
            'package_name' => '5 hours',
            'price_cents' => 500,
        ]);
        $this->assertDatabaseHas('vouchers', [
            'code' => '00123',
            'package_name' => '5 hours',
        ]);
        $this->assertDatabaseCount('vouchers', 2);
    }

    public function test_empty_excel_import_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        $this->from(route('dashboard'))
            ->post(route('vouchers.store'), [
                'package_name' => '5 hours',
                'codes' => '',
                'file' => $this->excelUpload([
                    ['Voucher Code'],
                ]),
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('codes');

        $this->assertDatabaseCount('vouchers', 0);
    }

    public function test_admin_cannot_add_codes_for_a_package_outside_the_catalog(): void
    {
        $this->actingAs(User::factory()->create());

        $this->from(route('dashboard'))
            ->post(route('vouchers.store'), [
                'package_name' => 'Custom package',
                'codes' => 'WIFI-CUSTOM-001',
            ])
            ->assertSessionHasErrors('package_name');

        $this->assertDatabaseCount('vouchers', 0);
    }

    public function test_duplicate_voucher_codes_are_not_added_twice(): void
    {
        $this->actingAs(User::factory()->create());

        Voucher::query()->create([
            'code' => 'WIFI-5H-001',
            'package_name' => '5 hours',
            'price_cents' => 500,
            'currency' => 'TZS',
            'status' => 'available',
        ]);

        $this->from(route('dashboard'))
            ->post(route('vouchers.store'), [
                'package_name' => '5 hours',
                'codes' => "WIFI-5H-001\nWIFI-5H-002",
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success', '1 voucher(s) added to the 5 hours package.');

        $this->assertDatabaseCount('vouchers', 2);
    }

    public function test_large_voucher_import_is_inserted_in_batches(): void
    {
        $this->actingAs(User::factory()->create());
        $codes = array_map(
            fn (int $number) => 'BATCH-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT),
            range(1, 1001),
        );

        $this->post(route('vouchers.store'), [
            'package_name' => '5 hours',
            'codes' => implode("\n", $codes),
        ])->assertRedirect(route('dashboard'))
            ->assertSessionHas('success', '1001 voucher(s) added to the 5 hours package.');

        $this->assertDatabaseCount('vouchers', 1001);
        $this->assertDatabaseHas('vouchers', [
            'code' => 'BATCH-1001',
            'package_name' => '5 hours',
            'status' => 'available',
        ]);
    }

    public function test_it_assigns_an_available_voucher_to_the_paying_phone_number(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Voucher::query()->create([
            'code' => 'WIFI-DAY-001',
            'package_name' => 'Daily',
            'price_cents' => 2500,
            'currency' => 'KES',
            'status' => 'available',
        ]);

        Voucher::query()->create([
            'code' => 'WIFI-DAY-002',
            'package_name' => 'Daily',
            'price_cents' => 2500,
            'currency' => 'KES',
            'status' => 'available',
        ]);

        $response = $this->postJson(route('vouchers.assign'), [
            'phone' => '254712345678',
            'package_name' => 'Daily',
        ]);

        $response->assertOk();
        $this->assertSame('assigned', Voucher::query()->where('assigned_to_phone', '254712345678')->first()->status);
        $this->assertCount(1, Voucher::query()->where('status', 'assigned')->get());
    }

    public function test_it_prevents_a_used_voucher_from_being_reused_by_another_phone_number(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Voucher::query()->create([
            'code' => 'WIFI-USED-001',
            'package_name' => 'Weekly',
            'price_cents' => 6000,
            'currency' => 'KES',
            'status' => 'used',
            'assigned_to_phone' => '254700000001',
            'assigned_at' => now(),
            'used_at' => now(),
            'used_by_phone' => '254700000001',
        ]);

        $response = $this->postJson(route('vouchers.redeem'), [
            'phone' => '254700000002',
            'voucher_code' => 'WIFI-USED-001',
        ]);

        $response->assertUnprocessable();
    }

    public function test_lowercase_voucher_code_can_be_redeemed_without_changing_its_case(): void
    {
        $this->actingAs(User::factory()->create());

        Voucher::query()->create([
            'code' => 'swala1',
            'package_name' => '5 hours',
            'price_cents' => 500,
            'currency' => 'TZS',
            'status' => 'assigned',
            'assigned_to_phone' => '255712345678',
        ]);

        $this->postJson(route('vouchers.redeem'), [
            'phone' => '255712345678',
            'voucher_code' => 'swala1',
        ])
            ->assertOk()
            ->assertJsonPath('voucher.code', 'swala1');

        $this->assertDatabaseHas('vouchers', [
            'code' => 'swala1',
            'status' => 'used',
        ]);
    }

    /**
     * @param  array<int, array<int, string|null>>  $rows
     */
    private function excelUpload(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'voucher-codes-');

        try {
            (new Xlsx($spreadsheet))->save($path);
            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new \RuntimeException('Failed to read the generated Excel fixture.');
            }
        } finally {
            unlink($path);
            $spreadsheet->disconnectWorksheets();
        }

        return UploadedFile::fake()->createWithContent('voucher-codes.xlsx', $contents);
    }
}
