<?php

namespace Tests\Feature;

use App\Models\PaymentOrder;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_dashboard_explains_reserved_vouchers_and_can_filter_inventory_by_phone(): void
    {
        $this->actingAs(User::factory()->create());

        $assigned = Voucher::query()->create([
            'code' => 'SWALA1',
            'package_name' => '5 hours',
            'price_cents' => 500,
            'currency' => 'TZS',
            'status' => 'assigned',
            'assigned_to_phone' => '255700000001',
            'assigned_at' => '2026-10-20 12:34:56',
        ]);
        $reserved = Voucher::query()->create([
            'code' => 'TEMBO1',
            'package_name' => '5 hours',
            'price_cents' => 500,
            'currency' => 'TZS',
            'status' => 'reserved',
            'assigned_to_phone' => '255700000002',
        ]);
        Voucher::query()->create([
            'code' => 'SIMBA1',
            'package_name' => '5 hours',
            'price_cents' => 500,
            'currency' => 'TZS',
            'status' => 'available',
        ]);
        PaymentOrder::query()->create([
            'public_id' => (string) Str::uuid(),
            'voucher_id' => $reserved->id,
            'package_name' => '5 hours',
            'phone' => '255700000002',
            'amount' => 500,
            'currency' => 'TZS',
            'status' => 'unknown',
        ]);

        $this->get(route('dashboard', ['phone' => '255700000001']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('phone_filter', '255700000001')
                ->where('voucher_stats.available', 1)
                ->where('voucher_stats.reserved', 1)
                ->where('voucher_stats.assigned', 1)
                ->where('voucher_stats.used', 0)
                ->where('reserved_clearable', 0)
                ->has('vouchers.data', 1)
                ->where('vouchers.data.0.code', $assigned->code)
                ->where('vouchers.data.0.assigned_to_phone', '255700000001')
                ->has('vouchers.data.0.assigned_at'));

        $this->get(route('dashboard', ['phone' => '255700000002']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('vouchers.data', 1)
                ->where('vouchers.data.0.status', 'reserved')
                ->where('vouchers.data.0.payment_status', 'unknown'));
    }

    public function test_admin_can_clear_reserved_vouchers_older_than_five_minutes(): void
    {
        $this->actingAs(User::factory()->create());

        $oldReserved = Voucher::query()->create([
            'code' => 'RESERVED-001',
            'package_name' => '5 hours',
            'price_cents' => 500,
            'currency' => 'TZS',
            'status' => 'reserved',
            'assigned_to_phone' => '255700000001',
        ]);
        $unknownPayment = PaymentOrder::query()->create([
            'public_id' => (string) Str::uuid(),
            'voucher_id' => $oldReserved->id,
            'package_name' => '5 hours',
            'phone' => '255700000001',
            'amount' => 500,
            'currency' => 'TZS',
            'status' => 'unknown',
        ]);
        $unknownPayment->forceFill([
            'created_at' => now()->subMinutes(6),
            'updated_at' => now()->subMinutes(6),
        ])->save();

        $recentReserved = Voucher::query()->create([
            'code' => 'RESERVED-002',
            'package_name' => '5 hours',
            'price_cents' => 500,
            'currency' => 'TZS',
            'status' => 'reserved',
            'assigned_to_phone' => '255700000003',
        ]);
        $recentPayment = PaymentOrder::query()->create([
            'public_id' => (string) Str::uuid(),
            'voucher_id' => $recentReserved->id,
            'package_name' => '5 hours',
            'phone' => '255700000003',
            'amount' => 500,
            'currency' => 'TZS',
            'status' => 'pending',
        ]);
        $assigned = Voucher::query()->create([
            'code' => 'ASSIGNED-001',
            'package_name' => '5 hours',
            'price_cents' => 500,
            'currency' => 'TZS',
            'status' => 'assigned',
            'assigned_to_phone' => '255700000002',
        ]);

        $this->post(route('vouchers.clear-reserved'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success', '1 reservation(s) older than 5 minutes released. Check any related payments before reassigning vouchers.');

        $this->assertSame('available', $oldReserved->fresh()->status);
        $this->assertNull($oldReserved->fresh()->assigned_to_phone);
        $this->assertSame('released', $unknownPayment->fresh()->status);
        $this->assertSame('reserved', $recentReserved->fresh()->status);
        $this->assertSame('pending', $recentPayment->fresh()->status);
        $this->assertSame('assigned', $assigned->fresh()->status);
    }

    public function test_dashboard_counts_only_reserved_vouchers_older_than_five_minutes_for_admin_clear(): void
    {
        $this->actingAs(User::factory()->create());

        $oldVoucher = Voucher::query()->create([
            'code' => 'OLD-RESERVED',
            'package_name' => '5 hours',
            'price_cents' => 500,
            'currency' => 'TZS',
            'status' => 'reserved',
            'assigned_to_phone' => '255700000001',
        ]);
        $oldPayment = PaymentOrder::query()->create([
            'public_id' => (string) Str::uuid(),
            'voucher_id' => $oldVoucher->id,
            'package_name' => '5 hours',
            'phone' => '255700000001',
            'amount' => 500,
            'currency' => 'TZS',
            'status' => 'unknown',
        ]);
        $oldPayment->forceFill([
            'created_at' => Carbon::now()->subMinutes(6),
            'updated_at' => Carbon::now()->subMinutes(6),
        ])->save();

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('reserved_clear_minutes', 5)
                ->where('reserved_clearable', 1));
    }
}
