<?php

namespace Tests\Feature;

use App\Models\PaymentOrder;
use App\Models\Voucher;
use App\Services\SonicPesaGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.sonicpesa.api_key' => 'test-api-key',
            'services.sonicpesa.secret_key' => 'test-secret-key',
            'services.sonicpesa.base_url' => 'https://api.sonicpesa.test',
            'services.sonicpesa.timeout' => 5,
            'services.nextbyte.api_key' => 'test-nextbyte-key',
            'services.nextbyte.sender_id' => 'Wakili M',
            'services.nextbyte.sms_url' => 'https://messaging-service.co.tz/api/sms/v2/text/single',
            'services.nextbyte.timeout' => 5,
        ]);

        Http::fake();
    }

    public function test_customer_can_start_checkout_without_an_account_and_voucher_is_reserved(): void
    {
        $gateway = $this->mockGateway();
        $gateway->shouldReceive('createOrder')
            ->once()
            ->with([
                'buyer_email' => 'noemail@sonicpesa.com',
                'buyer_name' => 'Wi-Fi Customer',
                'buyer_phone' => '255712345678',
                'amount' => 500,
                'currency' => 'TZS',
            ])
            ->andReturn(['status' => 'success', 'order_id' => 'sp-order-123']);

        $voucher = $this->createAvailableVoucher();

        $response = $this->post(route('payments.start'), [
            'package_name' => '5 hours',
            'phone' => '255712345678',
        ]);

        $payment = PaymentOrder::query()->sole();

        $response->assertRedirect(route('payments.show', $payment));
        $this->assertGuest();
        $this->assertSame('pending', $payment->status);
        $this->assertSame('sp-order-123', $payment->provider_order_id);
        $this->assertSame('255712345678', $payment->phone);
        $this->assertDatabaseHas('vouchers', [
            'id' => $voucher->id,
            'status' => 'reserved',
            'assigned_to_phone' => '255712345678',
        ]);

    }

    public function test_voucher_is_only_issued_after_sonicpesa_confirms_payment(): void
    {
        $gateway = $this->mockGateway();
        $gateway->shouldReceive('createOrder')
            ->once()
            ->andReturn(['status' => 'pending', 'order_id' => 'sp-order-456']);
        $gateway->shouldReceive('orderStatus')
            ->twice()
            ->with('sp-order-456')
            ->andReturn(
                [
                    'status' => 'pending',
                    'order_id' => 'sp-order-456',
                    'amount' => 500,
                    'currency' => 'TZS',
                ],
                [
                    'status' => 'success',
                    'order_id' => 'sp-order-456',
                    'amount' => 500,
                    'currency' => 'TZS',
                ],
            );

        $voucher = $this->createAvailableVoucher();

        $this->post(route('payments.start'), [
            'package_name' => '5 hours',
            'phone' => '255712345678',
        ])->assertRedirect();

        $payment = PaymentOrder::query()->sole();

        $this->getJson(route('payments.status', $payment))
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('voucher_code', null);

        $this->assertSame('reserved', $voucher->fresh()->status);
        Http::assertNothingSent();

        $this->getJson(route('payments.status', $payment))
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('voucher_code', $voucher->code);

        $this->assertSame('assigned', $voucher->fresh()->status);
        $this->assertSame('255712345678', $voucher->fresh()->assigned_to_phone);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->getJson(route('payments.status', $payment))
            ->assertOk()
            ->assertJsonPath('status', 'paid');
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://messaging-service.co.tz/api/sms/v2/text/single'
            && $request->hasHeader('Authorization', 'Bearer test-nextbyte-key')
            && $request['from'] === 'Wakili M'
            && $request['to'] === '255712345678'
            && $request['text'] === "Hello, you have subscribed to Wakili Wi-Fi with the 5 hours bundle. Your voucher code is {$voucher->code}. Welcome, and enjoy our service!"
            && $request['flash'] === 0
            && is_string($request['reference'])
            && $request['reference'] !== '');
    }

    public function test_failed_payment_releases_reserved_voucher(): void
    {
        $gateway = $this->mockGateway();
        $gateway->shouldReceive('createOrder')
            ->once()
            ->andReturn(['status' => 'pending', 'order_id' => 'sp-order-789']);
        $gateway->shouldReceive('orderStatus')
            ->once()
            ->with('sp-order-789')
            ->andReturn([
                'status' => 'failed',
                'order_id' => 'sp-order-789',
                'amount' => 500,
                'currency' => 'TZS',
            ]);

        $voucher = $this->createAvailableVoucher();

        $this->post(route('payments.start'), [
            'package_name' => '5 hours',
            'phone' => '255712345678',
        ])->assertRedirect();

        $payment = PaymentOrder::query()->sole();

        $this->getJson(route('payments.status', $payment))
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('voucher_code', null);

        $this->assertSame('available', $voucher->fresh()->status);
        $this->assertNull($voucher->fresh()->assigned_to_phone);
    }

    public function test_pending_checkout_releases_its_voucher_after_three_minutes(): void
    {
        $gateway = $this->mockGateway();
        $gateway->shouldReceive('createOrder')
            ->once()
            ->andReturn(['status' => 'pending', 'order_id' => 'sp-order-timeout']);
        $gateway->shouldReceive('orderStatus')
            ->once()
            ->with('sp-order-timeout')
            ->andReturn([
                'status' => 'pending',
                'order_id' => 'sp-order-timeout',
                'amount' => 500,
                'currency' => 'TZS',
            ]);

        $voucher = $this->createAvailableVoucher();

        $this->post(route('payments.start'), [
            'package_name' => '5 hours',
            'phone' => '255712345678',
        ])->assertRedirect();

        $payment = PaymentOrder::query()->sole();
        $this->travel(4)->minutes();

        $this->artisan('payments:expire-pending')
            ->expectsOutput('Expired 1 unpaid checkout(s).')
            ->assertSuccessful();

        $this->assertSame('expired', $payment->fresh()->status);
        $this->assertSame('available', $voucher->fresh()->status);
        $this->assertNull($voucher->fresh()->assigned_to_phone);
    }

    public function test_customer_can_cancel_pending_checkout_and_release_voucher(): void
    {
        $gateway = $this->mockGateway();
        $gateway->shouldReceive('createOrder')
            ->once()
            ->andReturn(['status' => 'pending', 'order_id' => 'sp-order-cancel']);

        $voucher = $this->createAvailableVoucher();

        $this->post(route('payments.start'), [
            'package_name' => '5 hours',
            'phone' => '255712345678',
        ])->assertRedirect();

        $payment = PaymentOrder::query()->sole();

        $this->postJson(route('payments.cancel', $payment))
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertSame('available', $voucher->fresh()->status);
        $this->assertNull($voucher->fresh()->assigned_to_phone);

        $this->getJson(route('payments.status', $payment))
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');
    }

    public function test_cancelling_a_confirmed_payment_does_not_release_its_voucher(): void
    {
        $gateway = $this->mockGateway();
        $gateway->shouldReceive('createOrder')
            ->once()
            ->andReturn(['status' => 'pending', 'order_id' => 'sp-order-already-paid']);

        $voucher = $this->createAvailableVoucher();

        $this->post(route('payments.start'), [
            'package_name' => '5 hours',
            'phone' => '255712345678',
        ])->assertRedirect();

        $payment = PaymentOrder::query()->sole();
        $payment->update(['status' => 'paid']);
        $voucher->update(['status' => 'assigned']);

        $this->postJson(route('payments.cancel', $payment))
            ->assertOk()
            ->assertJsonPath('status', 'paid');

        $this->assertSame('assigned', $voucher->fresh()->status);
        $this->assertSame('paid', $payment->fresh()->status);
    }

    public function test_late_provider_confirmation_keeps_the_voucher_assigned(): void
    {
        $gateway = $this->mockGateway();
        $gateway->shouldReceive('createOrder')
            ->once()
            ->andReturn(['status' => 'pending', 'order_id' => 'sp-order-late-paid']);
        $gateway->shouldReceive('orderStatus')
            ->once()
            ->with('sp-order-late-paid')
            ->andReturn([
                'status' => 'success',
                'order_id' => 'sp-order-late-paid',
                'amount' => 500,
                'currency' => 'TZS',
            ]);

        $voucher = $this->createAvailableVoucher();

        $this->post(route('payments.start'), [
            'package_name' => '5 hours',
            'phone' => '255712345678',
        ])->assertRedirect();

        $payment = PaymentOrder::query()->sole();
        $this->travel(4)->minutes();

        $this->artisan('payments:expire-pending')
            ->expectsOutput('Expired 0 unpaid checkout(s).')
            ->assertSuccessful();

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('assigned', $voucher->fresh()->status);
        $this->assertSame('255712345678', $voucher->fresh()->assigned_to_phone);
    }

    public function test_provider_server_error_is_reported_with_reference_and_does_not_free_reserved_voucher(): void
    {
        $gateway = $this->mockGateway();
        $gateway->shouldReceive('createOrder')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'Server error: POST create_order resulted in a 500 Internal Server Error response.',
            ]);

        $voucher = $this->createAvailableVoucher();

        $response = $this->from(route('home'))->post(route('payments.start'), [
            'package_name' => '5 hours',
            'phone' => '255712345678',
        ]);

        $payment = PaymentOrder::query()->sole();

        $response->assertRedirect(route('home'))
            ->assertSessionHasErrors([
                'phone' => 'SonicPesa could not confirm checkout creation (HTTP 500). The payment may still have been received. Do not retry yet; contact support with reference '.$payment->public_id.'.',
            ]);

        $this->assertSame('unknown', $payment->status);
        $this->assertSame('reserved', $voucher->fresh()->status);
    }

    public function test_mismatched_provider_order_is_not_treated_as_a_payment(): void
    {
        $gateway = $this->mockGateway();
        $gateway->shouldReceive('createOrder')
            ->once()
            ->andReturn(['status' => 'pending', 'order_id' => 'sp-order-real']);
        $gateway->shouldReceive('orderStatus')
            ->once()
            ->with('sp-order-real')
            ->andReturn([
                'status' => 'success',
                'order_id' => 'sp-order-other',
                'amount' => 500,
                'currency' => 'TZS',
            ]);

        $voucher = $this->createAvailableVoucher();

        $this->post(route('payments.start'), [
            'package_name' => '5 hours',
            'phone' => '255712345678',
        ])->assertRedirect();

        $payment = PaymentOrder::query()->sole();

        $this->getJson(route('payments.status', $payment))
            ->assertStatus(503)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('voucher_code', null);

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('reserved', $voucher->fresh()->status);
    }

    public function test_payment_is_not_confirmed_without_provider_amount_and_currency(): void
    {
        $gateway = $this->mockGateway();
        $gateway->shouldReceive('createOrder')
            ->once()
            ->andReturn(['status' => 'pending', 'order_id' => 'sp-order-verified']);
        $gateway->shouldReceive('orderStatus')
            ->once()
            ->with('sp-order-verified')
            ->andReturn([
                'status' => 'success',
                'order_id' => 'sp-order-verified',
            ]);

        $voucher = $this->createAvailableVoucher();

        $this->post(route('payments.start'), [
            'package_name' => '5 hours',
            'phone' => '255712345678',
        ])->assertRedirect();

        $payment = PaymentOrder::query()->sole();

        $this->getJson(route('payments.status', $payment))
            ->assertStatus(503)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('voucher_code', null);

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('reserved', $voucher->fresh()->status);
    }

    private function mockGateway(): MockInterface
    {
        $gateway = Mockery::mock(SonicPesaGateway::class);
        $this->app->instance(SonicPesaGateway::class, $gateway);

        return $gateway;
    }

    private function createAvailableVoucher(): Voucher
    {
        return Voucher::query()->create([
            'code' => 'WIFI-5H-001',
            'package_name' => '5 hours',
            'price_cents' => 500,
            'currency' => 'TZS',
            'status' => 'available',
        ]);
    }
}
