<?php

namespace Laravel\Cashier\Tests;

use DateTime;
use Stripe\Token;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Laravel\Cashier\Billable;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Laravel\Cashier\Http\Controllers\WebhookController;

class CashierTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();

        Eloquent::unguard();

        $db = new DB;
        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $db->bootEloquent();
        $db->setAsGlobal();

        $this->schema()->create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email');
            $table->string('name');
            $table->string('stripe_id')->nullable();
            $table->string('card_brand')->nullable();
            $table->string('card_last_four')->nullable();
            $table->timestamps();
        });

        $this->schema()->create('subscriptions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('name');
            $table->string('stripe_id');
            $table->string('stripe_plan');
            $table->integer('quantity');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
    }

    public function tearDown()
    {
        $this->schema()->drop('users');
        $this->schema()->drop('subscriptions');
    }

    public function test_subscriptions_can_be_created()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        // Create Subscription
        $user->newSubscription('main', $this->plan_1_id)->create($this->getTestToken());

        $this->assertEquals(1, count($user->subscriptions));
        $this->assertNotNull($user->subscription('main')->stripe_id);

        $this->assertTrue($user->subscribed('main'));
        $this->assertTrue($user->subscribedToPlan($this->plan_1_id, 'main'));
        $this->assertFalse($user->subscribedToPlan($this->plan_1_id, 'something'));
        $this->assertFalse($user->subscribedToPlan($this->plan_2_id, 'main'));
        $this->assertTrue($user->subscribed('main', $this->plan_1_id));
        $this->assertFalse($user->subscribed('main', $this->plan_2_id));
        $this->assertTrue($user->subscription('main')->active());
        $this->assertFalse($user->subscription('main')->cancelled());
        $this->assertFalse($user->subscription('main')->onGracePeriod());
        $this->assertTrue($user->subscription('main')->recurring());
        $this->assertFalse($user->subscription('main')->ended());

        // Cancel Subscription
        $subscription = $user->subscription('main');
        $subscription->cancel();

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->cancelled());
        $this->assertTrue($subscription->onGracePeriod());
        $this->assertFalse($subscription->recurring());
        $this->assertFalse($subscription->ended());

        // Modify Ends Date To Past
        $oldGracePeriod = $subscription->ends_at;
        $subscription->fill(['ends_at' => Carbon::now()->subDays(5)])->save();

        $this->assertFalse($subscription->active());
        $this->assertTrue($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());
        $this->assertFalse($subscription->recurring());
        $this->assertTrue($subscription->ended());

        $subscription->fill(['ends_at' => $oldGracePeriod])->save();

        // Resume Subscription
        $subscription->resume();

        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());
        $this->assertTrue($subscription->recurring());
        $this->assertFalse($subscription->ended());

        // Increment & Decrement
        $subscription->incrementQuantity();

        $this->assertEquals(2, $subscription->quantity);

        $subscription->decrementQuantity();

        $this->assertEquals(1, $subscription->quantity);

        // Swap Plan
        $subscription->swap($this->plan_2_id);

        $this->assertEquals($this->plan_2_id, $subscription->stripe_plan);

        // Invoice Tests
        $invoice = $user->invoices()[1];

        $this->assertEquals('$10.00', $invoice->total());
        $this->assertFalse($invoice->hasDiscount());
        $this->assertFalse($invoice->hasStartingBalance());
        $this->assertNull($invoice->coupon());
        $this->assertInstanceOf(Carbon::class, $invoice->date());
    }

    public function test_swapping_subscription_with_coupon()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        $user->newSubscription('main', $this->plan_1_id)->create($this->getTestToken());
        $subscription = $user->subscription('main');

        // Swap Plan with Coupon
        $subscription->swap($this->plan_2_id, [
            'coupon' => $this->coupon_1_id,
        ]);

        $this->assertEquals($this->coupon_1_id, $subscription->asStripeSubscription()->discount->coupon->id);
    }

    public function test_creating_subscription_with_coupons()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        // Create Subscription
        $user->newSubscription('main', $this->plan_1_id)
            ->withCoupon($this->coupon_1_id)
            ->create($this->getTestToken());

        $subscription = $user->subscription('main');

        $this->assertTrue($user->subscribed('main'));
        $this->assertTrue($user->subscribed('main', $this->plan_1_id));
        $this->assertFalse($user->subscribed('main', $this->plan_2_id));
        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());
        $this->assertTrue($subscription->recurring());
        $this->assertFalse($subscription->ended());

        // Invoice Tests
        $invoice = $user->invoices()[0];

        $this->assertTrue($invoice->hasDiscount());
        $this->assertEquals('$5.00', $invoice->total());
        $this->assertEquals('$5.00', $invoice->amountOff());
        $this->assertFalse($invoice->discountIsPercentage());
    }

    public function test_creating_subscription_with_an_anchored_billing_cycle()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        // Create Subscription
        $user->newSubscription('main', $this->plan_1_id)
            ->anchorBillingCycleOn(new DateTime('first day of next month'))
            ->create($this->getTestToken());

        $subscription = $user->subscription('main');

        $this->assertTrue($user->subscribed('main'));
        $this->assertTrue($user->subscribed('main', $this->plan_1_id));
        $this->assertFalse($user->subscribed('main', $this->plan_2_id));
        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());
        $this->assertTrue($subscription->recurring());
        $this->assertFalse($subscription->ended());

        // Invoice Tests
        $invoice = $user->invoices()[0];
        $invoicePeriod = $invoice->invoiceItems()[0]->period;

        $this->assertEquals(
            (new DateTime('now'))->format('Y-m-d'),
            date('Y-m-d', $invoicePeriod->start)
        );
        $this->assertEquals(
            (new DateTime('first day of next month'))->format('Y-m-d'),
            date('Y-m-d', $invoicePeriod->end)
        );
    }

    public function test_generic_trials()
    {
        $user = new User;

        $this->assertFalse($user->onGenericTrial());

        $user->trial_ends_at = Carbon::tomorrow();

        $this->assertTrue($user->onGenericTrial());

        $user->trial_ends_at = Carbon::today()->subDays(5);

        $this->assertFalse($user->onGenericTrial());
    }

    public function test_creating_subscription_with_trial()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        // Create Subscription
        $user->newSubscription('main', $this->plan_1_id)
            ->trialDays(7)
            ->create($this->getTestToken());

        $subscription = $user->subscription('main');

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onTrial());
        $this->assertFalse($subscription->recurring());
        $this->assertFalse($subscription->ended());
        $this->assertEquals(Carbon::today()->addDays(7)->day, $subscription->trial_ends_at->day);

        // Cancel Subscription
        $subscription->cancel();

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onGracePeriod());
        $this->assertFalse($subscription->recurring());
        $this->assertFalse($subscription->ended());

        // Resume Subscription
        $subscription->resume();

        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->onGracePeriod());
        $this->assertTrue($subscription->onTrial());
        $this->assertFalse($subscription->recurring());
        $this->assertFalse($subscription->ended());
        $this->assertEquals(Carbon::today()->addDays(7)->day, $subscription->trial_ends_at->day);
    }

    public function test_creating_subscription_with_explicit_trial()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        // Create Subscription
        $user->newSubscription('main', $this->plan_1_id)
            ->trialUntil(Carbon::tomorrow()->hour(3)->minute(15))
            ->create($this->getTestToken());

        $subscription = $user->subscription('main');

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onTrial());
        $this->assertFalse($subscription->recurring());
        $this->assertFalse($subscription->ended());
        $this->assertEquals(Carbon::tomorrow()->hour(3)->minute(15), $subscription->trial_ends_at);

        // Cancel Subscription
        $subscription->cancel();

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onGracePeriod());
        $this->assertFalse($subscription->recurring());
        $this->assertFalse($subscription->ended());

        // Resume Subscription
        $subscription->resume();

        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->onGracePeriod());
        $this->assertTrue($subscription->onTrial());
        $this->assertFalse($subscription->recurring());
        $this->assertFalse($subscription->ended());
        $this->assertEquals(Carbon::tomorrow()->hour(3)->minute(15), $subscription->trial_ends_at);
    }

    public function test_applying_coupons_to_existing_customers()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        // Create Subscription
        $user->newSubscription('main', $this->plan_1_id)
            ->create($this->getTestToken());

        $user->applyCoupon($this->coupon_1_id);

        $customer = $user->asStripeCustomer();

        $this->assertEquals($this->coupon_1_id, $customer->discount->coupon->id);
    }

    public function test_marking_as_cancelled_from_webhook()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        $user->newSubscription('main', $this->plan_1_id)
            ->create($this->getTestToken());

        $subscription = $user->subscription('main');

        $request = Request::create('/', 'POST', [], [], [], [], json_encode([
            'id' => 'foo',
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => $subscription->stripe_id,
                    'customer' => $user->stripe_id,
                ],
            ],
        ]));

        $controller = new CashierTestControllerStub;
        $response = $controller->handleWebhook($request);
        $this->assertEquals(200, $response->getStatusCode());

        $user = $user->fresh();
        $subscription = $user->subscription('main');

        $this->assertTrue($subscription->cancelled());
    }

    public function test_creating_one_off_invoices()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        // Create Invoice
        $user->createAsStripeCustomer();
        $user->updateCard($this->getTestToken());
        $user->invoiceFor('Laravel Cashier', 1000);

        // Invoice Tests
        $invoice = $user->invoices()[0];
        $this->assertEquals('$10.00', $invoice->total());
        $this->assertEquals('Laravel Cashier', $invoice->invoiceItems()[0]->asStripeInvoiceItem()->description);
    }

    public function test_refunds()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        // Create Invoice
        $user->createAsStripeCustomer();
        $user->updateCard($this->getTestToken());
        $invoice = $user->invoiceFor('Laravel Cashier', 1000);

        // Create the refund
        $refund = $user->refund($invoice->charge);

        // Refund Tests
        $this->assertEquals(1000, $refund->amount);
    }

    public function test_subscription_state_scopes()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);
        $subscription = $user->subscriptions()->create([
            'name' => 'yearly',
            'stripe_id' => 'xxxx',
            'stripe_plan' => 'stripe-yearly',
            'quantity' => 1,
            'trial_ends_at' => null,
            'ends_at' => null,
        ]);

        // subscription is active
        $this->assertTrue($user->subscriptions()->active()->exists());
        $this->assertFalse($user->subscriptions()->onTrial()->exists());
        $this->assertTrue($user->subscriptions()->notOnTrial()->exists());
        $this->assertTrue($user->subscriptions()->recurring()->exists());
        $this->assertFalse($user->subscriptions()->cancelled()->exists());
        $this->assertTrue($user->subscriptions()->notCancelled()->exists());
        $this->assertFalse($user->subscriptions()->onGracePeriod()->exists());
        $this->assertTrue($user->subscriptions()->notOnGracePeriod()->exists());
        $this->assertFalse($user->subscriptions()->ended()->exists());

        // put on trial
        $subscription->update(['trial_ends_at' => Carbon::now()->addDay()]);

        $this->assertTrue($user->subscriptions()->active()->exists());
        $this->assertTrue($user->subscriptions()->onTrial()->exists());
        $this->assertFalse($user->subscriptions()->notOnTrial()->exists());
        $this->assertFalse($user->subscriptions()->recurring()->exists());
        $this->assertFalse($user->subscriptions()->cancelled()->exists());
        $this->assertTrue($user->subscriptions()->notCancelled()->exists());
        $this->assertFalse($user->subscriptions()->onGracePeriod()->exists());
        $this->assertTrue($user->subscriptions()->notOnGracePeriod()->exists());
        $this->assertFalse($user->subscriptions()->ended()->exists());

        // put on grace period
        $subscription->update(['ends_at' => Carbon::now()->addDay()]);

        $this->assertTrue($user->subscriptions()->active()->exists());
        $this->assertTrue($user->subscriptions()->onTrial()->exists());
        $this->assertFalse($user->subscriptions()->notOnTrial()->exists());
        $this->assertFalse($user->subscriptions()->recurring()->exists());
        $this->assertTrue($user->subscriptions()->cancelled()->exists());
        $this->assertFalse($user->subscriptions()->notCancelled()->exists());
        $this->assertTrue($user->subscriptions()->onGracePeriod()->exists());
        $this->assertFalse($user->subscriptions()->notOnGracePeriod()->exists());
        $this->assertFalse($user->subscriptions()->ended()->exists());

        // end subscription
        $subscription->update(['ends_at' => Carbon::now()->subDay()]);

        $this->assertFalse($user->subscriptions()->active()->exists());
        $this->assertTrue($user->subscriptions()->onTrial()->exists());
        $this->assertFalse($user->subscriptions()->notOnTrial()->exists());
        $this->assertFalse($user->subscriptions()->recurring()->exists());
        $this->assertTrue($user->subscriptions()->cancelled()->exists());
        $this->assertFalse($user->subscriptions()->notCancelled()->exists());
        $this->assertFalse($user->subscriptions()->onGracePeriod()->exists());
        $this->assertTrue($user->subscriptions()->notOnGracePeriod()->exists());
        $this->assertTrue($user->subscriptions()->ended()->exists());
    }

    protected function getTestToken()
    {
        return Token::create([
            'card' => [
                'number' => '4242424242424242',
                'exp_month' => 5,
                'exp_year' => 2020,
                'cvc' => '123',
            ],
        ], ['api_key' => getenv('STRIPE_SECRET')])->id;
    }

    protected function schema(): Builder
    {
        return $this->connection()->getSchemaBuilder();
    }

    protected function connection(): ConnectionInterface
    {
        return Eloquent::getConnectionResolver()->connection();
    }
}

class User extends Eloquent
{
    use Billable;
}

class CashierTestControllerStub extends WebhookController
{
    public function __construct()
    {
        // Prevent setting middleware...
    }
}
