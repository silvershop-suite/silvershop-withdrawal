<%t SilverShop\Withdrawal\Email\WithdrawalConfirmationEmail.Heading "Your withdrawal is confirmed" %>

<%t SilverShop\Withdrawal\Email\WithdrawalConfirmationEmail.Intro "We have recorded your withdrawal from the purchase below. Keep this email as your confirmation." %>

<% if $Order %><%t SilverShop\Withdrawal\Email\WithdrawalConfirmationEmail.Order "Order" %> $Order.Reference
<% end_if %><%t SilverShop\Withdrawal\Email\WithdrawalConfirmationEmail.Reference "Withdrawal reference" %>: $WithdrawalRequest.Token
<%t SilverShop\Withdrawal\Email\WithdrawalConfirmationEmail.ConfirmedAt "Confirmed at" %>: $WithdrawalRequest.ConfirmedAt.Nice
<% if $WithdrawableItems %>
<% loop $WithdrawableItems %>- $TableTitle ($Quantity)
<% end_loop %><% end_if %>
<%t SilverShop\Withdrawal\Email\WithdrawalConfirmationEmail.RefundNote "Any refund due is returned within 14 days. The exact timing and any return conditions follow the shop policy." %>

<%t SilverShop\Withdrawal\Email\WithdrawalConfirmationEmail.Fineprint "This confirms your statutory right of withdrawal. The precise terms are set by the shop and applicable law." %>
