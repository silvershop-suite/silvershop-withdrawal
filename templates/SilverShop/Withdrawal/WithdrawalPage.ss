<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <% base_tag %>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>$Title.XML</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #f5f6f8;
            color: #1c2733;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.5;
        }
        .wd { max-width: 640px; margin: 0 auto; padding: 32px 16px 64px; }
        .wd-card {
            background: #fff;
            border: 1px solid #e3e7ec;
            border-radius: 12px;
            padding: 28px;
            box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
        }
        h1 { font-size: 24px; margin: 0 0 8px; }
        h2 { font-size: 16px; margin: 24px 0 8px; }
        p { margin: 0 0 14px; }
        .wd-muted { color: #5b6b7b; font-size: 14px; }
        .wd-ref { font-weight: 600; }
        .wd-items { list-style: none; padding: 0; margin: 14px 0; border-top: 1px solid #eef1f4; }
        .wd-items li {
            display: flex; justify-content: space-between; gap: 12px;
            padding: 10px 0; border-bottom: 1px solid #eef1f4;
        }
        .wd-exempt { color: #8a97a4; }
        .wd-exempt .wd-tag {
            display: inline-block; font-size: 12px; color: #8a5a00;
            background: #fff4e0; border-radius: 999px; padding: 2px 8px; margin-left: 6px;
        }
        .wd-notice {
            background: #fff8ec; border: 1px solid #f3dcae; border-radius: 10px;
            padding: 16px; margin: 14px 0; font-size: 14px;
        }
        .wd-success-badge {
            display: inline-flex; align-items: center; justify-content: center;
            width: 48px; height: 48px; border-radius: 50%;
            background: #e6f6ec; color: #1a7f3c; font-size: 26px; margin-bottom: 8px;
        }
        .wd-field { margin: 0 0 16px; }
        .wd-field label { display: block; font-weight: 600; margin-bottom: 4px; }
        .wd-field input[type=email], .wd-field input[type=text], .wd-field textarea, .wd-field select {
            width: 100%; padding: 10px 12px; font-size: 15px;
            border: 1px solid #c9d2db; border-radius: 8px; background: #fff; color: inherit;
        }
        .wd-field .description, .wd-field .right-title { color: #5b6b7b; font-size: 13px; }
        .btn-toolbar, .Actions { margin-top: 8px; }
        button, input[type=submit] {
            background: #1c6ef2; color: #fff; border: 0; border-radius: 8px;
            padding: 12px 20px; font-size: 15px; font-weight: 600; cursor: pointer;
        }
        .message { padding: 12px; border-radius: 8px; margin-bottom: 14px; }
        .message.bad { background: #fdecec; color: #a12a2a; border: 1px solid #f3c6c6; }
        .message.good { background: #e6f6ec; color: #1a7f3c; border: 1px solid #b7e3c6; }
        .wd-fineprint { font-size: 12px; color: #8a97a4; margin-top: 24px; }
    </style>
</head>
<body>
<main class="wd">
    <div class="wd-card">
        <% if $IsComplete %>
            <div class="wd-success-badge" aria-hidden="true">&#10003;</div>
            <h1><%t SilverShop\Withdrawal\WithdrawalPage.CompleteHeading "Your withdrawal is confirmed" %></h1>
            <p><%t SilverShop\Withdrawal\WithdrawalPage.CompleteIntro "We have recorded your withdrawal from this purchase." %></p>

            <h2><%t SilverShop\Withdrawal\WithdrawalPage.Reference "Reference" %></h2>
            <p class="wd-ref">$WithdrawalRequest.Token</p>
            <p class="wd-muted"><%t SilverShop\Withdrawal\WithdrawalPage.ConfirmedAt "Confirmed at" %>: $WithdrawalRequest.ConfirmedAt.Nice</p>

            <div class="wd-notice">
                <%t SilverShop\Withdrawal\WithdrawalPage.RefundNote "Any refund due is returned within 14 days. The exact timing and any return conditions depend on the shop policy." %>
            </div>

            <p class="wd-muted"><%t SilverShop\Withdrawal\WithdrawalPage.EmailedTo "A copy has been emailed to" %> <strong>$WithdrawalRequest.ContactEmail.XML</strong>.</p>
        <% else_if $Order.IsFullyWithdrawalExempt %>
            <h1><%t SilverShop\Withdrawal\WithdrawalPage.ExemptHeading "This order cannot be withdrawn" %></h1>
            <div class="wd-notice">
                <%t SilverShop\Withdrawal\WithdrawalPage.ExemptNotice "The items in this order are exempt from the right of withdrawal (for example made-to-order or personalised goods). Please contact us if you have a question about your order." %>
            </div>
        <% else_if $Order.WithdrawalPeriodExpired %>
            <h1><%t SilverShop\Withdrawal\WithdrawalPage.ExpiredHeading "The withdrawal period has passed" %></h1>
            <div class="wd-notice">
                <%t SilverShop\Withdrawal\WithdrawalPage.ExpiredNotice "The 14-day cooling-off period for this order has ended. Please contact us about returns or any other question." %>
            </div>
        <% else_if $Order.CanWithdraw %>
            <h1>$Title.XML</h1>
            <p><%t SilverShop\Withdrawal\WithdrawalPage.Intro "You can withdraw from this purchase within the 14-day cooling-off period. Confirm below and we will email you a confirmation." %></p>

            <h2><%t SilverShop\Withdrawal\WithdrawalPage.OrderLabel "Order" %> $Order.Reference</h2>
            <ul class="wd-items">
                <% loop $Order.Items %>
                    <li class="<% if not $IsWithdrawable %>wd-exempt<% end_if %>">
                        <span>
                            $TableTitle
                            <% if not $IsWithdrawable %><span class="wd-tag"><%t SilverShop\Withdrawal\WithdrawalPage.ExemptTag "not withdrawable" %></span><% end_if %>
                        </span>
                        <span>$Quantity &times;</span>
                    </li>
                <% end_loop %>
            </ul>

            $WithdrawalForm
        <% else %>
            <h1><%t SilverShop\Withdrawal\WithdrawalPage.UnavailableHeading "Withdrawal is not available" %></h1>
            <div class="wd-notice">
                <%t SilverShop\Withdrawal\WithdrawalPage.UnavailableNotice "Online withdrawal is not available for this order right now. Please contact us for help." %>
            </div>
        <% end_if %>

        <p class="wd-fineprint"><%t SilverShop\Withdrawal\WithdrawalPage.Disclaimer "This withdrawal function supports your statutory right of withdrawal. The precise terms are set by the shop and applicable law." %></p>
    </div>
</main>
</body>
</html>
