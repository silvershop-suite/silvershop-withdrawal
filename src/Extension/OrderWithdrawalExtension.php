<?php

declare(strict_types=1);

namespace SilverShop\Withdrawal\Extension;

use SilverShop\Model\Order;
use SilverShop\Withdrawal\Model\WithdrawalRequest;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordViewer;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Model\List\ArrayList;

/**
 * The 14-day right-of-withdrawal behaviour on an {@link Order}:
 *
 *  - the single new signal {@link $db DeliveredAt} (SilverShop records `Dispatched` = *sent*, never
 *    *received*, so goods have no deadline anchor without it);
 *  - the cooling-off window maths ({@link WithdrawalDeadline()} / {@link IsWithinWithdrawalPeriod()}),
 *    computed conservatively — the button stays OPEN while delivery is unknown, because closing it early
 *    is the compliance risk (a fine + a ~12-month auto-extension of the period);
 *  - the per-order withdrawable / exemption view ({@link WithdrawableItems()},
 *    {@link IsFullyWithdrawalExempt()}, {@link CanWithdraw()});
 *  - the guest-capable {@link WithdrawalLink()} that reuses order-confirmation's opaque `GuestToken`.
 *
 * > DEFENSIBLE-DESIGN, NOT LEGAL ADVICE. Every statutory specific (the 14 days, the delivery anchor, the
 * > exemptions) is configurable and must be verified with legal/DPO before launch.
 *
 * @property ?string $DeliveredAt
 * @extends Extension<Order>
 */
class OrderWithdrawalExtension extends Extension
{
    use Configurable;

    /** Goods: the deadline is anchored on physical possession (DeliveredAt) — CRD Art. 9(2)(b). (verify) */
    public const BASIS_GOODS = 'goods';

    /** Services / digital content: the deadline is anchored on conclusion (Placed) — CRD Art. 9(2)(a)/(c). (verify) */
    public const BASIS_PLACED = 'placed';

    private static array $db = [
        // The missing datum. Nullable: for most goods it is unknown until a human (or, in v2, a carrier
        // webhook / pickup "collected" event) records it. Services/digital never need it.
        'DeliveredAt' => 'Datetime',
    ];

    private static array $has_many = [
        'WithdrawalRequests' => WithdrawalRequest::class . '.Order',
    ];

    /** CRD Art. 9 cooling-off period in days. (verify with legal/DPO) */
    private static int $cooling_off_days = 14;

    /** Optional extra grace added to the goods deadline. */
    private static int $grace_days = 0;

    /** Assumed transit (days) used only to derive a soft/admin-reminder deadline when DeliveredAt is unknown. */
    private static int $assumed_transit_days = 14;

    /** Conservative default: keep the button open while delivery is unknown (do not hard-close on Dispatched). */
    private static bool $auto_close_without_delivery = false;

    /** Default deadline anchor for an order (goods vs services/digital). */
    private static string $default_basis = self::BASIS_GOODS;

    /** When true, a confirmed whole-order withdrawal flips the core Order status to MemberCancelled. */
    private static bool $cancel_order_on_withdrawal = false;

    /**
     * The deadline anchor for this order: {@link BASIS_GOODS} (DeliveredAt) or {@link BASIS_PLACED}
     * (Placed, for services / digital content). Fires an `updateWithdrawalBasis` hook so a digital/service
     * module (or the app) can override per order.
     */
    public function getWithdrawalDeadlineBasis(): string
    {
        $basis = (string) static::config()->get('default_basis');
        if ($basis !== self::BASIS_PLACED) {
            $basis = self::BASIS_GOODS;
        }
        $this->getOwner()->extend('updateWithdrawalBasis', $basis);

        return $basis === self::BASIS_PLACED ? self::BASIS_PLACED : self::BASIS_GOODS;
    }

    /**
     * The computed end of the cooling-off window, or null when there is no deadline yet (right exists, clock
     * not started — e.g. goods not yet delivered). Conservative by design; see the class docblock.
     */
    public function WithdrawalDeadline(): ?DBDatetime
    {
        $order = $this->getOwner();
        $placed = (string) $order->getField('Placed');
        if ($placed === '') {
            return null;
        }

        $days = (int) static::config()->get('cooling_off_days');

        if ($this->getWithdrawalDeadlineBasis() === self::BASIS_PLACED) {
            return $this->datetimePlusDays($placed, $days);
        }

        // Goods.
        $delivered = (string) $order->getField('DeliveredAt');
        if ($delivered !== '') {
            return $this->datetimePlusDays($delivered, $days + (int) static::config()->get('grace_days'));
        }

        // DeliveredAt unknown.
        $dispatched = (string) $order->getField('Dispatched');
        if ($dispatched !== '' && (bool) static::config()->get('auto_close_without_delivery')) {
            $assumed = (int) static::config()->get('assumed_transit_days');
            return $this->datetimePlusDays($dispatched, $assumed + $days);
        }

        // Nothing to anchor on yet → no deadline → keep the button open.
        return null;
    }

    /**
     * Is the order currently inside its cooling-off window? True from `Placed` until the deadline (inclusive),
     * and always true while there is no deadline yet (delivery unknown) — the conservative keep-open default.
     */
    public function IsWithinWithdrawalPeriod(): bool
    {
        $order = $this->getOwner();
        $placed = (string) $order->getField('Placed');
        if ($placed === '') {
            return false;
        }

        $now = DBDatetime::now()->getTimestamp();
        if ($now < strtotime($placed)) {
            return false;
        }

        $deadline = $this->WithdrawalDeadline();
        if (!$deadline instanceof DBDatetime) {
            return true;
        }

        return $now <= $deadline->getTimestamp();
    }

    /**
     * True when the window has demonstrably closed (there is a deadline and it has passed). Distinct from
     * "no deadline yet", which keeps the button open.
     */
    public function WithdrawalPeriodExpired(): bool
    {
        $order = $this->getOwner();
        if ((string) $order->getField('Placed') === '') {
            return false;
        }
        $deadline = $this->WithdrawalDeadline();

        return $deadline instanceof DBDatetime && DBDatetime::now()->getTimestamp() > $deadline->getTimestamp();
    }

    /**
     * The order items that may still be withdrawn (not frozen as exempt at placement). Returns an ArrayList so
     * the frozen per-line snapshot {@link OrderItemWithdrawalExtension::IsWithdrawable()} is the single source
     * of truth (rather than a re-derived DB filter).
     *
     * @return ArrayList<\SilverShop\Model\OrderItem>
     */
    public function WithdrawableItems(): ArrayList
    {
        $items = ArrayList::create();
        foreach ($this->getOwner()->Items() as $item) {
            if (!$item->hasMethod('IsWithdrawable') || $item->IsWithdrawable()) {
                $items->push($item);
            }
        }

        return $items;
    }

    public function HasWithdrawableItems(): bool
    {
        return $this->WithdrawableItems()->exists();
    }

    /**
     * Every line is frozen exempt (and there is at least one line): the order carries no withdrawal right →
     * no button, show the exemption notice instead.
     */
    public function IsFullyWithdrawalExempt(): bool
    {
        $all = $this->getOwner()->Items();

        return $all->exists() && !$this->HasWithdrawableItems();
    }

    /**
     * The button-visible predicate: the order is placed, inside the window, and has at least one withdrawable
     * line. (The storefront still shows an explanatory notice in the other states.)
     */
    public function CanWithdraw(): bool
    {
        return (string) $this->getOwner()->getField('Placed') !== ''
            && $this->IsWithinWithdrawalPeriod()
            && $this->HasWithdrawableItems();
    }

    /** The most recent operative (Confirmed or later) withdrawal request for this order, if any. */
    public function LatestWithdrawalRequest(): ?WithdrawalRequest
    {
        $request = WithdrawalRequest::get()
            ->filter([
                'OrderID' => (int) $this->getOwner()->ID,
                'Status' => [
                    WithdrawalRequest::STATUS_CONFIRMED,
                    WithdrawalRequest::STATUS_ACKNOWLEDGED,
                    WithdrawalRequest::STATUS_REFUNDED,
                ],
            ])
            ->sort('Created', 'DESC')
            ->first();

        return $request instanceof WithdrawalRequest ? $request : null;
    }

    public function HasConfirmedWithdrawal(): bool
    {
        return $this->LatestWithdrawalRequest() instanceof WithdrawalRequest;
    }

    /**
     * The guest-capable withdrawal URL `withdraw/{ID}?t={GuestToken}`. Reuses order-confirmation's opaque
     * `GuestToken`; when order-confirmation is absent the token is empty and only the member / same-session
     * paths resolve the order (the README explains the guest fallback).
     */
    public function WithdrawalLink(): string
    {
        $order = $this->getOwner();
        if (!$order->ID) {
            return '';
        }

        $link = 'withdraw/' . (int) $order->ID;
        $token = $order->hasField('GuestToken') ? (string) $order->getField('GuestToken') : '';
        if ($token !== '') {
            $link .= '?t=' . $token;
        }

        // Absolute, so it works in the order email (durable medium) and off-site, and resolves regardless
        // of the page it is embedded on.
        return Director::absoluteURL($link);
    }

    /**
     * Flip the core Order status to MemberCancelled for a confirmed whole-order withdrawal, when the shop has
     * opted in. MemberCancelled is the honest existing enum value; a dedicated `Withdrawn` status would need a
     * core enum change (see README). v1 leaves this OFF — refund and status are handled manually.
     */
    public function cancelForWithdrawal(): void
    {
        $order = $this->getOwner();
        if (!(bool) static::config()->get('cancel_order_on_withdrawal')) {
            return;
        }
        if ($order->getField('Status') === 'MemberCancelled') {
            return;
        }
        $order->setField('Status', 'MemberCancelled');
        $order->write();
    }

    public function updateCMSFields(FieldList $fields): void
    {
        $order = $this->getOwner();
        if (!$order->isInDB()) {
            return;
        }

        $fields->addFieldToTab(
            'Root.Withdrawal',
            DatetimeField::create('DeliveredAt', _t(self::class . '.DeliveredAt', 'Delivered / received at'))
                ->setDescription(_t(
                    self::class . '.DeliveredAtDesc',
                    'Date the customer took physical possession. Anchors the 14-day goods deadline; '
                    . 'leave blank to keep the window open (conservative). Verify your policy with legal/DPO.'
                ))
        );

        $deadline = $this->WithdrawalDeadline();
        $summary = sprintf(
            '<p><strong>%s</strong> %s<br><strong>%s</strong> %s</p>',
            _t(self::class . '.DeadlineLabel', 'Computed deadline:'),
            $deadline instanceof DBDatetime
                ? htmlspecialchars($deadline->Nice(), ENT_QUOTES)
                : _t(self::class . '.NoDeadline', 'none yet (window open)'),
            _t(self::class . '.WithinLabel', 'Within window now:'),
            $this->IsWithinWithdrawalPeriod()
                ? _t(self::class . '.Yes', 'yes')
                : _t(self::class . '.No', 'no')
        );
        $fields->addFieldToTab('Root.Withdrawal', LiteralField::create('WithdrawalSummary', $summary));

        $fields->addFieldToTab(
            'Root.Withdrawal',
            GridField::create(
                'WithdrawalRequests',
                _t(self::class . '.Requests', 'Withdrawal requests'),
                $order->WithdrawalRequests(),
                GridFieldConfig_RecordViewer::create()
            )
        );
    }

    /**
     * Add whole days to a datetime value and return it as a DBDatetime. Uses PHP date maths (DST-safe at the
     * day granularity the law uses) and {@link DBDatetime::now()} elsewhere so the test clock mock applies.
     */
    protected function datetimePlusDays(string $value, int $days): DBDatetime
    {
        $timestamp = strtotime('+' . $days . ' days', strtotime($value));

        /** @var DBDatetime $field */
        $field = DBField::create_field('Datetime', date('Y-m-d H:i:s', $timestamp));

        return $field;
    }
}
