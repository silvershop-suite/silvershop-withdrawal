<?php

declare(strict_types=1);

namespace SilverShop\Withdrawal\Form;

use SilverShop\Model\Order;
use SilverStripe\Control\RequestHandler;
use SilverStripe\Forms\EmailField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\Validation\RequiredFieldsValidator;

/**
 * The minimal, no-mandatory-account withdrawal form (CRD Art. 9; ACM "only necessary data, no undue
 * barriers").
 *
 * The ONLY required field is the contact email (where the durable-medium acknowledgement goes, prefilled
 * from the order). The reason is a plain optional dropdown + free text and MUST NEVER be required. The order
 * identity + token travel as hidden fields; the controller re-resolves and re-authorises them on submit.
 */
class WithdrawalForm extends Form
{
    public function __construct(RequestHandler $controller, string $name = 'WithdrawalForm', ?Order $order = null)
    {
        $fields = FieldList::create(
            HiddenField::create('OrderID'),
            HiddenField::create('Token'),
            EmailField::create('ContactEmail', _t(self::class . '.ContactEmail', 'Your email address'))
                ->setDescription(_t(
                    self::class . '.ContactEmailDesc',
                    'We send your withdrawal confirmation here.'
                )),
            DropdownField::create(
                'ReasonCode',
                _t(self::class . '.ReasonCode', 'Reason (optional)'),
                $this->reasonOptions()
            )->setEmptyString(_t(self::class . '.ReasonEmpty', '— Prefer not to say —')),
            TextareaField::create('Reason', _t(self::class . '.Reason', 'Anything to add? (optional)'))
                ->setRows(3)
        );

        $actions = FieldList::create(
            FormAction::create('doWithdrawal', _t(self::class . '.Confirm', 'Confirm withdrawal'))
        );

        // ONLY the email is required — never the reason.
        $validator = RequiredFieldsValidator::create(['ContactEmail']);

        parent::__construct($controller, $name, $fields, $actions, $validator);

        // Pin a stable HTML id (the default would be WithdrawalForm_WithdrawalForm).
        $this->setHTMLID('WithdrawalForm');

        if ($order instanceof Order) {
            $this->loadDataFrom([
                'OrderID' => (int) $order->ID,
                'Token' => $order->hasField('GuestToken') ? (string) $order->getField('GuestToken') : '',
                'ContactEmail' => $order->getLatestEmail(),
            ]);
        }
    }

    /**
     * Optional reason codes. Purely informational — the field is never required and defaults to blank.
     *
     * @return array<string, string>
     */
    protected function reasonOptions(): array
    {
        return [
            'changed-mind' => _t(self::class . '.ReasonChangedMind', 'Changed my mind'),
            'not-as-described' => _t(self::class . '.ReasonNotAsDescribed', 'Not as described'),
            'ordered-by-mistake' => _t(self::class . '.ReasonMistake', 'Ordered by mistake'),
            'found-better' => _t(self::class . '.ReasonFoundBetter', 'Found a better option'),
            'other' => _t(self::class . '.ReasonOther', 'Other'),
        ];
    }
}
