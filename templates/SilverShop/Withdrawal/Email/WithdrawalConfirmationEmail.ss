<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><%t SilverShop\Withdrawal\Email\WithdrawalConfirmationEmail.Heading "Your withdrawal is confirmed" %></title>
    <style>
        body { margin: 0; padding: 0; background: #f5f6f8; color: #1c2733;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        .shell { max-width: 600px; margin: 0 auto; padding: 24px 16px; }
        .card { background: #ffffff; border: 1px solid #e3e7ec; border-radius: 12px; padding: 28px; }
        h1 { font-size: 22px; margin: 0 0 12px; }
        p { margin: 0 0 14px; font-size: 15px; line-height: 1.5; }
        .ref { font-weight: 600; font-size: 16px; }
        .muted { color: #5b6b7b; font-size: 13px; }
        .items { width: 100%; border-collapse: collapse; margin: 14px 0; }
        .items td { padding: 8px 0; border-bottom: 1px solid #eef1f4; font-size: 14px; }
        .items td.qty { text-align: right; color: #5b6b7b; }
        .note { background: #fff8ec; border: 1px solid #f3dcae; border-radius: 10px; padding: 14px; font-size: 14px; }
        .fineprint { color: #8a97a4; font-size: 12px; margin-top: 20px; }
    </style>
</head>
<body>
<div class="shell">
    <div class="card">
        <h1><%t SilverShop\Withdrawal\Email\WithdrawalConfirmationEmail.Heading "Your withdrawal is confirmed" %></h1>

        <p><%t SilverShop\Withdrawal\Email\WithdrawalConfirmationEmail.Intro "We have recorded your withdrawal from the purchase below. Keep this email as your confirmation." %></p>

        <% if $Order %>
            <p class="ref"><%t SilverShop\Withdrawal\Email\WithdrawalConfirmationEmail.Order "Order" %> $Order.Reference</p>
        <% end_if %>
        <p class="muted"><%t SilverShop\Withdrawal\Email\WithdrawalConfirmationEmail.Reference "Withdrawal reference" %>: $WithdrawalRequest.Token<br>
            <%t SilverShop\Withdrawal\Email\WithdrawalConfirmationEmail.ConfirmedAt "Confirmed at" %>: $WithdrawalRequest.ConfirmedAt.Nice</p>

        <% if $WithdrawableItems %>
            <table class="items" role="presentation">
                <% loop $WithdrawableItems %>
                    <tr>
                        <td>$TableTitle</td>
                        <td class="qty">$Quantity &times;</td>
                    </tr>
                <% end_loop %>
            </table>
        <% end_if %>

        <div class="note">
            <%t SilverShop\Withdrawal\Email\WithdrawalConfirmationEmail.RefundNote "Any refund due is returned within 14 days. The exact timing and any return conditions follow the shop policy." %>
        </div>

        <p class="fineprint"><%t SilverShop\Withdrawal\Email\WithdrawalConfirmationEmail.Fineprint "This confirms your statutory right of withdrawal. The precise terms are set by the shop and applicable law." %></p>
    </div>
</div>
</body>
</html>
