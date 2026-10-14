<?php

declare(strict_types=1);

namespace SilverShop\Withdrawal\Email;

use Psr\Log\LoggerInterface;
use SilverShop\Extension\ShopConfigExtension;
use SilverShop\Withdrawal\Model\WithdrawalRequest;
use SilverStripe\Control\Director;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;

/**
 * Builds + sends the immediate withdrawal-confirmation email (the CRD "acknowledgement of receipt on a
 * durable medium", obligation #4 on-screen's other half).
 *
 * Self-contained styling (its own `<style>` block, inline-safe) — it deliberately does NOT `<% include %>`
 * the core email shell, which needs silvershop/core 6.2.0, so the module stays installable on core 6.1.
 *
 * It stamps two headers so an optional `silvershop/notification-log` can record the send with zero coupling
 * (the log only READS headers):
 *   X-SilverShop-Order-ID: {OrderID}
 *   X-SilverShop-Notification-Key: withdrawal.confirmation
 */
class WithdrawalConfirmationEmail
{
    use Configurable;

    /** Stable notification key — same convention as the notifications registry / log. */
    public const NOTIFICATION_KEY = 'withdrawal.confirmation';

    private static string $html_template = 'SilverShop/Withdrawal/Email/WithdrawalConfirmationEmail';

    public static function create_for(WithdrawalRequest $request): Email
    {
        $order = $request->Order();
        $from = ShopConfigExtension::config()->get('email_from') ?: Email::config()->get('admin_email');
        $to = (string) $request->getField('ContactEmail');
        if ($to === '' && $order && $order->exists()) {
            $to = (string) $order->getLatestEmail();
        }

        $reference = $order && $order->exists() ? (string) $order->Reference : '';
        $subject = _t(
            self::class . '.Subject',
            'Your withdrawal is confirmed — order #{OrderNo}',
            '',
            ['OrderNo' => $reference]
        );

        $template = (string) self::config()->get('html_template');

        $email = Email::create()
            ->setHTMLTemplate($template)
            ->setPlainTemplate($template . 'Plain')
            ->setFrom($from)
            ->setTo($to)
            ->setSubject($subject);

        $email->setData([
            'Order' => $order,
            'WithdrawalRequest' => $request,
            'WithdrawableItems' => $order && $order->exists() && $order->hasMethod('WithdrawableItems')
                ? $order->WithdrawableItems()
                : null,
            'BaseURL' => Director::absoluteBaseURL(),
        ]);

        $headers = $email->getHeaders();
        $headers->addTextHeader('X-SilverShop-Order-ID', (string) (int) $request->getField('OrderID'));
        $headers->addTextHeader('X-SilverShop-Notification-Key', self::NOTIFICATION_KEY);

        return $email;
    }

    /**
     * Build + send, fully best-effort. BOTH construction and sending are wrapped: email is an acknowledgement
     * of an already-recorded, already-confirmed withdrawal, so no email problem (an unconfigured mail
     * transport, an empty from-address, a template error) may ever break the withdrawal flow or the on-screen
     * confirmation — it is logged and the request simply stays un-acknowledged.
     */
    public static function send_for(WithdrawalRequest $request): bool
    {
        try {
            $email = self::create_for($request);
            $email->send();
        } catch (\Throwable $throwable) {
            Injector::inst()->get(LoggerInterface::class)->error(
                'WithdrawalConfirmationEmail: failed to send for request ' . (int) $request->ID
                . ': ' . $throwable->getMessage()
            );
            return false;
        }

        return true;
    }
}
