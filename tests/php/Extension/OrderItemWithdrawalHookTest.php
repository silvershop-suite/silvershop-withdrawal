<?php

declare(strict_types=1);

namespace SilverShop\Withdrawal\Tests\Extension;

use SilverShop\Model\OrderItem;
use SilverShop\Withdrawal\Tests\Stub\WithdrawableVetoStub;
use SilverStripe\Dev\SapphireTest;

/**
 * The `updateWithdrawable` veto hook — the integration contract the personalisation / product-status modules
 * consume. Exercised with a stub extension standing in for them.
 */
class OrderItemWithdrawalHookTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $required_extensions = [
        OrderItem::class => [
            WithdrawableVetoStub::class,
        ],
    ];

    public function testUpdateWithdrawableHookVetoes(): void
    {
        $item = OrderItem::create();
        $item->write();

        [$withdrawable, $reason] = $item->deriveWithdrawable();

        $this->assertFalse($withdrawable, 'A sibling module can veto withdrawal via the hook');
        $this->assertSame('veto-by-hook', $reason);
    }

    public function testHookVetoIsFrozenAtPlacement(): void
    {
        $item = OrderItem::create();
        $item->write();

        $item->onPlacement();
        $item->write();

        $this->assertFalse($item->IsWithdrawable(), 'The hook veto is frozen onto the line at placement');
    }
}
