<?php

declare(strict_types=1);

namespace SilverShop\Withdrawal\Tests\Stub;

use SilverShop\Model\Order;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Stands in for silvershop/order-confirmation's GuestToken (a composer `suggest`, so it is NOT installed in
 * the clean room). Lets the controller test exercise the real token-authorisation branch.
 *
 * @extends Extension<Order>
 */
class OrderGuestTokenStub extends Extension implements TestOnly
{
    private static array $db = [
        'GuestToken' => 'Varchar(32)',
    ];
}
