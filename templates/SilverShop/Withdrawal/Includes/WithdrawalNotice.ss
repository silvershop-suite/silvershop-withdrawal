<%-- Inform-at-purchase notice (CRD Art. 6(1)(h) + 11a): the existence of the right of withdrawal and how
     to use it. Include at checkout and in the order email where an $Order is in scope. "verify with legal". --%>
<div class="withdrawal-notice">
    <p><%t SilverShop\Withdrawal\Includes\WithdrawalNotice.Text "You have the right to withdraw from this purchase within 14 days. You can use the withdrawal function to do so online." %></p>
    <% if $Order && $Order.WithdrawalLink %>
        <p><a href="$Order.WithdrawalLink.ATT"><%t SilverShop\Withdrawal\Includes\WithdrawalNotice.Link "Withdraw from this purchase" %></a></p>
    <% end_if %>
</div>
