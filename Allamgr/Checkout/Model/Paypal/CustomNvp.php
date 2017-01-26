<?php
/**
 * ${DESCRIPTION}
 */

namespace Allamgr\Checkout\Model\Paypal;


use Magento\Payment\Model\Method\Logger;


class CustomNvp extends \Magento\Paypal\Model\Api\Nvp
{
    /**
     * @var \Magento\Framework\App\ObjectManager
     */
    //protected $_objectManager;

    protected $hasSuccessError = false;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;
    /**
     * @param \Magento\Customer\Helper\Address $customerAddress
     * @param \Psr\Log\LoggerInterface $logger
     * @param Logger $customLogger
     * @param \Magento\Framework\Locale\ResolverInterface $localeResolver
     * @param \Magento\Directory\Model\RegionFactory $regionFactory
     * @param \Magento\Directory\Model\CountryFactory $countryFactory
     * @param ProcessableExceptionFactory $processableExceptionFactory
     * @param \Magento\Framework\Exception\LocalizedExceptionFactory $frameworkExceptionFactory
     * @param \Magento\Framework\HTTP\Adapter\CurlFactory $curlFactory
     * @param array $data
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Customer\Helper\Address $customerAddress,
        \Psr\Log\LoggerInterface $logger,
        Logger $customLogger,
        \Magento\Framework\Locale\ResolverInterface $localeResolver,
        \Magento\Directory\Model\RegionFactory $regionFactory,
        \Magento\Directory\Model\CountryFactory $countryFactory,
        \Magento\Paypal\Model\Api\ProcessableExceptionFactory $processableExceptionFactory,
        \Magento\Framework\Exception\LocalizedExceptionFactory $frameworkExceptionFactory,
        \Magento\Framework\HTTP\Adapter\CurlFactory $curlFactory,
        \Magento\Checkout\Model\Session $checkoutSession,
        array $data = []
    ) {
        parent::__construct($customerAddress, $logger, $customLogger, $localeResolver, $regionFactory, $countryFactory, $processableExceptionFactory, $frameworkExceptionFactory, $curlFactory, $data);
        //$this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->checkoutSession = $checkoutSession;

    }


    /**
     * SetExpressCheckout call
     *
     * TODO: put together style and giropay settings
     *
     * @return void
     * @link https://cms.paypal.com/us/cgi-bin/?&cmd=_render-content&content_ID=developer/e_howto_api_nvp_r_SetExpressCheckout
     */
    public function callSetExpressCheckout()
    {
        $this->_prepareExpressCheckoutCallRequest($this->_setExpressCheckoutRequest);
        $request = $this->_exportToRequest($this->_setExpressCheckoutRequest);
        $this->_exportLineItems($request);

        if(isset($request['CURRENCYCODE'])){
            $request['CURRENCYCODE'] = "USD";
        }

        // import/suppress shipping address, if any
        $options = $this->getShippingOptions();
        if ($this->getAddress()) {
            $request = $this->_importAddresses($request);
            $request['ADDROVERRIDE'] = 1;
        } elseif ($options && count($options) <= 10) {
            // doesn't support more than 10 shipping options
            $request['CALLBACK'] = $this->getShippingOptionsCallbackUrl();
            $request['CALLBACKTIMEOUT'] = 6;
            // max value
            $request['MAXAMT'] = $request['AMT'] + 999.00;
            // it is impossible to calculate max amount
            $this->_exportShippingOptions($request);
        }

        $response = $this->call(self::SET_EXPRESS_CHECKOUT, $request);
        $this->_importFromResponse($this->_setExpressCheckoutResponse, $response);
    }

    protected function _handleCallErrors($response)
    {
        $errors = $this->_extractErrorsFromResponse($response);
        if (empty($errors)) {
            return;
        }

        $errorMessages = [];
        foreach ($errors as $error) {
            $errorMessages[] = $error['message'];
            $this->_callErrors[] = $error['code'];
        }
        $errorMessages = implode(' ', $errorMessages);

        $exceptionLogMessage = sprintf(
            'PayPal NVP gateway errors: %s Correlation ID: %s. Version: %s.',
            $errorMessages,
            isset($response['CORRELATIONID']) ? $response['CORRELATIONID'] : '',
            isset($response['VERSION']) ? $response['VERSION'] : ''
        );
        $this->_logger->critical($exceptionLogMessage);

        $exceptionPhrase = __('PayPal gateway has rejected request. %1', $errorMessages);

        /** @var \Magento\Framework\Exception\LocalizedException $exception */
        $firstError = $errors[0]['code'];
        $exception = $this->_isProcessableError($firstError)
            ? $this->_processableExceptionFactory->create(
                ['phrase' => $exceptionPhrase, 'code' => $firstError]
            )
            : $this->_frameworkExceptionFactory->create(
                ['phrase' => $exceptionPhrase]
            );

        if($firstError === "10415"){
            $this->checkoutSession->setData('hasSuccessError', true);
        }

        if($this->checkoutSession->getData('hasSuccessError')) { return;}

        throw $exception;
    }


    /**
     * Do the API call
     *
     * @param string $methodName
     * @param array $request
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException|\Exception
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function call($methodName, array $request)
    {
        $request = $this->_addMethodToRequest($methodName, $request);
        $eachCallRequest = $this->_prepareEachCallRequest($methodName);
        if ($this->getUseCertAuthentication()) {
            $key = array_search('SIGNATURE', $eachCallRequest);
            if ($key) {
                unset($eachCallRequest[$key]);
            }
        }
        $request = $this->_exportToRequest($eachCallRequest, $request);
        $debugData = ['url' => $this->getApiEndpoint(), $methodName => $request];

        try {
            $http = $this->_curlFactory->create();
            $config = ['timeout' => 60, 'verifypeer' => $this->_config->getValue('verifyPeer')];
            if ($this->getUseProxy()) {
                $config['proxy'] = $this->getProxyHost() . ':' . $this->getProxyPort();
            }
            if ($this->getUseCertAuthentication()) {
                $config['ssl_cert'] = $this->getApiCertificate();
            }
            $http->setConfig($config);
            $http->write(
                \Zend_Http_Client::POST,
                $this->getApiEndpoint(),
                '1.1',
                $this->_headers,
                $this->_buildQuery($request)
            );
            $response = $http->read();
        } catch (\Exception $e) {
            $debugData['http_error'] = ['error' => $e->getMessage(), 'code' => $e->getCode()];
            $this->_debug($debugData);
            throw $e;
        }

        $response = preg_split('/^\r?$/m', $response, 2);
        $response = trim($response[1]);
        $response = $this->_deformatNVP($response);

        $debugData['response'] = $response;
        $this->_debug($debugData);

        $response = $this->_postProcessResponse($response);

        // handle transport error
        if ($http->getErrno()) {
            $this->_logger->critical(
                new \Exception(
                    sprintf('PayPal NVP CURL connection error #%s: %s', $http->getErrno(), $http->getError())
                )
            );
            $http->close();

            throw new \Magento\Framework\Exception\LocalizedException(
                __('Payment Gateway is unreachable at the moment. Please use another payment option.')
            );
        }

        // cUrl resource must be closed after checking it for errors
        $http->close();

        if (!$this->_validateResponse($methodName, $response)) {
            $this->_logger->critical(new \Exception(__('PayPal response hasn\'t required fields.')));
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Something went wrong while processing your order.')
            );
        }

        $this->_callErrors = [];
        if ($this->_isCallSuccessful($response)) {
            if ($this->_rawResponseNeeded) {
                $this->setRawSuccessResponseData($response);
            }
            return $response;
        }
        $this->_handleCallErrors($response);
        return $response;
    }
}