<?php

namespace Tests\Feature;

use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_a_successful_response()
    {
        $response = $this->get(route('home'));

        $response->assertOk();
    }

    public function test_homepage_shows_available_packages_without_exposing_voucher_codes(): void
    {
        config([
            'services.sonicpesa.api_key' => null,
            'services.sonicpesa.secret_key' => null,
        ]);

        Voucher::query()->create([
            'code' => 'PRIVATE-CODE-001',
            'package_name' => '5 hours',
            'price_cents' => 500,
            'currency' => 'TZS',
            'status' => 'available',
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portal')
                ->has('packages', 8)
                ->where('packages.0.name', '5 hours')
                ->where('packages.0.price', 500)
                ->where('packages.0.currency', 'TZS')
                ->where('packages.0.available_count', 1)
                ->where('packages.0.stock_count', 1)
                ->where('packages.7.name', '30 days')
                ->where('packages.7.price', 30000)
                ->where('payment_ready', false)
                ->missing('packages.0.code'));
    }

    public function test_homepage_does_not_show_assigned_or_used_vouchers_as_available(): void
    {
        Voucher::query()->create([
            'code' => 'ALREADY-ASSIGNED',
            'package_name' => '5 hours',
            'price_cents' => 500,
            'currency' => 'TZS',
            'status' => 'assigned',
            'assigned_to_phone' => '254700000001',
        ]);

        $this->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('portal')
                ->where('packages.0.available_count', 0)
                ->where('packages.0.stock_count', 1));
    }
}
