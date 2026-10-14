<?php

namespace Tests\Feature;

use App\Application\SandboxBilling;
use App\Application\SandboxOperations;
use App\Domain\Providers\SandboxData;
use App\Exceptions\SandboxUnavailable;
use App\Infrastructure\Providers\MollieSandboxProvider;
use App\Infrastructure\Providers\PayPalSandboxProvider;
use App\Infrastructure\Providers\SandboxCatalog;
use App\Infrastructure\Providers\SandboxHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\SandboxTestCase;

class SandboxProvidersTest extends SandboxTestCase
{
    use RefreshDatabase;

    public function test_paypal_uses_sandbox_oauth_subscription_checkout_and_sale_verification(): void
    {
        $order = $this->sandboxOrder('paypal');
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'https://api-m.sandbox.paypal.com/v1/billing/subscriptions' => Http::response(['id' => 'I-test', 'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/webapps/billing/subscriptions?ba_token=test']]]),
            'https://api-m.sandbox.paypal.com/v1/billing/subscriptions/I-test' => Http::response(['id' => 'I-test', 'custom_id' => $order->id, 'plan_id' => 'paypalprice'.$order->price_id, 'status' => 'ACTIVE', 'start_time' => now()->subDay()->toIso8601ZuluString(), 'billing_info' => ['next_billing_time' => now()->addMonth()->toIso8601ZuluString()]]),
            'https://api-m.sandbox.paypal.com/v1/billing/subscriptions/I-test/transactions*' => Http::response(['transactions' => [['id' => 'SALE-test', 'status' => 'COMPLETED', 'time' => now()->toIso8601ZuluString()]]]),
            'https://api-m.sandbox.paypal.com/v1/payments/sale/SALE-test' => Http::response(['id' => 'SALE-test', 'billing_agreement_id' => 'I-test', 'state' => 'completed', 'amount' => ['total' => '19.00', 'currency' => 'EUR']]),
        ]);
        $billing = app(SandboxBilling::class);
        $billing->checkout($order->demo_session_id, $order->id);
        $billing->reconcile($order);
        Http::assertSent(fn ($request) => $request->url() === 'https://api-m.sandbox.paypal.com/v1/billing/subscriptions' && $request['custom_id'] === $order->id && $request->hasHeader('PayPal-Request-Id'));
        Http::assertSentCount(5);
        $this->assertDatabaseHas('payments', ['provider' => 'paypal', 'external_id' => 'SALE-test', 'amount_minor' => 1900]);
    }

    public function test_paypal_refunds_subscription_sale_using_original_payment_id(): void
    {
        $order = $this->sandboxOrder('paypal');
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
            'https://api-m.sandbox.paypal.com/v1/payments/sale/SALE-test/refund' => Http::response(['id' => 'REFUND-test', 'state' => 'pending']),
        ]);
        $result = app(PayPalSandboxProvider::class)->execute($order, 'refund', ['external_payment_id' => 'SALE-test', 'amount_minor' => 950], 'refund-key');
        $this->assertSame('REFUND-test', $result['id']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/refund') && $request['amount']['total'] === '9.50' && $request['amount']['currency'] === 'EUR' && $request->hasHeader('PayPal-Request-Id', 'refund-key'));
    }

    public function test_mollie_first_payment_has_customer_sequence_metadata_and_scoped_webhook(): void
    {
        $order = $this->sandboxOrder('mollie');
        Http::fake([
            'https://api.mollie.com/v2/customers' => Http::response(['id' => 'cst_test']),
            'https://api.mollie.com/v2/payments' => Http::response(['id' => 'tr_test', 'mode' => 'test', '_links' => ['checkout' => ['href' => 'https://www.mollie.com/checkout/test']]]),
        ]);
        $result = app(SandboxBilling::class)->checkout($order->demo_session_id, $order->id);
        $this->assertSame('tr_test', $result['id']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.mollie.com/v2/payments' && $request['sequenceType'] === 'first' && $request['customerId'] === 'cst_test' && $request['amount']['value'] === '19.00' && str_contains($request['webhookUrl'], 'token=') && $request->hasHeader('Idempotency-Key'));
    }

    public function test_mollie_subscription_requires_paid_first_payment_and_valid_mandate(): void
    {
        $order = $this->sandboxOrder('mollie');
        $catalog = app(SandboxCatalog::class);
        $catalog->map($order, 'customer', 'cst_test');
        $catalog->map($order, 'checkout', 'tr_test');
        Http::fake([
            'https://api.mollie.com/v2/payments/tr_test' => Http::response(['id' => 'tr_test', 'mode' => 'test', 'status' => 'paid', 'customerId' => 'cst_test', 'mandateId' => 'mdt_test', 'metadata' => ['order_id' => $order->id], 'amount' => ['value' => '19.00', 'currency' => 'EUR'], 'paidAt' => now()->toIso8601ZuluString()]),
            'https://api.mollie.com/v2/customers/cst_test/mandates/mdt_test' => Http::response(['id' => 'mdt_test', 'status' => 'valid']),
            'https://api.mollie.com/v2/customers/cst_test/subscriptions' => Http::response(['id' => 'sub_test', 'status' => 'active']),
        ]);
        app(SandboxOperations::class)->execute($order, 'activate_subscription', [], 'activate-'.$order->id);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/subscriptions') && $request['startDate'] === now()->addMonthNoOverflow()->toDateString() && $request['mandateId'] === 'mdt_test' && $request->hasHeader('Idempotency-Key'));
        $this->assertDatabaseHas('provider_objects', ['external_id' => 'sub_test', 'internal_id' => $order->id]);
    }

    public function test_invalid_mollie_mandate_does_not_create_subscription(): void
    {
        $order = $this->sandboxOrder('mollie');
        app(SandboxCatalog::class)->map($order, 'customer', 'cst_test');
        app(SandboxCatalog::class)->map($order, 'checkout', 'tr_test');
        Http::fake(['https://api.mollie.com/v2/payments/tr_test' => Http::response(['mode' => 'test', 'status' => 'failed', 'customerId' => 'cst_test'])]);
        try {
            app(MollieSandboxProvider::class)->execute($order, 'activate_subscription', [], 'subscription-key');
            $this->fail('Invalid first payment created a subscription.');
        } catch (SandboxUnavailable) {
            Http::assertSentCount(1);
        }
    }

    public function test_mollie_paid_checkout_activates_and_reconciles_without_duplicate_accounting(): void
    {
        $order = $this->sandboxOrder('mollie');
        $first = ['id' => 'tr_first', 'mode' => 'test', 'status' => 'paid', 'customerId' => 'cst_test', 'mandateId' => 'mdt_test', 'metadata' => ['order_id' => $order->id], 'amount' => ['value' => '19.00', 'currency' => 'EUR'], 'paidAt' => now()->toIso8601ZuluString(), 'createdAt' => now()->toIso8601ZuluString()];
        $subscription = ['id' => 'sub_test', 'status' => 'active', 'metadata' => ['order_id' => $order->id], 'amount' => $first['amount'], 'startDate' => now()->addMonthNoOverflow()->toDateString(), 'nextPaymentDate' => now()->addMonthNoOverflow()->toDateString()];
        Http::fake([
            'https://api.mollie.com/v2/customers' => Http::response(['id' => 'cst_test']),
            'https://api.mollie.com/v2/payments' => Http::response(['id' => 'tr_first', 'mode' => 'test', '_links' => ['checkout' => ['href' => 'https://www.mollie.com/checkout/test']]]),
            'https://api.mollie.com/v2/payments/tr_first' => Http::response($first),
            'https://api.mollie.com/v2/customers/cst_test/payments*' => Http::response(['_embedded' => ['payments' => [$first]]]),
            'https://api.mollie.com/v2/payments/tr_first/refunds*' => Http::response(['_embedded' => ['refunds' => []]]),
            'https://api.mollie.com/v2/payments/tr_first/chargebacks' => Http::response(['_embedded' => ['chargebacks' => []]]),
            'https://api.mollie.com/v2/customers/cst_test/mandates/mdt_test' => Http::response(['id' => 'mdt_test', 'status' => 'valid']),
            'https://api.mollie.com/v2/customers/cst_test/subscriptions' => Http::response($subscription),
            'https://api.mollie.com/v2/customers/cst_test/subscriptions/sub_test' => Http::response($subscription),
        ]);
        $billing = app(SandboxBilling::class);
        $billing->checkout($order->demo_session_id, $order->id);
        $billing->reconcile($order);
        $billing->reconcile($order);
        $this->assertDatabaseHas('subscriptions', ['order_id' => $order->id, 'status' => 'active']);
        $this->assertDatabaseHas('provider_objects', ['external_id' => 'tr_first', 'object_type' => 'checkout', 'internal_id' => $order->id]);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/subscriptions')));
    }

    public function test_mollie_checkout_payment_identity_cannot_be_adopted_by_another_order(): void
    {
        $order = $this->sandboxOrder('mollie');
        $other = $this->sandboxOrder('mollie');
        $catalog = app(SandboxCatalog::class);
        $catalog->map($order, 'checkout', 'tr_first');
        $this->expectException(SandboxUnavailable::class);
        $catalog->map($other, 'payment', 'tr_first');
    }

    #[DataProvider('mandateStates')]
    public function test_pending_mandate_does_not_start_the_subscription_creation_retry_window(string $status, bool $ready): void
    {
        $order = $this->sandboxOrder('mollie');
        app(SandboxCatalog::class)->map($order, 'customer', 'cst_test');
        app(SandboxCatalog::class)->map($order, 'checkout', 'tr_test');
        Http::fake([
            'https://api.mollie.com/v2/customers/cst_test/payments*' => Http::response(['_embedded' => ['payments' => [['id' => 'tr_test', 'mode' => 'test', 'status' => 'paid', 'paidAt' => now()->toIso8601ZuluString(), 'metadata' => ['order_id' => $order->id], 'customerId' => 'cst_test', 'mandateId' => 'mdt_test', 'amount' => ['currency' => 'EUR', 'value' => '19.00']]]]]),
            'https://api.mollie.com/v2/payments/tr_test/refunds*' => Http::response(['_embedded' => ['refunds' => []]]),
            'https://api.mollie.com/v2/payments/tr_test/chargebacks' => Http::response(['_embedded' => ['chargebacks' => []]]),
            'https://api.mollie.com/v2/customers/cst_test/mandates/mdt_test' => Http::response(['status' => $status]),
        ]);
        $snapshot = app(MollieSandboxProvider::class)->snapshot($order);
        $this->assertSame($ready, $snapshot['mandate_ready']);
        $this->assertDatabaseCount('sandbox_operations', 0);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public static function mandateStates(): array
    {
        return [['pending', false], ['valid', true]];
    }

    public function test_mollie_paid_period_starts_at_the_latest_confirmed_payment(): void
    {
        $this->travelTo(now()->startOfDay());
        $order = $this->sandboxOrder('mollie');
        $catalog = app(SandboxCatalog::class);
        $catalog->map($order, 'customer', 'cst_test');
        $catalog->map($order, 'checkout', 'tr_first');
        $catalog->map($order, 'subscription', 'sub_test');
        $firstPaidAt = now()->subMonthNoOverflow();
        $nextPaymentDate = now()->addMonthNoOverflow()->toDateString();
        $payment = ['mode' => 'test', 'status' => 'paid', 'metadata' => ['order_id' => $order->id], 'customerId' => 'cst_test', 'amount' => ['currency' => 'EUR', 'value' => '19.00']];
        Http::fake([
            'https://api.mollie.com/v2/customers/cst_test/payments*' => Http::response(['_embedded' => ['payments' => [
                $payment + ['id' => 'tr_renewal', 'subscriptionId' => 'sub_test', 'paidAt' => now()->toIso8601ZuluString(), 'createdAt' => now()->toIso8601ZuluString()],
                $payment + ['id' => 'tr_first', 'paidAt' => $firstPaidAt->toIso8601ZuluString(), 'createdAt' => $firstPaidAt->toIso8601ZuluString()],
            ]]]),
            'https://api.mollie.com/v2/payments/*/refunds*' => Http::response(['_embedded' => ['refunds' => []]]),
            'https://api.mollie.com/v2/payments/*/chargebacks' => Http::response(['_embedded' => ['chargebacks' => []]]),
            'https://api.mollie.com/v2/customers/cst_test/subscriptions/sub_test' => Http::response(['id' => 'sub_test', 'metadata' => ['order_id' => $order->id], 'status' => 'active', 'amount' => $payment['amount'], 'startDate' => now()->toDateString(), 'nextPaymentDate' => $nextPaymentDate]),
        ]);
        $snapshot = app(SandboxBilling::class)->reconcile($order);
        $this->assertSame(now()->timestamp, $snapshot['subscription']['period_start']);
        $this->assertSame(now()->addMonthNoOverflow()->startOfDay()->timestamp, $snapshot['subscription']['period_end']);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('ledger_transactions', 2);
    }

    public function test_mollie_initial_paid_period_ends_when_recurring_billing_starts(): void
    {
        $this->travelTo(now()->startOfDay());
        $order = $this->sandboxOrder('mollie');
        $catalog = app(SandboxCatalog::class);
        $catalog->map($order, 'customer', 'cst_test');
        $catalog->map($order, 'checkout', 'tr_first');
        $catalog->map($order, 'subscription', 'sub_test');
        $startDate = now()->addMonthNoOverflow()->toDateString();
        Http::fake([
            'https://api.mollie.com/v2/customers/cst_test/payments*' => Http::response(['_embedded' => ['payments' => [['id' => 'tr_first', 'mode' => 'test', 'status' => 'paid', 'paidAt' => now()->toIso8601ZuluString(), 'createdAt' => now()->toIso8601ZuluString(), 'customerId' => 'cst_test', 'metadata' => ['order_id' => $order->id], 'amount' => ['currency' => 'EUR', 'value' => '19.00']]]]]),
            'https://api.mollie.com/v2/payments/tr_first/refunds*' => Http::response(['_embedded' => ['refunds' => []]]),
            'https://api.mollie.com/v2/payments/tr_first/chargebacks' => Http::response(['_embedded' => ['chargebacks' => []]]),
            'https://api.mollie.com/v2/customers/cst_test/subscriptions/sub_test' => Http::response(['id' => 'sub_test', 'metadata' => ['order_id' => $order->id], 'status' => 'active', 'amount' => ['currency' => 'EUR', 'value' => '19.00'], 'startDate' => $startDate, 'nextPaymentDate' => $startDate]),
        ]);
        $snapshot = app(SandboxBilling::class)->reconcile($order);
        $this->assertSame(now()->timestamp, $snapshot['subscription']['period_start']);
        $this->assertSame(now()->addMonthNoOverflow()->startOfDay()->timestamp, $snapshot['subscription']['period_end']);
    }

    public function test_mollie_live_resource_is_rejected_even_with_a_test_key(): void
    {
        Http::fake(['*' => Http::response(['id' => 'tr_test', 'mode' => 'live'])]);
        $this->expectException(SandboxUnavailable::class);
        app(SandboxHttp::class)->request('mollie', 'GET', '/payments/tr_test');
    }

    #[DataProvider('unsafeUrls')]
    public function test_checkout_urls_cannot_redirect_to_an_attacker(string $provider, string $url): void
    {
        $this->expectException(SandboxUnavailable::class);
        SandboxData::checkoutUrl($provider, $url);
    }

    public static function unsafeUrls(): array
    {
        return [['stripe', 'https://checkout.stripe.com.attacker.example/pay'], ['paypal', 'https://www.paypal.com/checkout'], ['mollie', 'http://www.mollie.com/pay'], ['stripe', 'https://user@checkout.stripe.com/pay'], ['stripe', 'https://checkout.stripe.com:8443/pay'], ['mollie', 'https://127.0.0.1/pay']];
    }

    public function test_money_conversion_rejects_floats_rounding_and_non_two_decimal_values(): void
    {
        $this->assertSame(1999, SandboxData::minor('19.99'));
        $this->assertSame('0.09', SandboxData::decimal(9));
        $this->expectException(SandboxUnavailable::class);
        SandboxData::minor('19.999');
    }

    public function test_transport_rejects_redirects_and_never_follows_provider_supplied_hosts(): void
    {
        Http::fake(['https://api.stripe.com/v1/customers' => Http::response('', 302, ['Location' => 'https://attacker.example'])]);
        $this->expectException(SandboxUnavailable::class);
        app(SandboxHttp::class)->request('stripe', 'GET', '/customers');
    }

    public function test_catalog_is_provisioned_explicitly_and_repeated_command_is_idempotent(): void
    {
        DB::table('sandbox_catalog')->where('provider', 'mollie')->delete();
        $this->artisan('sandbox:catalog mollie')->assertSuccessful();
        $this->artisan('sandbox:catalog mollie')->assertSuccessful();
        $this->assertSame(3, DB::table('sandbox_catalog')->where('provider', 'mollie')->count());
        Http::assertNothingSent();
    }
}
