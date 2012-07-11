<?php 
class TIG_Buckaroo3Extended_Model_Response_Push extends TIG_Buckaroo3Extended_Model_Response_Abstract
{
    const PAYMENTCODE = 'buckaroo3extended';
	
    protected $_order = '';
    protected $_postArray = '';
    protected $_debugEmail = '';
    protected $_method = '';
        
	public function setCurrentOrder($order)
    {
    	$this->_order = $order;
    }
    
    public function getCurrentOrder()
    {
    	return $this->_order;
    }
    
    public function setPostArray($array)
    {
    	$this->_postArray = $array;
    }
    
    public function getPostArray()
    {
    	return $this->_postArray;
    }
    
    public function setMethod($method)
    {
//        if (strpos($method, 'buckaroo') !== false) {
//            $methodBits = explode('_', $method);
//            $method = end($methodBits);
//        }
        
    	$this->_method = $method;
    }
    
    public function getMethod()
    {
    	return $this->_method;
    }
    
    public function setDebugEmail($debugEmail)
    {
    	$this->_debugEmail = $debugEmail;
    }
    
    public function getDebugEmail()
    {
    	return $this->_debugEmail;
    }
    
    public function __construct($data = array())
    {
    	$this->setCurrentOrder($data['order']);
    	$this->setPostArray($data['postArray']);
    	$this->setDebugEmail($data['debugEmail']);
    	$this->setMethod($data['method']);
    }
	/**
	 * Processes 'pushes' recieves from Buckaroo with the purpose of updating an order or payment.
	 * 
	 * @return boolean
	 */
	public function processPush()
	{	
		//check if the push is valid and if the order can be updated
		$canProcessPush = $this->_canProcessPush();
		list($canProcess, $canUpdate) = $canProcessPush;
		
		$this->_debugEmail .= "can the order be processed? " . $canProcess . "\ncan the order be updated? " . $canUpdate . "\n";
		
		if (!$canProcess) {
			return false;
		} elseif ($canProcess && !$canUpdate) {
			//if the order cant be updated, try to add a notification to the status history instead
			$response = $this->_parsePostResponse($this->_postArray['brq_statuscode']);
			$this->_addNote($response['message'], $this->_method);
			return false;
		}
		
		$response = $this->_parsePostResponse($this->_postArray['brq_statuscode']);
		$newStates = $this->_getNewStates($response['status']);
		
		$this->_debugEmail .= "Response recieved: " . var_export($response, true) . "\n\n";
		$this->_debugEmail .= "Current state: " . $this->_order->getState() . "\nCurrent status: " . $this->_order->getStatus() . "\n";
		$this->_debugEmail .= "New state: " . $newStates[0] . "\nNew status: " . $newStates[1] . "\n\n";
        
        Mage::dispatchEvent('buckaroo3extended_push_custom_processing', array('push' => $this, 'order' => $this->getCurrentOrder(), 'response' => $response));  
                
        if ($this->getCustomResponseProcessing()) {
            return true;
        }
        
		switch ($response['status'])
		{
		    case self::BUCKAROO_ERROR:
			case self::BUCKAROO_FAILED:		       $updatedFailed = $this->_processFailed($newStates, $response['message']);
									               break;
			case self::BUCKAROO_SUCCESS:	       $updatedSuccess = $this->_processSuccess($newStates, $response['message']);
											       break;
			case self::BUCKAROO_NEUTRAL:           $this->_addNote($response['message']);
			                                       break;
			case self::BUCKAROO_PENDING_PAYMENT:   $updatedPendingPayment = $this->_processPendingPayment($newStates, $response['message']);
			                                       break;
			case self::BUCKAROO_INCORRECT_PAYMENT: $updatedIncorrectPayment = $this->_processIncorrectPayment($newStates);
			                                       break;
		}
		
		if (isset($updatedFailed) && $updatedFailed) {
			$this->_debugEmail .= "Succesfully updated 'failed' state and status \n";
		} elseif (isset($updatedSuccess) && $updatedSuccess) {
			$this->_debugEmail .= "Succesfully updated 'success' state and status \n";
		} elseif (isset($updatedPendingPayment) && $updatedPendingPayment) {
			$this->_debugEmail .= "Succesfully updated pending payment \n";
		} elseif (isset($updatedIncorrectPayment) && $updatedIncorrectPayment) {
			$this->_debugEmail .= "Succesfully updated incorrect payment \n";
		} else {
			$this->_debugEmail .= "Order was not updated \n";
		}
		
		return true;
	}
	
	
	/**
     * Checks if the post recieved is valid by checking its signature field.
     * This field is unique for every payment and every store.
     * Also calls method that checks if an order is able to be updated further.
     * Canceled, completed, holded etc. orders are not able to be updated
     * 
     * @return array $return
     */
	protected function _canProcessPush($isReturn = false)
	{
	    $correctSignature = false;
		$canUpdate = false;
	    $signature = $this->_calculateSignature($isReturn);
	    if ($signature === $this->_postArray['brq_signature']) {
	        $correctSignature = true;
	    }
	    
		//check if the order can recieve further status updates
		if ($correctSignature === true) {
			$canUpdate = $this->_canUpdate();
		}
		
		$return = array(
			(bool) $correctSignature,
			(bool) $canUpdate,
		);
		return $return;
	}

	/**
	 * Checks if the order can be updated by checking if its state and status is not
	 * complete, closed, cancelled or holded and the order can be invoiced
	 * 
	 * @return boolean $return
	 */
	protected function _canUpdate()
	{
		$return = false;
		
		// Get successful state and status
        $completedStateAndStatus  = array('complete', 'complete');
        $cancelledStateAndStatus = array('canceled', 'canceled');
        $holdedStateAndStatus = array('holded', 'holded');
        $closedStateAndStatus = array ('closed','closed');
        
		$currentStateAndStatus = array($this->_order->getState(), $this->_order->getStatus());
		
		//prevent completed orders from recieving further updates
        if($completedStateAndStatus != $currentStateAndStatus 
        	&& $cancelledStateAndStatus != $currentStateAndStatus
        	&& $holdedStateAndStatus != $currentStateAndStatus
        	&& $closedStateAndStatus != $currentStateAndStatus
        	&& $this->_order->canInvoice()
        	)
        {
        	$return = true;
        } else {
        	$this->_debugEmail .= "order already has succes, complete, closed, or holded state or can't be invoiced \n\n";
        }
        
        return $return;
	}
	
	/**
	 * Uses setState to add a comment to the order status history without changing the state nor status. Purpose of the comment
	 * is to inform the user of an attempted status upsate after the order has already recieved complete, canceled, closed or holded states
	 * or the order can't be invoiced. Returns false if the config has disabled this feature.
	 * 
	 * @param string $omschrijving
	 * 
	 * @return boolean
	 */
	protected function _addNote($omschrijving)
	{
		$note = Mage::helper('buckaroo3extended')->__('Buckaroo attempted to update this order after it already had ') 
			. '<b>' 
			. strtoupper($this->_order->getState()) 
			. '</b>' 
			. Mage::helper('buckaroo3extended')->__(' state, by sending the following: ') 
			. '<br/>--------------------------------------------------------------------------------------------------------------------------------<br/>' 
			. $omschrijving
			. ' (' 
			. $this->_postArray['brq_statuscode']
			. ')';
		$this->_order->addStatusHistoryComment($note)
					 ->save();
	}
	
	public function addNote($omschrijving)
	{
	    $this->_addNote($omschrijving);
	}
    
	/**
	 * Determines which state and status an order will recieve based on its response code
	 * and the configuration. Will use configuration for the payment method used or, if
	 * that's not set, use the default
	 * 
	 * @param string $code
	 * @param string $paymentCode
	 * 
	 * @return array $newStates
	 */
	protected function _getNewStates($code)
	{
	    //get the possible new states for the order
		$stateSuccess                = Mage::getStoreConfig('buckaroo/buckaroo3extended_advanced/order_state_success', Mage::app()->getStore()->getStoreId());
		$stateFailure                = Mage::getStoreConfig('buckaroo/buckaroo3extended_advanced/order_state_failed', Mage::app()->getStore()->getStoreId());
		$statePendingpayment         = Mage::getStoreConfig('buckaroo/buckaroo3extended_advanced/order_state_pendingpayment', Mage::app()->getStore()->getStoreId());
		
		//get the possible new status for the order based on the payment method's individual config options
		//good chance these are not set
		$customSuccessStatus         = Mage::getStoreConfig('buckaroo/' . $this->_method . '/order_status_success', Mage::app()->getStore()->getStoreId());
		$customFailureStatus         = Mage::getStoreConfig('buckaroo/' . $this->_method . '/order_status_failed', Mage::app()->getStore()->getStoreId());
		$customPendingPaymentStatus  = Mage::getStoreConfig('buckaroo/' . $this->_method . '/order_status_pendingpayment', Mage::app()->getStore()->getStoreId());
		
		//get the possible default new status for the order based on the general config options
		//these should always be set
		$defaultSuccessStatus        = Mage::getStoreConfig('buckaroo/buckaroo3extended/order_status_success', Mage::app()->getStore()->getStoreId());
		$defaultFailureStatus        = Mage::getStoreConfig('buckaroo/buckaroo3extended/order_status_failed', Mage::app()->getStore()->getStoreId());
		$defaultPendingPaymentStatus = Mage::getStoreConfig('buckaroo/buckaroo3extended/order_status_pendingpayment', Mage::app()->getStore()->getStoreId());
		
		//determine whether to use the default or custom status
		if ($customSuccessStatus && !empty($customSuccessStatus)) 
		{
			$statusSuccess = $customSuccessStatus;
		} else {
			$statusSuccess = $defaultSuccessStatus;
		}
		
		if ($customFailureStatus && !empty($customFailureStatus)) {
			$statusFailure = $customFailureStatus;
		} else {
			$statusFailure = $defaultFailureStatus;
		}
		
		if ($customPendingPaymentStatus && !empty($customPendingPaymentStatus)) {
			$statusPendingpayment = $customPendingPaymentStatus;
		} else {
			$statusPendingpayment = $defaultPendingPaymentStatus;
		}
		
		$stateIncorrectPayment  = 'holded';
		$statusIncorrectPayment = 'buckaroo_incorrect_payment';
		
		//magento 1.4 compatibility
		$version15 = '1.5.0.0';
        $version14 = '1.4.0.0';
		if (version_compare(Mage::getVersion(), $version15, '<') 
		    && version_compare(Mage::getVersion(), $version14, '>')
		    && $statusIncorrectPayment == 'buckaroo_incorrect_payment'
		    )
		{
		    $statusIncorrectPayment = 'payment_review';
		}
		
		switch($code)
        {
            case self::BUCKAROO_SUCCESS:           $newStates = array($stateSuccess, $statusSuccess);
                                                   break;
            case self::BUCKAROO_ERROR:
            case self::BUCKAROO_FAILED:            $newStates = array($stateFailure, $statusFailure);
                                                   break;
            case self::BUCKAROO_NEUTRAL:           $newStates = array(null, null);
                                                   break; 
            case self::BUCKAROO_PENDING_PAYMENT:   $newStates = array($statePendingpayment, $statusPendingpayment);
                                                   break;
            case self::BUCKAROO_INCORRECT_PAYMENT: $newStates = array($stateIncorrectPayment, $statusIncorrectPayment);
                                                   break;
            default:					           $newStates = array(null, null);                                                                                
        }
        
        return $newStates;
	}
	
	/**
	 * Process a succesful order. Sets its new state and status, sends an order confirmation email
	 * and creates an invoice if set in config.
	 * 
	 * @TODO $trx will be used for Buckaroo2012Refund, to be added in 3.0.0
	 * 
	 * @param array $response | int $response
	 * @param string $description
	 * 
	 * @return boolean
	 */
	protected function _processSuccess($newStates, $description = false)
	{	
        $this->_autoInvoice();
	    
		$description = Mage::helper('buckaroo3extended')->__($description);
			
		$description .= " (#{$this->_postArray['brq_statuscode']})";
		
		//sets the transaction key if its defined ($trx)
		//will retrieve it from the response array, if response actually is an array
		if (!$this->_order->getTransactionKey() && array_key_exists('brq_transactions', $this->_postArray)) {
			$this->_order->setTransactionKey($this->_postArray['brq_transactions']);
			$this->_order->setTransactionId($this->_postArray['brq_transactions']);
			$this->_order->save();
		}
		
		$this->_order->setState($newStates[0], $newStates[1], $description)
			         ->save();
		
		//send new order email if it hasnt already been sent
		if(!$this->_order->getEmailSent())
        {
        	$this->_order->sendNewOrderEmail();
        }
        
		return true;
	}
	
	/**
	 * Process a failed order. Sets its new state and status and cencels the order
	 * if set in config.
	 * 
	 * @param array $newStates
	 * @param string $description
	 * 
	 * @return boolean
	 */
	protected function _processFailed($newStates, $description = false)
	{
		$description .= " (#{$this->_postArray['brq_statuscode']})";
		
	    //sets the transaction key if its defined ($trx)
		//will retrieve it from the response array, if response actually is an array
		if (!$this->_order->getTransactionKey() && array_key_exists('brq_transactions', $this->_postArray)) {
			$this->_order->setTransactionKey($this->_postArray['brq_transactions']);
		}
		
		if (Mage::getStoreConfig('buckaroo/buckaroo3extended_advanced/cancel_on_failed', Mage::app()->getStore()->getStoreId())) {
	    	$this->_order->cancel()
	    				 ->save();
	    	if ($description) {
	    		$this->_order->addStatusHistoryComment(Mage::helper('buckaroo3extended')->__($description))
	    					 ->save();
	    	}
	    } else {	    	
	    	$this->_order->setState($newStates[0], $newStates[1], Mage::helper('buckaroo3extended')->__($description))
	    				 ->save();
	    }
	    return true;
	}
	
	/**
	 * Processes an order for which an incorrect amount has been paid (can only happen with Overschrijving)
	 * 
	 * @return boolean
	 */
	protected function _processIncorrectPayment($newStates)
	{
		//determine whether too much or not enough has been paid and determine the status history copmment accordingly
		$amount = round($this->_order->getBaseGrandTotal()*100, 0);
		$currency = $this->_order->getBaseCurrencyCode();
		
		if ($amount > $this->_postArray['brq_amount']) {
	    	$setState = $newStates[0];
            $setStatus = $newStates[1];
            $description = Mage::helper('buckaroo3extended')->__('te weinig betaald: ') 
               			 . round(($this->_postArray['brq_amount'] / 100), 2)
               			 . ' '
               			 . $currency
               			 . Mage::helper('buckaroo3extended')->__(' is overgemaakt. Order bedrag was: ') 
              			 . round($this->_order->getGrandTotal(), 2)
               			 . ' '
               			 . $currency;
	    } elseif ($amount < $this->_postArray['bpe_amount']) {
	    	$setState = $newStates[0];
            $setStatus = $newStates[1];
            $description = Mage::helper('buckaroo3extended')->__('te veel betaald: ') 
               			 . round(($this->_postArray['brq_amount'] / 100), 2)
               			 . ' '
               			 . $currency
               			 . Mage::helper('buckaroo3extended')->__(' is overgemaakt. Order bedrag was: ') 
               			 . round($this->_order->getGrandTotal(), 2)
               			 . ' '
               			 . $currency;
	    } else {
	    	//the correct amount was actually paid, so return false
	    	return false;
	    }
	    
	    if (Mage::getStoreConfig('buckaroo/buckaroo3extended_transfer/on_hold_email')) {
	        $this->_sendOverschrijvingOnHoldEmail();
	    }
	    
	    //hold the order
		$this->_order->hold()
		             ->save();
	    $this->_order->setState($setState, $setStatus, Mage::helper('buckaroo3extended')->__($description))
	    			 ->save();
	    
	    return true;
	}
	
	/**
	 * processes an order awaiting payment. Sets its new state and status.
	 * 
	 * @param array $newStates
	 * @param string $description
	 * 
	 * @return boolean
	 */
    protected function _processPendingPayment($newStates, $description = false)
	{	
		$description = Mage::helper('buckaroo3extended')->__($description);
		$description .= " (#{$this->_postArray['brq_statuscode']})";
		
	    //sets the transaction key if its defined ($trx)
		//will retrieve it from the response array, if response actually is an array
		if (!$this->_order->getTransactionKey() && array_key_exists('brq_transactions', $this->_postArray)) {
			$this->_order->setTransactionKey($this->_postArray['brq_transactions']);
		}
		
		$this->_order->setState($newStates[0], $newStates[1], $description)
					 ->save();
		                            
		return true;
	}
	
	public function getNewStates($code)
	{
	    return $this->_getNewStates($code);
	}
	
	public function processPendingPayment($newStates, $description = false) {
	    return $this->_processPendingPayment($newStates, $description);
	}
	
    public function processSuccess($newStates, $description = false) {
	    return $this->_processPendingPayment($newStates, $description);
	}
	
    public function processFailed($newStates, $description = false) {
	    return $this->_processPendingPayment($newStates, $description);
	}
	
    public function processIncorrectPayment($newStates) {
	    return $this->_processPendingPayment($newStates);
	}
	
	/**
	 * Creates an invoice for the order if set to do so in config.
	 * 
	 * @return boolean |
	 */
	protected function _autoInvoice()
	{		
		if (!Mage::getStoreConfig('buckaroo/buckaroo3extended_advanced/auto_invoice', Mage::app()->getStore()->getStoreId()))
	    {
	    	return false;
	    }
	    
	    $this->_saveInvoice();
	                            
	    if(Mage::getStoreConfig('buckaroo/buckaroo3extended_advanced/invoice_mail', Mage::app()->getStore()->getStoreId()))
	    {
		    foreach($this->_order->getInvoiceCollection() as $invoice)
		    {
			    if(!$invoice->getEmailSent())
			    {
				    $invoice->sendEmail()
				    		->setEmailSent(true)
				    		->save();
			    }
		    }
	    }                            
	}
	
	/**
	 * Saves an invoice and sets totalpaid for the order
	 * 
	 * @param float $paid
	 * 
	 * @return boolean
	 */
    protected function _saveInvoice()
    {  
	    if ($this->_order->canInvoice()) {
	        $payment = $this->_order->getPayment()->registerCaptureNotification($this->_order->getBaseGrandTotal());
	        $this->_order->save();
	        $this->_debugEmail .= 'Invoice created and saved. \n';
	        return true;
	    }
        foreach($this->_order->getInvoiceCollection() as $invoice)
	    {
	        $invoice->setTransactionId($this->_postArray['brq_transactions']);
	    }
        return false;
    }
    
	/**
	 * Determines the signature using array sorting and the SHA1 hash algorithm
	 * 
	 * @param array $origArray
	 * 
	 * @return string $signature
	 */
	protected function _calculateSignature($isReturn = false)
	{
	    if (isset($this->_postArray['isOldPost']) && $this->_postArray['isOldPost'])
	    {
	        return $this->_calculateOldSignature();
	    }
	    
	    $origArray = $this->_postArray;
	    unset($origArray['brq_signature']);
	    
	    //sort the array
	    list($sortableArray) = $this->keyNatCaseSort($origArray);
	    
	    //turn into string and add the secret key to the end
	    $signatureString = '';
	    foreach($sortableArray as $key => $value) {
            $value = urldecode($value);
	        $signatureString .= $key . '=' . $value;
	    }
	    $signatureString .= Mage::getStoreConfig('buckaroo/buckaroo3extended/digital_signature', Mage::app()->getStore()->getStoreId());
	    
	    $this->_debugEmail .= "\nSignaturestring: {$signatureString}\n";
	    
	    //return the SHA1 encoded string for comparison
	    $signature = SHA1($signatureString);
	    
	    $this->_debugEmail .= "\nSignature: {$signature}\n";
	    
	    return $signature;
	}
	
	protected function _calculateOldSignature()
	{
        $signature2 = md5(
			$this->_postArray['oldPost']["bpe_trx"]
			. $this->_postArray['oldPost']["bpe_timestamp"]
			. Mage::getStoreConfig('buckaroo/buckaroo3extended/key', Mage::app()->getStore()->getStoreId())
			. $this->_postArray['oldPost']["bpe_invoice"]
			. $this->_postArray['oldPost']["bpe_reference"]
			. $this->_postArray['oldPost']["bpe_currency"]
			. $this->_postArray['oldPost']["bpe_amount"]
			. $this->_postArray['oldPost']["bpe_result"]
			. $this->_postArray['oldPost']["bpe_mode"]
			. Mage::getStoreConfig('buckaroo/buckaroo3extended/digital_signature', Mage::app()->getStore()->getStoreId())
		);
		
		return $signature2;
	}
}