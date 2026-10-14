<?php

declare(strict_types=1);

namespace SilverShop\Withdrawal\Control;

use SilverShop\Cart\ShoppingCart;
use SilverShop\Extension\OrderManipulationExtension;
use SilverShop\Model\Order;
use SilverShop\Withdrawal\Email\WithdrawalConfirmationEmail;
use SilverShop\Withdrawal\Form\WithdrawalForm;
use SilverShop\Withdrawal\Model\WithdrawalRequest;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Security;

/**
 * The guest-capable withdrawal surface at `withdraw/{ID}?t={GuestToken}`.
 *
 *  - GET `withdraw/{ID}?t=…`               → the minimal withdrawal form (step 1);
 *  - POST `withdraw/WithdrawalForm`        → confirm (step 2): writes the WithdrawalRequest, sends the
 *                                            durable-medium acknowledgement, then PRG-redirects to…
 *  - GET `withdraw/{ID}?t=…&done={Token}`  → the immediate on-screen confirmation.
 *
 * Order access reuses order-confirmation's four-way resolver (constant-time token, else session / cart /
 * member), so a guest reaches their own order off-session without an account (CRD Art. 9 / ACM). The pages
 * render a self-contained module template, so the function works headless; the storefront theme overrides
 * `SilverShop/Withdrawal/WithdrawalPage.ss` for the branded version.
 */
class WithdrawalController extends Controller
{
    private static array $url_handlers = [
        'WithdrawalForm' => 'WithdrawalForm',
        '$ID!' => 'index',
        '' => 'index',
    ];

    private static array $allowed_actions = [
        'index',
        'WithdrawalForm',
    ];

    public function Link($action = null): string
    {
        // Root-relative, so the form action resolves correctly from withdraw/{ID} without relying on a
        // <base href> (a document-relative "withdraw/WithdrawalForm" would resolve to withdraw/withdraw/...).
        return Controller::join_links(Director::baseURL(), 'withdraw', $action);
    }

    /**
     * Step 1 (the form) — or the on-screen confirmation when a `done` token is present (step 3, post-redirect).
     */
    public function index(HTTPRequest $request): HTTPResponse
    {
        $order = $this->currentOrder();
        if (!$order instanceof Order) {
            return $this->httpError(404, 'Order could not be found');
        }

        // Post-redirect-get: show the immediate confirmation for a just-created request.
        $done = (string) $request->getVar('done');
        if ($done !== '') {
            $withdrawalRequest = WithdrawalRequest::get()
                ->filter(['OrderID' => (int) $order->ID, 'Token' => $done])
                ->first();
            if ($withdrawalRequest instanceof WithdrawalRequest && $withdrawalRequest->IsOperative()) {
                return $this->renderPage([
                    'Order' => $order,
                    'WithdrawalRequest' => $withdrawalRequest,
                    'IsComplete' => true,
                    'Title' => _t(self::class . '.TitleComplete', 'Withdrawal confirmed'),
                ]);
            }
        }

        return $this->renderPage([
            'Order' => $order,
            'WithdrawalForm' => $this->WithdrawalForm(),
            'WithdrawableItems' => $order->WithdrawableItems(),
            'IsComplete' => false,
            'Title' => _t(self::class . '.Title', 'Withdraw from your purchase'),
        ]);
    }

    public function WithdrawalForm(): WithdrawalForm
    {
        return WithdrawalForm::create($this, 'WithdrawalForm', $this->currentOrder());
    }

    /**
     * Step 2 — the operative confirmation. Re-resolves + re-authorises from the posted order id + token,
     * re-checks the window, and is idempotent (a second confirm returns the existing acknowledgement).
     *
     * @param array<string, mixed> $data
     */
    public function doWithdrawal(array $data, WithdrawalForm $form): HTTPResponse
    {
        $order = $this->resolveOrder((int) ($data['OrderID'] ?? 0), (string) ($data['Token'] ?? ''));
        if (!$order instanceof Order) {
            return $this->httpError(404, 'Order could not be found');
        }

        // Idempotency first — a double submit must never create a second request or re-open a closed window.
        $existing = $order->LatestWithdrawalRequest();
        if ($existing instanceof WithdrawalRequest) {
            return $this->redirect($this->completeLink($order, $existing));
        }

        if (!$order->CanWithdraw()) {
            $form->sessionMessage(
                _t(self::class . '.CannotWithdraw', 'This order can no longer be withdrawn online.'),
                'bad'
            );
            return $this->redirectBack();
        }

        $withdrawalRequest = WithdrawalRequest::create();
        $withdrawalRequest->OrderID = (int) $order->ID;
        $withdrawalRequest->Scope = WithdrawalRequest::SCOPE_WHOLE_ORDER;
        $withdrawalRequest->ContactEmail = (string) ($data['ContactEmail'] ?? '') ?: (string) $order->getLatestEmail();
        $withdrawalRequest->ReasonCode = (string) ($data['ReasonCode'] ?? '');
        $withdrawalRequest->Reason = (string) ($data['Reason'] ?? '');
        $withdrawalRequest->RequestedAt = DBDatetime::now()->Rfc2822();
        $withdrawalRequest->write();
        $withdrawalRequest->confirm();

        if (WithdrawalConfirmationEmail::send_for($withdrawalRequest)) {
            $withdrawalRequest->markAcknowledged();
        }

        // No-op unless the shop opted into cancel_order_on_withdrawal (v1 keeps refund + status manual).
        $order->cancelForWithdrawal();

        return $this->redirect($this->completeLink($order, $withdrawalRequest));
    }

    /** Resolve the order for whichever request method is in play (GET url id / POST hidden field). */
    protected function currentOrder(): ?Order
    {
        $request = $this->getRequest();
        $id = (int) $request->param('ID');
        $token = (string) $request->getVar('t');
        if ($id === 0) {
            $id = (int) $request->postVar('OrderID');
            $token = (string) $request->postVar('Token');
        }

        return $this->resolveOrder($id, $token);
    }

    /**
     * Resolve + authorise an order. Any one of: a constant-time token match (order-confirmation's GuestToken),
     * the just-placed session order ids, the current cart, or the owning member. Copied from
     * order-confirmation's resolver so the two surfaces authorise identically.
     */
    protected function resolveOrder(int $id, string $token): ?Order
    {
        if ($id === 0) {
            return null;
        }

        $order = Order::get()->byID($id);
        if (!$order instanceof Order) {
            return null;
        }

        // 1) Opaque URL token (order-confirmation's GuestToken) — off-session, cross-device.
        $orderToken = $order->hasField('GuestToken') ? (string) $order->getField('GuestToken') : '';
        if ($token !== '' && $orderToken !== '' && hash_equals($orderToken, $token)) {
            return $order;
        }

        // 2) Session-stored order ids (the just-placed guest in the same browser).
        $sessionIds = OrderManipulationExtension::get_session_order_ids();
        if (is_array($sessionIds) && in_array($order->ID, $sessionIds)) {
            return $order;
        }

        // 3) The current cart.
        $cart = ShoppingCart::curr();
        if ($cart instanceof Order && (int) $cart->ID === (int) $order->ID) {
            return $order;
        }

        // 4) The owning member.
        $member = Security::getCurrentUser();
        if ($member && (int) $member->ID !== 0 && (int) $order->getField('MemberID') === (int) $member->ID) {
            return $order;
        }

        return null;
    }

    protected function completeLink(Order $order, WithdrawalRequest $withdrawalRequest): string
    {
        $link = $order->WithdrawalLink();
        $separator = str_contains($link, '?') ? '&' : '?';

        return $link . $separator . 'done=' . $withdrawalRequest->getField('Token');
    }

    /**
     * Render the self-contained withdrawal page. Returning an explicit HTTPResponse (rather than a data array)
     * keeps it theme-independent, so it is a real 200 in the clean room and headless installs.
     *
     * @param array<string, mixed> $data
     */
    protected function renderPage(array $data): HTTPResponse
    {
        $html = (string) $this->customise($data)->renderWith(['SilverShop\\Withdrawal\\WithdrawalPage']);

        return HTTPResponse::create($html);
    }
}
