<?php

namespace Allamgr\Checkout\Model\Paypal;


use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Paypal\Model\Express\Checkout as ExpressCheckout;

class Express extends \Magento\Paypal\Model\Express
{

    /**
     * Whether method is available for specified currency
     *
     * @param string $currencyCode
     * @return bool
     */
    public function canUseForCurrency($currencyCode)
    {
        return ($currencyCode === "DOP")? true : $this->_pro->getConfig()->isCurrencyCodeSupported($currencyCode);
    }

    /**
     * Order payment
     *
     * @param \Magento\Framework\DataObject|\Magento\Payment\Model\InfoInterface|Payment $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function order(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $paypalTransactionData = $this->_checkoutSession->getPaypalTransactionData();

        if (!is_array($paypalTransactionData)) {
            $this->_placeOrder($payment, $amount);
        } else {
            $this->_importToPayment($this->_pro->getApi()->setData($paypalTransactionData), $payment);
        }

        $payment->setAdditionalInformation($this->_isOrderPaymentActionKey, true);

        if ($payment->getIsFraudDetected()) {
            return $this;
        }

        $order = $payment->getOrder();
        $orderTransactionId = $payment->getTransactionId();


        /** @var CustomNvp $api */
        $api = $this->_callDoAuthorize($amount, $payment, $orderTransactionId);

        $state = \Magento\Sales\Model\Order::STATE_PROCESSING;
        $status = true;

        $formattedPrice = $order->getBaseCurrency()->formatTxt($amount);
        if ($payment->getIsTransactionPending()) {
            $message = __('The ordering amount of %1 is pending approval on the payment gateway.', $formattedPrice);
            $state = \Magento\Sales\Model\Order::STATE_PAYMENT_REVIEW;
        } else {
            $message = __('Ordered amount of %1', $formattedPrice);
        }

        $transaction = $this->transactionBuilder->setPayment($payment)
            ->setOrder($order)
            ->setTransactionId($payment->getTransactionId())
            ->build(Transaction::TYPE_ORDER);
        $payment->addTransactionCommentsToOrder($transaction, $message);

        $this->_pro->importPaymentInfo($api, $payment);

        if ($payment->getIsTransactionPending()) {
            $message = __(
                'We\'ll authorize the amount of %1 as soon as the payment gateway approves it.',
                $formattedPrice
            );
            $state = \Magento\Sales\Model\Order::STATE_PAYMENT_REVIEW;
            if ($payment->getIsFraudDetected()) {
                $status = \Magento\Sales\Model\Order::STATUS_FRAUD;
            }
        } else {
            $message = __('The authorized amount is %1.', $formattedPrice);
        }

        $payment->resetTransactionAdditionalInfo();

        $payment->setTransactionId($api->getTransactionId());
        $payment->setParentTransactionId($orderTransactionId);

        $transaction = $this->transactionBuilder->setPayment($payment)
            ->setOrder($order)
            ->setTransactionId($payment->getTransactionId())
            ->build(Transaction::TYPE_AUTH);
        $payment->addTransactionCommentsToOrder($transaction, $message);

        $order->setState($state)
            ->setStatus($status);

        $payment->setSkipOrderProcessing(true);
        return $this;
    }

    /**
     * Place an order with authorization or capture action
     *
     * @param Payment $payment
     * @param float $amount
     * @return $this
     */
    protected function _placeOrder(Payment $payment, $amount)
    {
        $order = $payment->getOrder();

        // prepare api call
        $token = $payment->getAdditionalInformation(ExpressCheckout::PAYMENT_INFO_TRANSPORT_TOKEN);

        $cart = $this->_cartFactory->create(['salesModel' => $order]);

        /** @var CustomNvp $api */
        $api = $this->getApi()->setToken(
            $token
        )->setPayerId(
            $payment->getAdditionalInformation(ExpressCheckout::PAYMENT_INFO_TRANSPORT_PAYER_ID)
        )->setAmount(
            $amount
        )->setPaymentAction(
            $this->_pro->getConfig()->getValue('paymentAction')
        )->setNotifyUrl(
            $this->_urlBuilder->getUrl('paypal/ipn/')
        )->setInvNum(
            $order->getIncrementId()
        )->setCurrencyCode(
            //($order->getBaseCurrencyCode() === "DOP")? "USD" : $order->getBaseCurrencyCode()
            "USD"
        )->setPaypalCart(
            $cart
        )->setIsLineItemsEnabled(
            $this->_pro->getConfig()->getValue('lineItemsEnabled')
        );
        if ($order->getIsVirtual()) {
            $api->setAddress($order->getBillingAddress())->setSuppressShipping(true);
        } else {
            $api->setAddress($order->getShippingAddress());
            $api->setBillingAddress($order->getBillingAddress());
        }

        // call api and get details from it
        $api->callDoExpressCheckoutPayment();

        $this->_importToPayment($api, $payment);
        return $this;
    }

    /**
     * Call DoAuthorize
     *
     * @param int $amount
     * @param \Magento\Framework\DataObject $payment
     * @param string $parentTransactionId
     * @return \Magento\Paypal\Model\Api\AbstractApi
     */
    protected function _callDoAuthorize($amount, $payment, $parentTransactionId)
    {
        $apiData = $this->_pro->getApi()->getData();
        foreach ($apiData as $k => $v) {
            if (is_object($v)) {
                unset($apiData[$k]);
            }
        }
        $this->_checkoutSession->setPaypalTransactionData($apiData);
        $this->_pro->resetApi();
        $api = $this->_setApiProcessableErrors()
            ->setAmount($amount)
            ->setCurrencyCode(
                //($payment->getOrder()->getBaseCurrencyCode() === "DOP")? "USD" : $payment->getOrder()->getBaseCurrencyCode()
                "USD"
            )
            ->setTransactionId($parentTransactionId)
            ->callDoAuthorization();

        $payment->setAdditionalInformation(
            $this->_authorizationCountKey,
            $payment->getAdditionalInformation($this->_authorizationCountKey) + 1
        );

        return $api;
    }
}