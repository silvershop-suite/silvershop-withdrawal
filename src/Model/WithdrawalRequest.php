<?php

declare(strict_types=1);

namespace SilverShop\Withdrawal\Model;

use SilverShop\Model\Order;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;

/**
 * The immutable audit record of a consumer's withdrawal declaration (one per order in v1; per-line is v2).
 *
 * The legally operative moment is the transition to {@link STATUS_CONFIRMED} ({@link $ConfirmedAt}). From
 * that point the record is append-only ({@link canEdit()} / {@link canDelete()} return false), mirroring the
 * suite's immutable-document style (ShopPostingEntry, SentNotification).
 *
 * The `Reason` / `ReasonCode` fields are deliberately OPTIONAL and MUST NEVER be made required (CRD Art. 9;
 * ACM "no undue barriers / only necessary data").
 *
 * @property string  $Status
 * @property string  $Token
 * @property string  $Scope
 * @property ?string $Reason
 * @property ?string $ReasonCode
 * @property string  $ContactEmail
 * @property ?string $RequestedAt
 * @property ?string $ConfirmedAt
 * @property ?string $AcknowledgedAt
 * @property bool    $WithinWindow
 * @property ?string $DeadlineAt
 * @property int     $CreditMemoID
 * @property int     $OrderID
 * @method Order Order()
 */
class WithdrawalRequest extends DataObject
{
    public const STATUS_DRAFT = 'Draft';

    public const STATUS_CONFIRMED = 'Confirmed';

    public const STATUS_ACKNOWLEDGED = 'Acknowledged';

    public const STATUS_REFUNDED = 'Refunded';

    public const STATUS_REJECTED = 'Rejected';

    public const SCOPE_WHOLE_ORDER = 'WholeOrder';

    public const SCOPE_PARTIAL_LINES = 'PartialLines';

    private static string $table_name = 'SilverShop_WithdrawalRequest';

    private static string $singular_name = 'Withdrawal request';

    private static string $plural_name = 'Withdrawal requests';

    private static string $default_sort = '"Created" DESC';

    private static array $db = [
        'Status' => "Enum('Draft,Confirmed,Acknowledged,Refunded,Rejected','Draft')",
        'Token' => 'Varchar(32)',
        'Scope' => "Enum('WholeOrder,PartialLines','WholeOrder')",
        'Reason' => 'Text',
        'ReasonCode' => 'Varchar',
        'ContactEmail' => 'Varchar',
        'RequestedAt' => 'Datetime',
        'ConfirmedAt' => 'Datetime',
        'AcknowledgedAt' => 'Datetime',
        'WithinWindow' => 'Boolean',
        'DeadlineAt' => 'Datetime',
        // Plain id (not a typed has_one) so the module installs without silvershop/invoicing. It links the
        // ShopCreditMemo once a refund is issued; v2 formalises the relation + triggers the refund.
        'CreditMemoID' => 'Int',
    ];

    private static array $has_one = [
        'Order' => Order::class,
    ];

    private static array $indexes = [
        'Token' => true,
        'OrderID' => true,
    ];

    private static array $summary_fields = [
        'Created',
        'Order.Reference',
        'Status',
        'ContactEmail',
        'ConfirmedAt',
    ];

    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['Order.Reference'] = _t(self::class . '.OrderReference', 'Order');

        return $labels;
    }

    public function getTitle(): string
    {
        $order = $this->Order();
        $reference = $order && $order->exists() ? $order->Reference : (string) $this->OrderID;

        return sprintf('#%s — %s', $reference, $this->Status);
    }

    /** Opaque per-request handle for the confirmation / acknowledgement link (distinct from the GuestToken). */
    public function generateToken(): string
    {
        $token = bin2hex(random_bytes(16));
        $this->setField('Token', $token);

        return $token;
    }

    public function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        if (!$this->getField('Token')) {
            $this->generateToken();
        }
    }

    public function IsOperative(): bool
    {
        return in_array(
            $this->getField('Status'),
            [self::STATUS_CONFIRMED, self::STATUS_ACKNOWLEDGED, self::STATUS_REFUNDED],
            true
        );
    }

    /** Immutable once the consumer has confirmed (the operative withdrawal declaration). */
    public function canEdit($member = null): bool
    {
        if ($this->IsOperative()) {
            return false;
        }

        return parent::canEdit($member);
    }

    public function canDelete($member = null): bool
    {
        if ($this->IsOperative()) {
            return false;
        }

        return parent::canDelete($member);
    }

    /** The storefront creates these for guests, so creation is open; access is gated by the order token. */
    public function canCreate($member = null, $context = []): bool
    {
        return true;
    }

    public function canView($member = null): bool
    {
        return true;
    }

    /**
     * Confirm the withdrawal: stamp the operative timestamp + the defensibility snapshots and persist. The
     * caller has already resolved + authorised the order and checked the window.
     */
    public function confirm(): void
    {
        $order = $this->Order();
        $now = DBDatetime::now();

        $this->setField('Status', self::STATUS_CONFIRMED);
        $this->setField('ConfirmedAt', $now->Rfc2822());
        if (!$this->getField('RequestedAt')) {
            $this->setField('RequestedAt', $now->Rfc2822());
        }
        if ($order && $order->exists()) {
            $this->setField('WithinWindow', $order->IsWithinWithdrawalPeriod() ? 1 : 0);
            $deadline = $order->WithdrawalDeadline();
            $this->setField('DeadlineAt', $deadline instanceof DBDatetime ? $deadline->Rfc2822() : null);
        }
        $this->write();
    }

    /** Mark the durable-medium acknowledgement (confirmation email) as dispatched. */
    public function markAcknowledged(): void
    {
        $this->setField('AcknowledgedAt', DBDatetime::now()->Rfc2822());
        if ($this->getField('Status') === self::STATUS_CONFIRMED) {
            $this->setField('Status', self::STATUS_ACKNOWLEDGED);
        }
        $this->write();
    }
}
