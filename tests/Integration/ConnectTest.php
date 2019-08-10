<?php

namespace Laravel\Cashier\Tests\Integration;

use Stripe\Plan;
use Stripe\Token;
use Carbon\Carbon;
use Stripe\Coupon;
use Stripe\Stripe;
use Stripe\Product;
use Stripe\ApiResource;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Stripe\Error\InvalidRequest;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Model as Eloquent;

class ConnectTest extends TestCase
{
    /**
     * @var string
     */
    protected static $stripePrefix = 'cashier-test-';

    /**
     * @var string
     */
    protected static $productId;

    /**
     * @var string
     */
    protected static $planId;

    /**
     * @var string
     */
    protected static $otherPlanId;

    /**
     * @var string
     */
    protected static $premiumPlanId;

    /**
     * @var string
     */
    protected static $couponId;

    public static function setUpBeforeClass()
    {
        Stripe::setApiVersion('2019-03-14');
        Stripe::setApiKey(getenv('STRIPE_SECRET'));

        static::setUpStripeTestData();
    }

    protected static function setUpStripeTestData()
    {
        static::$productId = static::$stripePrefix.'product-1'.Str::random(10);
        static::$planId = static::$stripePrefix.'monthly-10-'.Str::random(10);
        static::$otherPlanId = static::$stripePrefix.'monthly-10-'.Str::random(10);
        static::$premiumPlanId = static::$stripePrefix.'monthly-20-premium-'.Str::random(10);
        static::$couponId = static::$stripePrefix.'coupon-'.Str::random(10);

        Product::create([
            'id' => static::$productId,
            'name' => 'Laravel Cashier Test Product',
            'type' => 'service',
        ], ['stripe_account' => getenv('STRIPE_CONNECT_ACCOUNT_ID')]);

        Plan::create([
            'id' => static::$planId,
            'nickname' => 'Monthly $10',
            'currency' => 'USD',
            'interval' => 'month',
            'billing_scheme' => 'per_unit',
            'amount' => 1000,
            'product' => static::$productId,
        ], ['stripe_account' => getenv('STRIPE_CONNECT_ACCOUNT_ID')]);

        Plan::create([
            'id' => static::$otherPlanId,
            'nickname' => 'Monthly $10 Other',
            'currency' => 'USD',
            'interval' => 'month',
            'billing_scheme' => 'per_unit',
            'amount' => 1000,
            'product' => static::$productId,
        ], ['stripe_account' => getenv('STRIPE_CONNECT_ACCOUNT_ID')]);

        Plan::create([
            'id' => static::$premiumPlanId,
            'nickname' => 'Monthly $20 Premium',
            'currency' => 'USD',
            'interval' => 'month',
            'billing_scheme' => 'per_unit',
            'amount' => 2000,
            'product' => static::$productId,
        ], ['stripe_account' => getenv('STRIPE_CONNECT_ACCOUNT_ID')]);

        Coupon::create([
            'id' => static::$couponId,
            'duration' => 'repeating',
            'amount_off' => 500,
            'duration_in_months' => 3,
            'currency' => 'USD',
        ], ['stripe_account' => getenv('STRIPE_CONNECT_ACCOUNT_ID')]);
    }

    public function setUp()
    {
        Eloquent::unguard();

        $db = new DB();
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

    public static function tearDownAfterClass()
    {
        parent::tearDownAfterClass();

        static::deleteStripeResource(new Plan(static::$planId));
        static::deleteStripeResource(new Plan(static::$otherPlanId));
        static::deleteStripeResource(new Plan(static::$premiumPlanId));
        static::deleteStripeResource(new Product(static::$productId));
        static::deleteStripeResource(new Coupon(static::$couponId));
    }

    protected static function deleteStripeResource(ApiResource $resource)
    {
        try {
            $resource->delete(['stripe_account' => getenv('STRIPE_CONNECT_ACCOUNT_ID')]);
        } catch (InvalidRequest $e) {
        }
    }

    public function testSubscriptionsChargedApplicationFee()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);
        $application_fee_percent = rand(1, 100);
        // Create Subscription
        $user
            ->billingAccount(getenv('STRIPE_CONNECT_ACCOUNT_ID'))
            ->newSubscription('main', static::$planId)
            ->applicationFeePercent($application_fee_percent)
            ->create($this->getTestToken());

        $subscription = $user->subscription('main');
        $subscription->user->billingAccount(getenv('STRIPE_CONNECT_ACCOUNT_ID'));

        $this->assertEquals($application_fee_percent, $subscription->asStripeSubscription()->application_fee_percent);
    }

    public function testSubscriptionsCanBeCreated()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);
        // Create Subscription
        $user
            ->billingAccount(getenv('STRIPE_CONNECT_ACCOUNT_ID'))
            ->newSubscription('main', static::$planId)
            ->create($this->getTestToken());

        $this->assertEquals(1, count($user->subscriptions));
        $this->assertNotNull($user->subscription('main')->stripe_id);
        $this->assertTrue($user->subscribed('main'));
        $this->assertTrue($user->subscribedToPlan(static::$planId, 'main'));
        $this->assertFalse($user->subscribedToPlan(static::$planId, 'something'));
        $this->assertFalse($user->subscribedToPlan(static::$otherPlanId, 'main'));
        $this->assertTrue($user->subscribed('main', static::$planId));
        $this->assertFalse($user->subscribed('main', static::$otherPlanId));
        $this->assertTrue($user->subscription('main')->active());
        $this->assertFalse($user->subscription('main')->cancelled());
        $this->assertFalse($user->subscription('main')->onGracePeriod());
        $this->assertTrue($user->subscription('main')->recurring());
        $this->assertFalse($user->subscription('main')->ended());
        // Cancel Subscription
        $subscription = $user->subscription('main');
        $subscription->user->billingAccount(getenv('STRIPE_CONNECT_ACCOUNT_ID'));
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
        $subscription->swap(static::$otherPlanId);
        $this->assertEquals(static::$otherPlanId, $subscription->stripe_plan);
        // Invoice Tests
        $invoice = $user->billingAccount(getenv('STRIPE_CONNECT_ACCOUNT_ID'))->invoices()[1];
        $this->assertEquals('$10.00', $invoice->total());
        $this->assertFalse($invoice->hasDiscount());
        $this->assertFalse($invoice->hasStartingBalance());
        $this->assertNull($invoice->coupon());
        $this->assertInstanceOf(Carbon::class, $invoice->date());
    }

    public function testCreatingOneOffInvoices()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);
        // Create Invoice
        $user->billingAccount(getenv('STRIPE_CONNECT_ACCOUNT_ID'))
            ->createAsStripeCustomer();
        $user->updateCard($this->getTestToken());
        $user->invoiceFor('Laravel Cashier', 1000);
        // Invoice Tests
        $invoice = $user->invoices()[0];
        $this->assertEquals('$10.00', $invoice->total());
        $this->assertEquals('Laravel Cashier', $invoice->invoiceItems()[0]->asStripeInvoiceItem()->description);
    }

    public function testRefunds()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);
        // Create Invoice
        $user->billingAccount(getenv('STRIPE_CONNECT_ACCOUNT_ID'))
            ->createAsStripeCustomer();
        $user->updateCard($this->getTestToken());
        $invoice = $user->invoiceFor('Laravel Cashier', 1000);
        // Create the refund
        $refund = $user->refund($invoice->charge);
        // Refund Tests
        $this->assertEquals(1000, $refund->amount);
    }

    public function test_creating_subscription_with_coupons()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        // Create Subscription
        $user
            ->billingAccount(getenv('STRIPE_CONNECT_ACCOUNT_ID'))
            ->newSubscription('main', static::$planId)
            ->withCoupon(static::$couponId)
            ->create($this->getTestToken());

        $subscription = $user->subscription('main');
        $subscription->user->billingAccount(getenv('STRIPE_CONNECT_ACCOUNT_ID'));

        $this->assertTrue($user->subscribed('main'));
        $this->assertTrue($user->subscribed('main', static::$planId));
        $this->assertFalse($user->subscribed('main', static::$otherPlanId));
        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());
        $this->assertTrue($subscription->recurring());
        $this->assertFalse($subscription->ended());

        // Invoice Tests
        $invoice = $user->billingAccount(getenv('STRIPE_CONNECT_ACCOUNT_ID'))->invoices()[0];

        $this->assertTrue($invoice->hasDiscount());
        $this->assertEquals('$5.00', $invoice->total());
        $this->assertEquals('$5.00', $invoice->amountOff());
        $this->assertFalse($invoice->discountIsPercentage());
    }

    public function test_generic_trials()
    {
        $user = new User();

        $user->billingAccount(getenv('STRIPE_CONNECT_ACCOUNT_ID'));

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
        $user
            ->billingAccount(getenv('STRIPE_CONNECT_ACCOUNT_ID'))
            ->newSubscription('main', static::$planId)
            ->trialDays(7)
            ->create($this->getTestToken());

        $subscription = $user->subscription('main');
        $subscription->user->billingAccount(getenv('STRIPE_CONNECT_ACCOUNT_ID'));

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
        $user
            ->billingAccount(getenv('STRIPE_CONNECT_ACCOUNT_ID'))
            ->newSubscription('main', static::$planId)
            ->trialUntil(Carbon::tomorrow()->hour(3)->minute(15))
            ->create($this->getTestToken());

        $subscription = $user->subscription('main');
        $subscription->user->billingAccount(getenv('STRIPE_CONNECT_ACCOUNT_ID'));

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
        $user
            ->billingAccount(getenv('STRIPE_CONNECT_ACCOUNT_ID'))
            ->newSubscription('main', static::$planId)
            ->create($this->getTestToken());

        $user->applyCoupon(static::$couponId);

        $customer = $user->asStripeCustomer();

        $this->assertEquals(static::$couponId, $customer->discount->coupon->id);
    }

    public function test_marking_as_cancelled_from_webhook()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        $user
            ->billingAccount(getenv('STRIPE_CONNECT_ACCOUNT_ID'))
            ->newSubscription('main', static::$planId)
            ->create($this->getTestToken());

        $subscription = $user->subscription('main');
        $subscription->user->billingAccount(getenv('STRIPE_CONNECT_ACCOUNT_ID'));

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

        $controller = new CashierTestControllerStub();
        $response = $controller->handleWebhook($request);
        $this->assertEquals(200, $response->getStatusCode());

        $user = $user->fresh();
        $subscription = $user->subscription('main');

        $this->assertTrue($subscription->cancelled());
    }

    protected function getTestToken()
    {
        return Token::create([
            'card' => [
                'number' => '4242424242424242',
                'exp_month' => 5,
                'exp_year' => date('Y') + 1,
                'cvc' => '123',
            ],
        ])->id;
    }

    protected function getInvalidCardToken()
    {
        return Token::create([
            'card' => [
                'number' => '4000 0000 0000 0341',
                'exp_month' => 5,
                'exp_year' => date('Y') + 1,
                'cvc' => '123',
            ],
        ])->id;
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
