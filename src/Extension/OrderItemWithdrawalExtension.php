<?php

declare(strict_types=1);

namespace SilverShop\Withdrawal\Extension;

use SilverShop\Model\OrderItem;
use SilverStripe\Core\Extension;

/**
 * The per-line withdrawal-exemption contract on an {@link OrderItem} (CRD Art. 16).
 *
 * Exemption is FROZEN onto the line at placement ({@link onPlacement()}) — from the buyable's manual
 * {@link BuyableWithdrawalExtension WithdrawalExempt} flag plus the `updateWithdrawable` veto hook — and
 * never re-derived afterwards from a product whose flag may since have changed. This mirrors how
 * personalisation freezes its data and product-status snapshots its signal, and is what makes the
 * exemption defensible.
 *
 * Sibling modules veto via the hook (listed as composer `suggest`, so this module never depends on them):
 *
 *   public function updateWithdrawable(bool &$withdrawable, string &$reason): void
 *   {
 *       if ($this->getOwner()->PersonalisationHash) {       // personalisation → Art. 16(c)
 *           $withdrawable = false;
 *           $reason = 'personalised';
 *       }
 *   }
 *
 * @property bool    $WithdrawalExempt
 * @property ?string $WithdrawalExemptReason
 * @extends Extension<OrderItem>
 */
class OrderItemWithdrawalExtension extends Extension
{
    private static array $db = [
        // Frozen at placement. Default 0 (withdrawable) — the conservative default for any line that was
        // never placed through this module (e.g. pre-existing orders).
        'WithdrawalExempt' => 'Boolean',
        'WithdrawalExemptReason' => 'Varchar',
    ];

    /**
     * Whether this line may still be withdrawn. Returns the FROZEN snapshot, so it is stable for the life of
     * the order regardless of later product edits.
     */
    public function IsWithdrawable(): bool
    {
        return !(bool) $this->getOwner()->getField('WithdrawalExempt');
    }

    /**
     * Derive the exemption live from the buyable flag + the `updateWithdrawable` hook. Used at placement to
     * compute the snapshot; also callable directly in tests to exercise the hook.
     *
     * @return array{0: bool, 1: string} [withdrawable, reason]
     */
    public function deriveWithdrawable(): array
    {
        $withdrawable = true;
        $reason = '';

        // Resolve the buyable via its relationship name rather than Buyable(), which blindly calls the
        // relationship method (absent on the base OrderItem, and null-safe only on the concrete subclasses).
        $owner = $this->getOwner();
        $relationship = (string) $owner->config()->get('buyable_relationship');
        $buyable = ($relationship !== '' && $owner->hasMethod($relationship)) ? $owner->{$relationship}() : null;

        if ($buyable && $buyable->hasField('WithdrawalExempt') && (bool) $buyable->getField('WithdrawalExempt')) {
            $withdrawable = false;
            $reason = (string) $buyable->getField('WithdrawalExemptReason');
            if ($reason === '') {
                $reason = _t(self::class . '.ExemptManual', 'Marked as withdrawal-exempt');
            }
        }

        // The veto hook the personalisation / product-status modules consume (passed by reference).
        $this->getOwner()->extend('updateWithdrawable', $withdrawable, $reason);

        return [(bool) $withdrawable, (string) $reason];
    }

    /**
     * Freeze the exemption onto the line as the order is placed. Core fires `extend('onPlacement')` from
     * {@link OrderItem::onPlacement()} (called by OrderProcessor during placement).
     */
    public function onPlacement(): void
    {
        [$withdrawable, $reason] = $this->deriveWithdrawable();
        $this->getOwner()->setField('WithdrawalExempt', $withdrawable ? 0 : 1);
        $this->getOwner()->setField('WithdrawalExemptReason', $withdrawable ? '' : $reason);
    }

    /** The reason this line is exempt (frozen), for the "greyed out and explained" storefront state. */
    public function WithdrawalExemptReasonText(): string
    {
        return (string) $this->getOwner()->getField('WithdrawalExemptReason');
    }
}
