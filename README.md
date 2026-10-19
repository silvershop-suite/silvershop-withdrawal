# silvershop-suite/withdrawal

The EU/NL **right-of-withdrawal** function for [SilverShop](https://github.com/silvershop/silvershop-core) —
a persistent, guest-capable **withdrawal button** ("mijn aankoop ongedaan maken" / *Widerrufsbutton*),
available for the whole **14-day cooling-off window**, leading to a **minimal no-account form**, an
**immediate on-screen + emailed confirmation**, and a per-line **exemption contract**. No core fork:
everything hangs off existing extension points.

> ## ⚠️ Defensible design, not legal advice
> This module ships the **machinery** for the withdrawal right. It does **not** constitute legal advice, and
> the statutory specifics it encodes — the 14 days, the delivery anchor, the Art. 16 exemptions, and all
> on-screen/email wording — are **defaults you must verify with your own legal counsel / DPO** against the
> consolidated EU text (the Consumer Rights Directive 2011/83/EU as amended by Directive (EU) 2023/2673,
> Art. 11a), the Dutch transposition (Burgerlijk Wetboek) and ACM guidance, **before launch**. Every such
> value is configurable and flagged "verify with legal/DPO" in code. Treat the obligation (EU application
> date **19 June 2026**) as live, but let legal sign off the copy, the deadlines and the exemption matrix.

## Why

A clear, easy-to-find withdrawal function — available the whole 14-day window, **not login-walled**, with an
**immediate confirmation on a durable medium** — is a standing obligation for distance selling in the EU. The
off-the-shelf platforms are mostly account-centric; a compliant **guest** withdrawal is the gap this fills
cheaply, by reusing the opaque order token `silvershop-suite/order-confirmation` already provides.

## What it does (v1)

1. **A persistent withdrawal button** on the confirmation page, the account order-detail view and the order
   email, for the whole cooling-off window (states: available / per-item exempt / after-deadline /
   already-withdrawn).
2. **Guest access with no mandatory account.** `withdraw/{ID}?t={token}` reuses order-confirmation's opaque
   `GuestToken` and its four-way resolver (constant-time token, else session / cart / member).
3. **A minimal two-step form → confirm.** The only required field is the contact email (where the
   acknowledgement goes); the reason is optional and **never required** (ACM "only necessary data").
4. **Immediate confirmation on-screen *and* by email** (`WithdrawalConfirmationEmail`, a self-contained flat
   email), the durable-medium acknowledgement. The email is stamped with notification-log headers.
5. **An append-only `WithdrawalRequest` audit record** — the `Confirmed` transition (and its `ConfirmedAt`) is
   the legally operative moment; the record is immutable thereafter, with `WithinWindow` / `DeadlineAt`
   snapshots for defensibility.
6. **A per-line exemption contract** (CRD Art. 16) — a manual `WithdrawalExempt` flag on products/variations,
   frozen onto the order line at placement, plus an `updateWithdrawable` hook for the personalisation /
   product-status modules.

**Manual in v1 (by design):** the **refund** and any **order-status** change are handled by a human. v2
automates the refund through invoicing/ledger and adds partial per-line withdrawal + the printable model form.

## The 14-day clock — and the one new field

SilverShop records `Dispatched` (goods *sent*) but **never a delivery/receipt date**, so goods have no
deadline anchor. This module adds the single nullable field **`Order.DeliveredAt`** (admin-set now; carrier
webhook / pickup "collected" in v2).

`Order::WithdrawalDeadline()` is computed **conservatively** — closing the button *early* is the compliance
risk (a fine + the period auto-extends by up to ~12 months), so:

| Situation | Deadline |
|---|---|
| Services / digital (`default_basis: placed`) | `Placed + cooling_off_days` |
| Goods, `DeliveredAt` set | `DeliveredAt + cooling_off_days (+ grace_days)` |
| Goods, delivery unknown | **none → the button stays OPEN** (unless `auto_close_without_delivery`) |
| Goods, delivery unknown, `auto_close_without_delivery: true` | soft `Dispatched + assumed_transit_days + cooling_off_days` |

`Order::IsWithinWithdrawalPeriod()` = `now ≥ Placed && (deadline === null || now ≤ deadline)`. The button is
available from `Placed` (the right exists from conclusion, even before delivery).

### Config (all "verify with legal/DPO")

```yaml
SilverShop\Withdrawal\Extension\OrderWithdrawalExtension:
  cooling_off_days: 14
  grace_days: 0
  assumed_transit_days: 14
  auto_close_without_delivery: false   # conservative: keep open while delivery is unknown
  default_basis: 'goods'               # or 'placed' for a services/digital shop
  cancel_order_on_withdrawal: false    # v1 keeps order status manual (see below)
```

A digital/service shop sets `default_basis: 'placed'`, or overrides per order via the
`updateWithdrawalBasis(&$basis)` hook.

## Guest access (reuses order-confirmation)

The guest **online** button needs `silvershop-suite/order-confirmation` — it owns the `GuestToken` that makes an
order reachable off-session and across devices. It is a composer **`suggest`**: without it, logged-in
**members** still self-serve (resolved by the owning-member branch), and guests fall back to the
email/model-form route (the printable model form is a v2 deliverable). No parallel token machinery is added.

## Exemptions (CRD Art. 16)

- A manual **`WithdrawalExempt`** (+ reason) flag on `Product` and `Variation`, editable in the CMS — works
  **without** any other module.
- `OrderItem::IsWithdrawable()` returns a **frozen snapshot** captured at placement (`onPlacement`), so later
  product edits never change a placed order's rights.
- Deriving the snapshot fires **`updateWithdrawable(bool &$withdrawable, string &$reason)`**, the veto hook
  the planned `silvershop-suite/personalisation` and `silvershop-suite/product-status` modules consume:

  ```php
  // in a personalisation OrderItem extension
  public function updateWithdrawable(bool &$withdrawable, string &$reason): void
  {
      if ($this->getOwner()->PersonalisationHash) {   // Art. 16(c) — made to the consumer's specs
          $withdrawable = false;
          $reason = 'personalised';
      }
  }
  ```

- **Fully exempt order** → no button + an exemption notice. **Mixed order** → the withdrawable subset is
  offered (true partial per-line withdrawal is v2).

## Refund + order status

- **Refund (manual, v1):** a human issues the refund through the normal Omnipay/payment flow. When
  `silvershop-suite/invoicing` + `silvershop-suite/ledger` are present, flipping a `Payment` to `Refunded` already
  auto-creates the credit memo and posts it to the ledger — this module **records/links** the memo
  (`WithdrawalRequest.CreditMemoID`), it does not rebuild any accounting. v2 triggers the refund.
- **Order status:** v1 does **not** change the core `Order.Status` automatically. When a shop opts in
  (`cancel_order_on_withdrawal: true`), a confirmed whole-order withdrawal flips it to **`MemberCancelled`** —
  the honest existing enum value. A dedicated **`Withdrawn`** status reads better in reports but would require
  a **core enum change**, so it is deliberately out of scope here (candidate for a core PR). The
  `WithdrawalRequest` record is the authoritative withdrawal audit trail either way.

## Button placement & theming

The module ships **functional, unstyled** templates so it works headless:

- `templates/SilverShop/Withdrawal/WithdrawalPage.ss` — the self-contained form + confirmation page
- `templates/SilverShop/Withdrawal/Includes/WithdrawalButton.ss` — the button control (include where an
  `$Order` is in scope)
- `templates/SilverShop/Withdrawal/Includes/WithdrawalNotice.ss` — the inform-at-purchase notice
- `templates/SilverShop/Withdrawal/Email/WithdrawalConfirmationEmail[.ss|Plain.ss]`

A theme overrides any of these by shipping the same path. Because core's confirmation page and order email
expose **no content-injection hook today**, surfacing the button there is done by a **theme template
override** (the `silvershop-suite/storefront` theme adds `<% include SilverShop\Withdrawal\Includes\WithdrawalButton %>`
to `CheckoutPage_complete.ss`, the account order-detail view and the order email). Upstreaming a proper
`updateConfirmationData` hook + email slot is a v2 item.

## Notification-log audit

`WithdrawalConfirmationEmail` stamps two headers so an optional `silvershop-suite/notification-log` records the send
with **zero coupling** (the log only reads headers):

```
X-SilverShop-Order-ID: {OrderID}
X-SilverShop-Notification-Key: withdrawal.confirmation
```

## Installation

```bash
composer require silvershop-suite/withdrawal
```

Then run `dev/build?flush=all`. Requires `silvershop/core ^6` (CMS 6, PHP 8.3 / 8.4). Strongly suggested:
`silvershop-suite/order-confirmation` (the guest token), `silvershop-suite/storefront` (styled templates).

## v2 roadmap

Automated refund (Omnipay → invoicing credit memo → ledger) · partial per-line withdrawal · the printable
**model withdrawal form** (Annex I(B)) + prefilled-by-token · exemption automation via personalisation /
product-status · `DeliveredAt` auto-capture (carrier webhook / pickup) · upstream confirmation-data + order-email
content hooks.

## Licence

BSD-3-Clause.

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
