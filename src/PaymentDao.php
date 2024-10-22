<?php

namespace ReadyCMSIO\Nestpay;

interface PaymentDao {/**
	 * Fetch payment by $oid
	 * 
	 * @return \ReadyCMSIO\Nestpay\Payment
	 * @param scalar $oid
	 */
	public function getPayment($oid);
	
	/**
	 * Saves the payment
	 * 
	 * @param \ReadyCMSIO\Nestpay\Payment $payment
	 * @return \ReadyCMSIO\Nestpay\Payment
	 */
	public function savePayment(Payment $payment);

	/**
	 * Creates new payment
	 *
	 * @param array $properties
	 * @return \ReadyCMSIO\Nestpay\Payment
	 */
	public function createPayment(array $properties);
	
	
}
