<?php

declare(strict_types=1);

namespace SilverShop\Withdrawal\Tests\Extension;

use SilverShop\Model\OrderItem;
use SilverShop\Page\Product;
use SilverStripe\Dev\SapphireTest;

/**
 * The frozen per-line exemption snapshot + the manual buyable flag.
 */
class OrderItemWithdrawalExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testDefaultIsWithdrawable(): void
    {
        $item = OrderItem::create();
        $item->write();

        $this->assertTrue($item->IsWithdrawable(), 'A plain line defaults to withdrawable (conservative)');
    }

    public function testManualExemptFlagFreezesAtPlacement(): void
    {
        $product = Product::create();
        $product->Title = 'Engraved mug';
        $product->setField('WithdrawalExempt', 1);
        $product->setField('WithdrawalExemptReason', 'Made to order');
        $product->write();
        $product->publishSingle();

        $item = $product->createItem(1);
        $item->write();

        // Not frozen until placement — the line field is still at its default.
        $this->assertTrue($item->IsWithdrawable());

        $item->onPlacement();
        $item->write();

        $this->assertFalse($item->IsWithdrawable(), 'The buyable exempt flag is frozen onto the line at placement');
        $this->assertSame('Made to order', $item->WithdrawalExemptReasonText());
    }

    public function testDeriveReturnsReasonFromBuyable(): void
    {
        $product = Product::create();
        $product->Title = 'Perishable';
        $product->setField('WithdrawalExempt', 1);
        $product->write();
        $product->publishSingle();

        $item = $product->createItem(1);
        [$withdrawable, $reason] = $item->deriveWithdrawable();

        $this->assertFalse($withdrawable);
        $this->assertNotSame('', $reason, 'An exempt buyable with no reason gets the generic fallback');
    }
}
