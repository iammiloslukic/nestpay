<?php

namespace ReadyCMSIO\Nestpay;

class MerchantService {
	
	const HASH_V1 = 'ver1';
	const HASH_V2 = 'ver2';
	const HASH_V3 = 'ver3';
	
	const DEFAULT_HASH_ALGORITHM = 'ver2';
	
	/**
	 * @param scalar $value
	 * @return string
	 */
	public static function escapeHashValue($value, $hashAlgorithm = 'ver2') {
		if ($hashAlgorithm == self::HASH_V2) {
			return str_replace('|', '\\|', str_replace('\\', '\\\\', $value));
		}
		
		return str_replace('\\', '\\\\', $value);
	}
	
	/**
	 * @param string $storeKey
	 * @param array|string $hashParams
	 * @param string $hashAlgorithm
	 * @throws \InvalidArgumentException
	 */
	public static function calculateHash($storeKey, $hashParams, $hashAlgorithm = 'ver2') {
		
		$hashValue = '';
		
		if ($hashAlgorithm == self::HASH_V2) {
			if (!is_array($hashParams)) {
				$hashParams = explode('|', $hashParams);
			}
			
			$hashValues = [];
			
			foreach ($hashParams as $hashParam) {
			
				if ($hashParam == null) $hashParam = '';
				$hashValues[] = self::escapeHashValue($hashParam, $hashAlgorithm);
			}
			
			$hashValues[] = self::escapeHashValue($storeKey, $hashAlgorithm);
			
			$hashValue = implode('|', $hashValues);
		} else {
			if (is_array($hashParams)) {
				$hashValues = [];
				foreach ($hashParams as $hashParam) {
					if ($hashParam == null) $hashParam = '';
					$hashValues[] = self::escapeHashValue($hashParam, $hashAlgorithm);
				}
			} else {
				$hashValue = (string) $hashParams;
			}
			
			$hashValue .= $storeKey;
		}
		
		return base64_encode(pack('H*', hash('sha512', $hashValue)));
	}
	
	/**
	 * @var \ReadyCMSIO\Nestpay\MerchantConfig 
	 */
	protected $merchantConfig;
	
	/**
	 *
	 * @var \ReadyCMSIO\Nestpay\NestpayApi 
	 */
	protected $nestpayApi;
	
	/**
	 * @var \ReadyCMSIO\Nestpay\PaymentDao 
	 */
	protected $paymentDao;
	
	/**
	 * @var \ReadyCMSIO\Nestpay\Payment 
	 */
	protected $workingPayment;
	
	/**
	 * @var callable 
	 */
	protected $paymentSuccessHandler;
	
	/**
	 * @var callable
	 */
	protected $paymentFailedHandler;
	
	/**
	 * @var callable
	 */
	protected $errorHandler;
	
	/**
	 * @var \Throwable 
	 */
	protected $lastError;
	
	/**
	 * @param array $config
	 * @return \ReadyCMSIO\Nestpay\MerchantService
	 */
	public function __construct(array $config = null) {
		if (is_array($config)) {
			if (
				!isset($config['merchantConfig'])
				&& !isset($config['paymentDao'])
				&& !isset($config['workingPayment'])
				&& !isset($config['onSuccessfulPayment'])
				&& !isset($config['onFailedPayment'])
				&& !isset($config['onError'])
			) {
				//assume that merchant config array is passed
				return $this->setMerchantConfig($config);
			}
			
			$this->setProperties($config);
		}
	}
	
	/**
	 * @param array $properties
	 * @return \ReadyCMSIO\Nestpay\MerchantService
	 */
	public function setProperties(array $properties) {
		foreach ($properties as $key => $value) {
			if ($key == 'merchantConfig') {
				$this->setMerchantConfig($value);
			} else if ($key == 'paymentDao') {
				$this->setPaymentDao($value);
			} else if ($key == 'pdo') {
				$this->setPDO($value);
			} else if ($key == 'workingPayment') {
				$this->setWorkingPayment($value);
			} else if ($key == 'onSuccessfulPayment') {
				$this->onSuccessfulPayment($value);
			} else if ($key == 'onFailedPayment') {
				$this->onFailedPayment($value);
			} else if ($key == 'onError') {
				$this->onError($value);
			}
		}
		
		return $this;
	}
	
	/**
	 * @return \ReadyCMSIO\Nestpay\MerchantConfig 
	 */
	public function getMerchantConfig() {
		if (!$this->merchantConfig) {
			throw new \LogicException('Trying to use uninitialized property "merchantConfig');
		}
		
		return $this->merchantConfig;
	}
	
	/**
	 * @param array|\ReadyCMSIO\Nestpay\MerchantConfig $merchantConfig
	 * @return \ReadyCMSIO\Nestpay\MerchantService
	 */
	public function setMerchantConfig($merchantConfig) {
		if (is_array($merchantConfig)) {
			$merchantConfig = new MerchantConfig($merchantConfig);
		}
		
		if (!($merchantConfig instanceof MerchantConfig)) {
			throw new \InvalidArgumentException('Argument $merchantConfig must be array or instanceof \ReadyCMSIO\Nestpay\MerchantConfig');
		}
		
		$this->merchantConfig = $merchantConfig;
		return $this;
	}
	
	public function getNestpayApi() {
		if (!$this->nestpayApi) {
			$this->nestpayApi = new NestpayApi($this->getMerchantConfig()->toArray());
		}
		
		return $this->nestpayApi;
	}
	
	/**
	 * @return \ReadyCMSIO\Nestpay\PaymentDao
	 */
	public function getPaymentDao() {
		return $this->paymentDao;
	}
	
	/**
	 * @param \ReadyCMSIO\Nestpay\PaymentDao $paymentDao
	 * @return \ReadyCMSIO\Nestpay\MerchantService
	 */
	public function setPaymentDao(PaymentDao $paymentDao) {
		$this->paymentDao = $paymentDao;
		return $this;
	}

	/**
	 * Create payment dao using existing PDO connection
	 *
	 * @param \PDO $pdo
	 * @param string $tableName
	 * @return \ReadyCMSIO\Nestpay\MerchantService
	 */
	public function setPDO($pdo, $tableName = null)
	{
		$this->setPaymentDao(new PaymentDaoPdo($pdo, $tableName));
		return $this;
	}
	
	/**
	 * @param scalar $oid
	 * @return \ReadyCMSIO\Nestpay\MerchantService
	 * @throws MerchantServiceException In case no paymentDao is set
	 */
	protected function loadPayment($oid) {
		$paymentDao = $this->getPaymentDao();
		
		if (!$paymentDao) {
			throw new MerchantServiceException('Unable to load working payment no payment dao!');
		}
		
		return $paymentDao->getPayment($oid);
	}
	
	protected function savePayment($payment) {
		$paymentDao = $this->getPaymentDao();
		
		if ($paymentDao) {
			$paymentDao->savePayment($payment);
		}
	}
	
	/**
	 * @param array $properties
	 * @return \ReadyCMSIO\Nestpay\Payment
	 */
	protected function createPayment($properties) {
		$paymentDao = $this->getPaymentDao();
		
		
		if ($paymentDao) {
			return $paymentDao->createPayment($properties);
		}

		if (!isset($properties['oid'])) {
			$properties['oid'] = PaymentStandard::generateOid();
		}

		if (!isset($properties['trantype'])) {
			$properties['trantype'] = Payment::TRAN_TYPE_AUTH;
		}

		return new PaymentStandard($properties);
	}
	
	/**
	 * @return \ReadyCMSIO\Nestpay\Payment
	 */
	public function getWorkingPayment() {
		if (!$this->workingPayment) {
			throw new \LogicException('Trying to get working payment while it has not been initialized');
		}
		return $this->workingPayment;
	}
	
	/**
	 * 
	 * @param scalar|array|\ReadyCMSIO\Nestpay\Payment $workingPayment
	 * @return \ReadyCMSIO\Nestpay\MerchantService
	 */
	public function setWorkingPayment($workingPayment) {
		if (is_scalar($workingPayment)) {
			//got scalar, assume $workingPayment is oid 
			$workingPayment = $this->loadPayment($workingPayment);

			if (is_null($workingPayment)) {
				return $this;
			}
		}

		else if (is_array($workingPayment) && isset($workingPayment['oid'])) {
			$workingPayment = $this->loadPayment($workingPayment['oid']);

			if (is_null($workingPayment)) {
				return $this;
			}
		}
		
		if (!($workingPayment instanceof Payment)) {
			$workingPayment = $this->createPayment($workingPayment);
		}
		
		$this->workingPayment = $workingPayment;
		return $this;
	}
	
	/**
	 * Action to perform on successful payment
	 * Callable takes one parameter \ReadyCMSIO\Nestpay\Payment $payment which will be $workingPayment
	 * @param callable $handler
	 * @return \ReadyCMSIO\Nestpay\MerchantService
	 */
	public function onSuccessfulPayment(callable $handler) {
		$this->paymentSuccessHandler = $handler;
		return $this;
	}
	
	/**
	 * Action to perform on failed payment
	 * Callable takes one parameter \ReadyCMSIO\Nestpay\Payment $payment which will be $workingPayment
	 * @param callable $handler
	 * @return \ReadyCMSIO\Nestpay\MerchantService
	 */
	public function onFailedPayment(callable $handler) {
		$this->paymentFailedHandler = $handler;
		return $this;
	}
	
	/**
	 * Action to perform on error
	 * Callable takes two parameters 1) \ReadyCMSIO\Nestpay\Payment $payment which will be $workingPayment 2) \Throwable $ex last exception occured
	 * @param callable $handler
	 * @return \ReadyCMSIO\Nestpay\MerchantService
	 */
	public function onError(callable $handler) {
		$this->errorHandler = $handler;
		return $this;
	}
	
	/**
	 * @param string $eventType
	 * @return \ReadyCMSIO\Nestpay\MerchantService
	 */
	public function triggerEvent($eventType) {
		$workingPayment = $this->workingPayment;
		
		$handlerProperty = null;
		
		switch ($eventType) {
			case 'paymentSuccess':
			case 'paymentFailed':
				$handlerProperty = $eventType . 'Handler';
				if (!is_callable($this->$handlerProperty)) {
					return;
				}
				
				return call_user_func($this->$handlerProperty, $workingPayment);
				
			case 'error':
				if (!is_callable($this->errorHandler)) {
					return;
				}
				
				return call_user_func($this->errorHandler, $workingPayment, $this->lastError);
		}
	}
	
	/* HELPER METHODS */
	
	/**
	 * @return scalar
	 */
	public function getClientId() {
		return $this->getMerchantConfig()->getClientId();
	}
	
	/**
	 * @return scalar
	 */
	public function getStoreKey() {
		return $this->getMerchantConfig()->getStoreKey();
	}
	
	/**
	 * @return scalar
	 */
	public function getStoreType() {
		return $this->getMerchantConfig()->getStoreType();
	}
	
	/**
	 * @return scalar
	 */
	public function getOkUrl() {
		return $this->getMerchantConfig()->getOkUrl();
	}
	
	/**
	 * @return scalar
	 */
	public function getFailUrl() {
		return $this->getMerchantConfig()->getFailUrl();
	}
	
	/**
	 * @return scalar
	 */
	public function get3DGateUrl() {
		return $this->getMerchantConfig()->get3DGateUrl();
	}
	
	/**
	 * @return scalar
	 */
	public function getApiName() {
		return $this->getMerchantConfig()->getApiName();
	}
	
	/**
	 * @return scalar
	 */
	public function getApiPassword() {
		return $this->getMerchantConfig()->getApiPassword();
	}
	
	/**
	 * @return scalar
	 */
	public function getApiEndpointUrl() {
		return $this->getMerchantConfig()->getApiEndpointUrl();
	}
	
	/* Payment Methods*/
	
	/**
	 * 
	 * @param scalar|array|\ReadyCMSIO\Nestpay\Payment $workingPayment
	 * @return type
	 */
	public function paymentMakeRequestParameters($workingPayment = null, $hashAlgorithm = 'ver2') {
		if (!is_null($workingPayment)) {
			$this->setWorkingPayment($workingPayment);
		}
		
		$workingPayment = $this->getWorkingPayment();

		$clientId = $this->getClientId();
		$oid = $workingPayment->getOid();
		$amount = number_format($workingPayment->getAmount(), 2, '.', '');
		$okUrl = $this->getOkUrl();
		$failUrl = $this->getFailUrl();
		$trantype = $workingPayment->getTrantype();
		$instalment = $workingPayment->getInstalment();
		$rnd = $workingPayment->getRnd();
		$currency = $workingPayment->getCurrency();
		
		$storeKey = $this->getStoreKey();
		
		$hashParams = [
			$clientId,
			$oid,
			$amount,
			$okUrl,
			$failUrl,
			$trantype,
			$instalment,
			$rnd,
			'',
			'',
			'',
			$currency
		];
		
		$hash = self::calculateHash($storeKey, $hashParams, $hashAlgorithm);
		
		$formParameters = array_merge(
			$workingPayment->getProperties(null, ['processed', 'oid', 'amount', 'trantype', 'currency', 'rnd', 'lang'], true),
			[
				'clientid' => $this->getClientId(),
				'storetype' => $this->getStoreType(),
				'okurl' => $this->getOkUrl(),
				'failUrl' => $this->getFailUrl(),
				'oid' => $oid,
				'amount' => $amount,
				'TranType' => $trantype, 
				'currency' => $currency,
				'rnd' => $rnd,
				'lang' => $workingPayment->getLang(),
				'hashAlgorithm' => $hashAlgorithm,
				'hash' => $hash,
			]
		);
		
		$this->savePayment($workingPayment);
		
		return $formParameters;
	}
	
	/**
	 * Process 3D Gate response. This method triggers successfulPayment or failedPayment or error event.
	 * 
	 * @param array $responseData the $_POST data from 3D Gate
	 * @param boolean $fromFailUrl Weather process is called from failUrl page or not
	 * @param boolean $triggerEvents Trigger events "onPaymentSuccess", "onPaymentFailed", "onError" or NOT
	 * @param boolean $throwException Weather to throw exception on error or just trigger error event 
	 * @return \ReadyCMSIO\Nestpay\Payment
	 * @throws \ReadyCMSIO\Nestpay\PaymentAlreadyProcessedException
	 * @throws \Exception
	 */
	public function paymentProcess3DGateResponse(array $responseData, $fromFailUrl = false, $triggerEvents = true, $throwException = true) {
		
		$clientId = $this->getClientId();
		$storeKey = $this->getStoreKey();
		
		try {
			$oidFromResponse = null;
			
			if (!isset($responseData['Response']) || (!is_numeric($responseData['Response']) && empty($responseData['Response']))) {
				throw new Nestpay3DGateResponseException($responseData, 'Response field is not set');
			}
			
			if (!isset($responseData['clientid'])) {
				throw new Nestpay3DGateResponseException($responseData, 'Client id is empty');
			}
			
			if ($clientId != $responseData['clientid']) {
				throw new Nestpay3DGateResponseException($responseData, 'Invalid client id');
			}
			
			if (!isset($responseData['oid'])) {
				if (!isset($responseData['ReturnOid'])) {
					throw new Nestpay3DGateResponseException($responseData, 'Payment oid is empty');
				} else {
					$oidFromResponse = $responseData['ReturnOid'];
				}
			} else {
				$oidFromResponse = $responseData['oid'];
			}
			
			if (!is_numeric($oidFromResponse) && empty($oidFromResponse)) {
				throw new Nestpay3DGateResponseException($responseData, 'Invalid payment oid');
			}
			
			if (!isset($responseData['HASH'])) {
				throw new Nestpay3DGateResponseException($responseData, 'HASH is empty');
			}
			
			if (!isset($responseData['HASHPARAMS'])) {
				throw new Nestpay3DGateResponseException($responseData, 'HASHPARAMS is empty');
			}
			
			$hashAlgorithm = isset($responseData['hashAlgorithm']) ? $responseData['hashAlgorithm'] : self::DEFAULT_HASH_ALGORITHM;
			
			$hashParams = [];
			
			if ($hashAlgorithm == self::HASH_V2) {
				
				$hashParamsFields = explode('|', $responseData['HASHPARAMS']);
			} else {
				$hashParamsFields = explode(':', $responseData['HASHPARAMS']);
			}
			
			foreach ($hashParamsFields as $field) {
				$hashParams[] = isset($responseData[$field]) ? $responseData[$field] : '';
			}
			
			$calculatedHash = self::calculateHash($storeKey, $hashParams, $hashAlgorithm);
			
			if ($calculatedHash != $responseData['HASH']) {
				throw new Nestpay3DGateResponseException($responseData, 'HASH is not matching calculated hash possible attack! Calculated hash: "' . $calculatedHash . '" HASH: "' . $responseData['HASH'] . '"');
			}
			
			$workingPayment = null;
			
			try {
				$workingPayment = $this->loadPayment($oidFromResponse);
				
				if (!($workingPayment instanceof Payment)) {
					throw new MerchantServiceException('No payment is found from respnse oid: ' . $oidFromResponse);
				}
				
				$this->setWorkingPayment($workingPayment);
				
				$responseMandatoryFields = ['AuthCode', 'Response', 'mdStatus', 'HostRefNum', 'ProcReturnCode', 'TransId', 'ErrMsg'];
				
				foreach ($responseData as $key => $value) {
					if (in_array($key, $responseMandatoryFields)) {
						//force set mandatory fields
						$workingPayment->setProperty($key, $value);
						continue;
					}

					//for other fields just check if they are not set
					//to avoid overriding initial setup of email and other fields
					$property = $workingPayment->getProperty($key);
					
					if (isset($property) && (is_numeric($property) || !empty($property))) {
						
						continue;
					}
					
					$workingPayment->setProperty($key, $value);
				}
				
			} catch(MerchantServiceException $ex) {
				$workingPayment = $this->createPayment(array_merge($responseData, [
					'oid' => $oidFromResponse,
					'processed' => 0
				]));
				
				$this->setWorkingPayment($workingPayment);
			}
			
			if ($workingPayment->isProcessed()) {
				throw new PaymentAlreadyProcessedException('Payment is already processed!');
			}
			
			$workingPayment->setProcessed(1);
			
			$this->savePayment($workingPayment);
			
			if (!$triggerEvents) {
				return $workingPayment;
			}
			
			if ($workingPayment->isSuccess() && !$fromFailUrl) {
				$this->triggerEvent('paymentSuccess');
			} else {
				$this->triggerEvent('paymentFailed');
			}
			
			return $workingPayment;
			
		} catch (\Exception $e) {
			
			$this->lastError = $e;
			
			if (!$this->workingPayment) {
				$this->setWorkingPayment($responseData);
			}
			
			if ($triggerEvents) {
				$this->triggerEvent('error');
			}
			
			if ($throwException) {
				throw $e;
			}
			
			return $this->workingPayment;
		}
	}
	
	/**
	 * 
	 * @param 
	 * @param type $throwException
	 */
	
	/**
	 * @param scalar|array|\ReadyCMSIO\Nestpay\Payment $payment
	 * @param boolean $triggerEvents Trigger events "onPaymentSuccess", "onPaymentFailed", "onError" or NOT
	 * @param boolean $forceProcessed Process payment even it has been processed
	 * @param boolean $throwException throw exceptions or not
	 * @return \ReadyCMSIO\Nestpay\Payment
	 */
	public function paymentProcessOverNestpayApi($payment = null, $triggerEvents = true, $forceProcessed = false, $throwException = true) {
		if (!is_null($payment)) {
			$this->setWorkingPayment($payment);
		}
		
		$workingPayment = $this->getWorkingPayment();
		
		if ($workingPayment->isProcessed() && !$forceProcessed) {
			return $workingPayment;
		}
		
		try {
			$nestpayApi = $this->getNestpayApi();
			
			$paymentData = $nestpayApi->getPaymentData($workingPayment->getOid());
			
			if (!is_array($paymentData)) {
				//payment not found on nestpay
				//just return payment as is without saving
				
				return $workingPayment;
			}
			
			$workingPayment->setProperties($paymentData);
			
			$this->savePayment($workingPayment);
			
			if ($workingPayment->isProcessed() && $triggerEvents) {
				
				if ($workingPayment->isSuccess()) {
					$this->triggerEvent('paymentSuccess');
				} else {
					$this->triggerEvent('paymentFailed');
				}
			}
			
		} catch (\Exception $e) {
			$this->lastError = $e;
			
			if ($triggerEvents) {
				$this->triggerEvent('error');
			}
			
			if ($throwException) {
				throw $e;
			}
		}
		
		return $workingPayment;
	}

	/**
	 * @param string|\ReadyCMSIO\Nestpay\Payment $payment
	 * @return mixed The result from api as array
	 */
	public function postAuthorizationOverNestpayApi($payment = null, $amount = null, $triggerEvents = true, $throwException = true)
	{
		if (!is_null($payment)) {
			$this->setWorkingPayment($payment);
		}

		$workingPayment = $this->getWorkingPayment();

		if (is_null($amount)) {
			$amount = $workingPayment->getAmount();
		}

		try {
			$nestpayApi = $this->getNestpayApi();
			
			$response = $nestpayApi->postAuthorization($workingPayment->getOid(), $amount);
			
			return $response;

		} catch (\Exception $e) {
			$this->lastError = $e;
			
			if ($triggerEvents) {
				$this->triggerEvent('error');
			}
			
			if ($throwException) {
				throw $e;
			}
		}
	}

	/**
	 * @param string|\ReadyCMSIO\Nestpay\Payment $payment
	 * @return mixed The result from api as array
	 */
	public function voidOverNestpayApi($payment = null, $triggerEvents = true, $throwException = true)
	{
		if (!is_null($payment)) {
			$this->setWorkingPayment($payment);
		}

		$workingPayment = $this->getWorkingPayment();

		try {
			$nestpayApi = $this->getNestpayApi();
			
			$response = $nestpayApi->void($workingPayment->getOid());
			
			return $response;

		} catch (\Exception $e) {
			$this->lastError = $e;
			
			if ($triggerEvents) {
				$this->triggerEvent('error');
			}
			
			if ($throwException) {
				throw $e;
			}
		}
	}
}

