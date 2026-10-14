<?php

declare(strict_types=1);

namespace SilverShop\Withdrawal\Tests\Email;

use SilverShop\Model\Order;
use SilverShop\Withdrawal\Email\WithdrawalConfirmationEmail;
use SilverShop\Withdrawal\Model\WithdrawalRequest;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * The confirmation email stamps the notification-log headers (dependency-free durable-medium audit).
 */
class WithdrawalConfirmationEmailTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        Config::modify()->set(Email::class, 'admin_email', 'shop@example.com');
    }

    public function testStampsNotificationLogHeaders(): void
    {
        $order = Order::create();
        $order->Status = 'Paid';
        $order->Reference = 'TEST123';
        $order->Email = 'guest@example.com';
        $order->write();

        $request = WithdrawalRequest::create();
        $request->OrderID = $order->ID;
        $request->ContactEmail = 'guest@example.com';
        $request->write();

        $email = WithdrawalConfirmationEmail::create_for($request);
        $headers = $email->getHeaders();

        $this->assertTrue($headers->has('X-SilverShop-Notification-Key'));
        $this->assertSame(
            WithdrawalConfirmationEmail::NOTIFICATION_KEY,
            $headers->get('X-SilverShop-Notification-Key')->getBodyAsString()
        );
        $this->assertTrue($headers->has('X-SilverShop-Order-ID'));
        $this->assertSame((string) $order->ID, $headers->get('X-SilverShop-Order-ID')->getBodyAsString());
    }
}
