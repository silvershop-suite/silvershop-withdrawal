<%-- The "mijn aankoop ongedaan maken" control. Include where an $Order is in scope (confirmation page,
     account order-detail). Renders the available / exempt / expired / already-withdrawn states. The
     storefront theme overrides this include for the branded button. CSS uses em (startup theme sets html=10px). --%>
<% if $Order %>
<div class="withdrawal-control">
    <% if $Order.HasConfirmedWithdrawal %>
        <p class="withdrawal-control__status"><%t SilverShop\Withdrawal\Includes\WithdrawalButton.Requested "You have withdrawn from this order. Check your email for the confirmation." %></p>
    <% else_if $Order.IsFullyWithdrawalExempt %>
        <p class="withdrawal-control__exempt"><%t SilverShop\Withdrawal\Includes\WithdrawalButton.Exempt "This order is exempt from the right of withdrawal." %></p>
    <% else_if $Order.CanWithdraw %>
        <a class="withdrawal-control__button" href="$Order.WithdrawalLink.ATT">
            <%t SilverShop\Withdrawal\Includes\WithdrawalButton.Label "Withdraw from this purchase" %>
        </a>
        <p class="withdrawal-control__hint"><%t SilverShop\Withdrawal\Includes\WithdrawalButton.Hint "Available during the 14-day cooling-off period." %></p>
    <% else_if $Order.WithdrawalPeriodExpired %>
        <p class="withdrawal-control__expired"><%t SilverShop\Withdrawal\Includes\WithdrawalButton.Expired "The 14-day withdrawal period for this order has passed." %></p>
    <% end_if %>
</div>
<style>
    .withdrawal-control { margin: 1.6em 0; }
    .withdrawal-control__button {
        display: inline-block; background: #1c6ef2; color: #fff; text-decoration: none;
        padding: 0.9em 1.4em; border-radius: 0.6em; font-weight: 600;
    }
    .withdrawal-control__hint, .withdrawal-control__status,
    .withdrawal-control__exempt, .withdrawal-control__expired {
        font-size: 1.3em; color: #5b6b7b; margin: 0.6em 0 0;
    }
</style>
<% end_if %>
