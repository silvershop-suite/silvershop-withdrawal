<?php

declare(strict_types=1);

namespace SilverShop\Withdrawal\Extension;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;

/**
 * A manual, module-independent "withdrawal exempt" flag on a buyable ({@link \SilverShop\Page\Product} and
 * {@link \SilverShop\Model\Variation\Variation}).
 *
 * A shop can mark made-to-order / personalised / hygiene-sealed products exempt (CRD Art. 16) WITHOUT the
 * planned personalisation / product-status modules — those automate the same outcome through the
 * {@link OrderItemWithdrawalExtension} `updateWithdrawable` hook when installed. The flag is read at
 * placement and frozen onto the order line, so later product edits never change a placed order's rights.
 *
 * @property bool    $WithdrawalExempt
 * @property ?string $WithdrawalExemptReason
 * @extends Extension<DataObject>
 */
class BuyableWithdrawalExtension extends Extension
{
    private static array $db = [
        'WithdrawalExempt' => 'Boolean',
        'WithdrawalExemptReason' => 'Varchar',
    ];

    public function updateCMSFields(FieldList $fields): void
    {
        $exempt = CheckboxField::create(
            'WithdrawalExempt',
            _t(self::class . '.WithdrawalExempt', 'Exempt from the right of withdrawal')
        )->setDescription(_t(
            self::class . '.WithdrawalExemptDesc',
            'Tick for made-to-order / personalised / sealed-hygiene goods (CRD Art. 16). Frozen onto the '
            . 'order line at purchase. Verify which exemptions apply with legal/DPO.'
        ));

        $reason = TextField::create(
            'WithdrawalExemptReason',
            _t(self::class . '.WithdrawalExemptReason', 'Exemption reason (shown to the customer)')
        );

        // Products are Pages (tabbed CMS); variations use a flat field list.
        if ($fields->fieldByName('Root.Pricing')) {
            $fields->addFieldsToTab('Root.Pricing', [$exempt, $reason]);
        } else {
            $fields->push($exempt);
            $fields->push($reason);
        }
    }
}
