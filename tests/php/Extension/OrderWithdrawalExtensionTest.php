<?php

declare(strict_types=1);

namespace SilverShop\Withdrawal\Tests\Extension;

use SilverShop\Model\Order;
use SilverShop\Model\Product\OrderItem;
use SilverShop\Withdrawal\Extension\OrderWithdrawalExtension;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * The cooling-off window maths and the per-order withdrawable / exemption view.
 */
class OrderWithdrawalExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        // Freeze "now" so relative Placed / DeliveredAt dates are deterministic.
        DBDatetime::set_mock_now('2026-10-14 12:00:00');
    }

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    public function testServicesAnchorOnPlaced(): void
    {
        Config::modify()->set(OrderWithdrawalExtension::class, 'default_basis', OrderWithdrawalExtension::BASIS_PLACED);

        $withinOrder = $this->makeOrder(['Placed' => '2026-10-04 12:00:00']); // +14 => 18th, now 14th
        $this->assertTrue($withinOrder->IsWithinWithdrawalPeriod(), 'Service placed 10 days ago is within 14 days');

        $lateOrder = $this->makeOrder(['Placed' => '2026-09-20 12:00:00']); // +14 => Oct 4, now 14th
        $this->assertFalse($lateOrder->IsWithinWithdrawalPeriod(), 'Service placed 24 days ago is outside 14 days');
        $this->assertTrue($lateOrder->WithdrawalPeriodExpired());
    }

    public function testGoodsAnchorOnDeliveredAt(): void
    {
        // Placed long ago, but the goods deadline runs from DeliveredAt, not Placed.
        $order = $this->makeOrder([
            'Placed' => '2026-09-01 12:00:00',
            'DeliveredAt' => '2026-10-10 12:00:00', // +14 => 24th, now 14th
        ]);
        $this->assertSame(OrderWithdrawalExtension::BASIS_GOODS, $order->getWithdrawalDeadlineBasis());
        $this->assertTrue($order->IsWithinWithdrawalPeriod(), 'Goods deadline runs from delivery, not placement');

        $delivered = $order->WithdrawalDeadline();
        $this->assertNotNull($delivered);
        $this->assertSame('2026-10-24', date('Y-m-d', $delivered->getTimestamp()));
    }

    public function testGoodsUnknownDeliveryKeepsWindowOpen(): void
    {
        // The key conservative default: goods placed long ago, delivery never recorded, no dispatch → the
        // window stays OPEN (closing it early is the compliance risk).
        $order = $this->makeOrder(['Placed' => '2026-01-01 12:00:00']);

        $this->assertNull($order->WithdrawalDeadline(), 'No anchor yet → no deadline');
        $this->assertTrue($order->IsWithinWithdrawalPeriod(), 'Unknown delivery keeps the button open');
        $this->assertFalse($order->WithdrawalPeriodExpired());
    }

    public function testGoodsAutoCloseWhenConfigured(): void
    {
        Config::modify()->set(OrderWithdrawalExtension::class, 'auto_close_without_delivery', true);
        Config::modify()->set(OrderWithdrawalExtension::class, 'assumed_transit_days', 14);

        // Dispatched 40 days ago, assumed transit 14 + cooling 14 = 28 → deadline ~28 days after dispatch,
        // which is in the past → closed.
        $order = $this->makeOrder([
            'Placed' => '2026-08-01 12:00:00',
            'Dispatched' => '2026-09-04 12:00:00',
        ]);

        $this->assertNotNull($order->WithdrawalDeadline(), 'Opt-in auto-close derives a soft deadline from Dispatched');
        $this->assertFalse($order->IsWithinWithdrawalPeriod());
    }

    public function testNotPlacedIsNotWithinPeriod(): void
    {
        $order = $this->makeOrder(['Status' => 'Cart']); // no Placed
        $this->assertFalse($order->IsWithinWithdrawalPeriod());
        $this->assertFalse($order->CanWithdraw());
    }

    public function testMixedOrderWithdrawableSubset(): void
    {
        $order = $this->makeOrder(['Placed' => '2026-10-10 12:00:00']);
        $this->addItem($order, false);
        $this->addItem($order, true); // exempt

        $this->assertSame(1, $order->WithdrawableItems()->count());
        $this->assertTrue($order->HasWithdrawableItems());
        $this->assertFalse($order->IsFullyWithdrawalExempt());
        $this->assertTrue($order->CanWithdraw(), 'A mixed order can still withdraw the withdrawable subset');
    }

    public function testFullyExemptOrder(): void
    {
        $order = $this->makeOrder(['Placed' => '2026-10-10 12:00:00']);
        $this->addItem($order, true);
        $this->addItem($order, true);

        $this->assertSame(0, $order->WithdrawableItems()->count());
        $this->assertFalse($order->HasWithdrawableItems());
        $this->assertTrue($order->IsFullyWithdrawalExempt());
        $this->assertFalse($order->CanWithdraw(), 'A fully exempt order shows no button');
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function makeOrder(array $fields): Order
    {
        $order = Order::create();
        $order->Status = 'Paid';
        foreach ($fields as $name => $value) {
            $order->setField($name, $value);
        }
        $order->write();

        return $order;
    }

    private function addItem(Order $order, bool $exempt): OrderItem
    {
        $item = OrderItem::create();
        $item->OrderID = $order->ID;
        $item->Quantity = 1;
        $item->setField('WithdrawalExempt', $exempt ? 1 : 0);
        $item->write();

        return $item;
    }
}
