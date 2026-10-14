<?php

declare(strict_types=1);

namespace SilverShop\Withdrawal\Tests\Stub;

use SilverShop\Model\OrderItem;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Stands in for a sibling module (personalisation / product-status) that vetoes withdrawal of a line through
 * the `updateWithdrawable` hook — the integration contract this module ships.
 *
 * @extends Extension<OrderItem>
 */
class WithdrawableVetoStub extends Extension implements TestOnly
{
    public function updateWithdrawable(bool &$withdrawable, string &$reason): void
    {
        $withdrawable = false;
        $reason = 'veto-by-hook';
    }
}
