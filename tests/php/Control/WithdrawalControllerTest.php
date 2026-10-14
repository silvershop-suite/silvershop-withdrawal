<?php

declare(strict_types=1);

namespace SilverShop\Withdrawal\Tests\Control;

use SilverShop\Model\Order;
use SilverShop\Model\OrderItem;
use SilverShop\Withdrawal\Model\WithdrawalRequest;
use SilverShop\Withdrawal\Tests\Stub\OrderGuestTokenStub;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * The guest-capable withdrawal surface: token authorisation (valid 200 / wrong 404) and the confirm flow
 * (WithdrawalRequest created + acknowledgement email sent).
 *
 * order-confirmation (which owns the GuestToken) is a composer `suggest` and is absent from the clean room,
 * so {@link OrderGuestTokenStub} supplies the field to exercise the real token branch.
 */
class WithdrawalControllerTest extends FunctionalTest
{
    protected $usesDatabase = true;

    protected static $required_extensions = [
        Order::class => [
            OrderGuestTokenStub::class,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Config::modify()->set(Email::class, 'admin_email', 'shop@example.com');
        DBDatetime::set_mock_now('2026-10-14 12:00:00');
    }

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    public function testValidTokenReturns200(): void
    {
        $order = $this->makeWithdrawableOrder();

        $response = $this->get('withdraw/' . $order->ID . '?t=' . $order->GuestToken);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testWrongTokenReturns404(): void
    {
        $order = $this->makeWithdrawableOrder();

        $response = $this->get('withdraw/' . $order->ID . '?t=not-the-token');

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testMissingOrderReturns404(): void
    {
        $response = $this->get('withdraw/999999?t=whatever');

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testConfirmCreatesRequestAndSendsEmail(): void
    {
        $order = $this->makeWithdrawableOrder();

        $this->get('withdraw/' . $order->ID . '?t=' . $order->GuestToken);
        $this->submitForm('WithdrawalForm', 'action_doWithdrawal', [
            'ContactEmail' => 'guest@example.com',
            'ReasonCode' => 'changed-mind',
        ]);

        $request = WithdrawalRequest::get()->filter('OrderID', $order->ID)->first();
        $this->assertInstanceOf(WithdrawalRequest::class, $request);
        $this->assertContains(
            $request->Status,
            [WithdrawalRequest::STATUS_CONFIRMED, WithdrawalRequest::STATUS_ACKNOWLEDGED]
        );
        $this->assertNotEmpty($request->ConfirmedAt);
        $this->assertTrue((bool) $request->WithinWindow);

        $this->assertEmailSent('guest@example.com');
    }

    public function testConfirmIsIdempotent(): void
    {
        $order = $this->makeWithdrawableOrder();

        $this->get('withdraw/' . $order->ID . '?t=' . $order->GuestToken);
        $this->submitForm('WithdrawalForm', 'action_doWithdrawal', ['ContactEmail' => 'guest@example.com']);

        $this->get('withdraw/' . $order->ID . '?t=' . $order->GuestToken);
        $this->submitForm('WithdrawalForm', 'action_doWithdrawal', ['ContactEmail' => 'guest@example.com']);

        $this->assertSame(1, WithdrawalRequest::get()->filter('OrderID', $order->ID)->count());
    }

    private function makeWithdrawableOrder(): Order
    {
        $order = Order::create();
        $order->Status = 'Paid';
        $order->Placed = DBDatetime::now()->Rfc2822();
        $order->Email = 'guest@example.com';
        $order->GuestToken = bin2hex(random_bytes(16));
        $order->write();

        $item = OrderItem::create();
        $item->OrderID = $order->ID;
        $item->Quantity = 1;
        $item->write();

        return $order;
    }
}
