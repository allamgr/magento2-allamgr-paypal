# magento2-allamgr-paypal
Magento 2 Module for allow paypal module with a custom country, and change the currency of payment, fow now only work with the Paypal Express method and dont conver the currencies.

# Installation

Copy the folder Allamgr in the app/code folder of magento2

Open terminal and run 
<code>magento setup:upgrade</code> from magento root folder
or run <code>magento php -f bin\magento setup:upgrade</code> if you don't have magento cli installed

#How it works
You can set your own logic for which countries it will be available the method in app/code/Allamgr/Checkout/Model/Paypal/Express.php
<pre>
public function canUseForCurrency($currencyCode)
{
    return ($currencyCode === "DOP")? true : $this->_pro->getConfig()->isCurrencyCodeSupported($currencyCode);
}
</pre>


Set an available currency for paypal for proceed with the order method _callDoAuthorize in app/code/Allamgr/Checkout/Model/Paypal/Express.php 
<pre>
$api = $this->_setApiProcessableErrors()
            ->setAmount($amount)
            ->setCurrencyCode(
                //Custom logic  
                //($payment->getOrder()->getBaseCurrencyCode() === "DOP")? "USD" : $payment->getOrder()->getBaseCurrencyCode()
                //or Espefific logic
                "USD"
            )
            ->setTransactionId($parentTransactionId)
            ->callDoAuthorization();
</pre>

#TODO:
Convert the currency
Improve the readme.me
