<?php

/**
 * - Autodetect language in resolveAsiaPayLang(), right now it is read from the language file which is ok, as we have different language files pr. language
 */

defined('_JEXEC') or die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');

if (!class_exists ('vmPSPlugin')) {
	require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

/**
 * AsiaPay payment module for virtuemart 3.x - based on the paypal payment API
 */
class plgVMPaymentAsiaPay extends vmPSPlugin {

	function __construct (& $subject, $config) {

		parent::__construct ($subject, $config);
		$jlang = JFactory::getLanguage ();
		$jlang->load ('plg_vmpayment_asiapay', JPATH_ADMINISTRATOR, NULL, TRUE);
		$this->_loggable = TRUE;
		$this->_debug = TRUE;
		$this->tableFields = array_keys ($this->getTableSQLFields ());
		$this->_tablepkey = 'id';
		$this->_tableId = 'id';

		$varsToPush = array(
            'asiapay_payment_url' 			=> array('', 'char'),
    	    'asiapay_merchant_id' 			=> array('', 'char'),
    	    'asiapay_pay_method' 			=> array('', 'char'),
    		'asiapay_pay_type' 				=> array('', 'char'),
    		'asiapay_secure_hash_secret' 	=> array('', 'char'),
    		'asiapay_transaction_type' 		=> array('', 'char'),
    		'asiapay_challenge_pref' 		=> array('', 'char'),
    	);

		$this->setConfigParameterable ($this->_configTableFieldName, $varsToPush);
	}
	
	
	/**
	 * For generating secureHash to be sent to AsiaPay
	 */
	function generatePaymentSecureHash($merchantId, $merchantReferenceNumber, $currencyCode, $amount, $paymentType, $secureHashSecret) {
		$buffer = $merchantId . '|' . $merchantReferenceNumber . '|' . $currencyCode . '|' . $amount . '|' . $paymentType . '|' . $secureHashSecret;
		return sha1($buffer);
	}
	
	
	/**
	 * For validating the secureHash sent by AsiaPay
	 */
	function verifyPaymentDatafeed($src, $prc, $successCode, $merchantReferenceNumber, $paydollarReferenceNumber, $currencyCode, $amount, $payerAuthenticationStatus, $secureHashSecret, $secureHash) {
		$buffer = $src . '|' . $prc . '|' . $successCode . '|' . $merchantReferenceNumber . '|' . $paydollarReferenceNumber . '|' . $currencyCode . '|' . $amount . '|' . $payerAuthenticationStatus . '|' . $secureHashSecret;
		$verifyData = sha1($buffer);
		if ($secureHash == $verifyData) {
			return true;
		}
		return false;
	}
	
	
	/**
	 * Decide the language to be used on AsiaPay pages
	 */
	private function resolveAsiaPayLang() {
		$txtId = "VMPAYMENT_ASIAPAY_LANGUAGE";
		if(JText::_($txtId)) {
			return JText::_($txtId);
		}
		else {
			return 'E';
		}
	}

	
	public function getVmPluginCreateTableSQL () {

		return $this->createTableSQL ('Payment AsiaPay Table');
	}

	
	function getTableSQLFields () {
		$SQLfields = array(
				'id' 							=> 'int(11) unsigned NOT NULL AUTO_INCREMENT ',
				'virtuemart_order_id' 			=> 'int(11) UNSIGNED DEFAULT NULL',
				'order_number' 					=> 'char(32) DEFAULT NULL',
				'virtuemart_paymentmethod_id' 	=> 'mediumint(1) UNSIGNED DEFAULT NULL',
				'payment_name' 					=> 'char(255) NOT NULL DEFAULT \'\' ',
				'payment_order_total' 			=> 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
				'payment_currency' 				=> 'char(3) ',
				'cost_per_transaction' 			=> 'decimal(10,2) DEFAULT NULL ',
				'cost_percent_total' 			=> 'decimal(10,2) DEFAULT NULL ',
				'tax_id' 						=> 'smallint(1) DEFAULT NULL',
		
				'asiapay_Ref' 					=> 'char(50) DEFAULT NULL',
				'asiapay_PayRef' 				=> 'char(50) DEFAULT NULL',
				'asiapay_Ord' 					=> 'char(50) DEFAULT NULL',
				'asiapay_AuthId' 				=> 'char(50) DEFAULT NULL',
				'asiapay_successcode'			=> 'char(50) DEFAULT NULL'
		
		);
		return $SQLfields;
	}

	
	function plgVmConfirmedOrder($cart, $order) {

    	if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
    	    return null; // Another method was selected, do nothing
    	}
    	if (!$this->selectedThisElement($method->payment_element)) {
    	    return false;
    	}
    	$session = JFactory::getSession();
    	$return_context = $session->getId();
    	$this->_debug = $method->debug;
    	$this->logInfo('plgVmConfirmedOrder order number: ' . $order['details']['BT']->order_number, 'message');
    
    	if (!class_exists('VirtueMartModelOrders'))
    	    require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
    	if (!class_exists('VirtueMartModelCurrency'))
    	    require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
    
    	$new_status = '';
    
    	$usrBT = $order['details']['BT'];
    	$address = ((isset($order['details']['ST'])) ? $order['details']['ST'] : $order['details']['BT']);
    
    	$vendorModel = new VirtueMartModelVendor();
    	$vendorModel->setId(1);
    	$vendor = $vendorModel->getVendor();
    	$this->getPaymentCurrency($method);    	
    	$currency_numeric_code = shopFunctions::getCurrencyByID ($method->payment_currency, 'currency_numeric_code');
    
    	$paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
    	$totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total,false), 2);
    	$cd = CurrencyDisplay::getInstance($cart->pricesCurrency);
        
    	//prepare asiapay parameters
    	$orderRef = $order['details']['BT']->order_number;
    	$amount = $totalInPaymentCurrency;
    	$payMethod = $method->asiapay_pay_method;
    	$payType = $method->asiapay_pay_type;
    	$merchantId = $method->asiapay_merchant_id;    	    	
   	 	$secureHash = '';


		$transacionType = $method->asiapay_transaction_type;
		$challengePref = $method->asiapay_challenge_pref;

		if($method->asiapay_secure_hash_secret != ''){
			$secureHash = $this -> generatePaymentSecureHash($merchantId, $orderRef, $currency_numeric_code, $amount, $payType, $method->asiapay_secure_hash_secret);
		}
		$remark = $session->getId(); //sessionid

		$b = $order['details']['BT'];
		$s = ((isset($order['details']['ST'])) ? $order['details']['ST'] : '');
    	$billInfo = $this->_setBillingInfo($b);
    	$shipInfo = $this->_setShippingInfo($s);
    	
    	$post_variables = Array(
    			'orderRef' 		=> $orderRef,
    			'amount' 		=> $amount,
    			'currCode' 		=> $currency_numeric_code,
    			'lang' 			=> $this->resolveAsiaPayLang(),
    			
    			'payMethod' 	=> $payMethod,
    			'payType' 		=> $payType,
    			'merchantId' 	=> $merchantId,
    			
    			'successUrl' 	=> JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id),
    			'failUrl' 		=> JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id),
    			'cancelUrl' 	=> JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id),
    			'secureHash' 	=> $secureHash,
    			'remark'		=> $remark,
    			'failRetry' 	=> 'no',

    			//3ds2.0
    			'threeDSTransType' => $transacionType,
    			'threeDSChallengePreference' => $challengePref,
    			'threeDSIsAddrMatch' => $this->isSameBillShipAddress($billInfo,$shipInfo),
    			'threeDSShippingDetails' => $this->isSameBillShipAddress($billInfo,$shipInfo) ? '01' : '03',
    	);

    	

    	//check if user registered
    	$acctInfo = $this->getAccountInfo();

    	
    	$userOrderLists = $this->getUserOrders();
    	$post_variables = array_merge($post_variables, $billInfo , $shipInfo, $acctInfo, $userOrderLists);
    	// echo "<pre>";

    	// print_r($user);
    	// print_r($post_variables);
    	// print_r($order);
    	// exit;
    
    	// Prepare data that should be stored in the database
    	$dbValues['order_number'] = $order['details']['BT']->order_number;
    	$dbValues['payment_name'] = $this->renderPluginName($method, $order);
    	$dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
    	$dbValues['cost_per_transaction'] = $method->cost_per_transaction;
    	$dbValues['cost_percent_total'] = $method->cost_percent_total;
    	$dbValues['payment_currency'] = $method->payment_currency;
    	$dbValues['payment_order_total'] = $totalInPaymentCurrency;
    	$dbValues['tax_id'] = $method->tax_id;
    	$this->storePSPluginInternalData($dbValues);
    	 
    	// add form data    
    	$html = '<form action="' . $method->asiapay_payment_url . '" method="post" name="vm_asiapay_form" >';
    	$html.= JTExt::sprintf('VMPAYMENT_ASIAPAY_PAY_BUTTON');
    	foreach ($post_variables as $name => $value) {
    	    $html.= '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars($value) . '" />';
    	}
    	$html.= '</form>';    
    	$html.= ' <script type="text/javascript">';
    	$html.= ' document.vm_asiapay_form.submit();';
    	$html.= ' </script>';
    	// 	2 = don't delete the cart, don't send email and don't redirect
    	return $this->processConfirmedOrderPaymentResponse(2, $cart, $order, $html, $new_status);    
    }

    
	function plgVmgetPaymentCurrency ($virtuemart_paymentmethod_id, &$paymentCurrencyId) {

		if (!($method = $this->getVmPluginMethod ($virtuemart_paymentmethod_id))) {
			return NULL;
		} // Another method was selected, do nothing

		if (!$this->selectedThisElement ($method->payment_element)) {
			return FALSE;
		}

		$this->getPaymentCurrency ($method);
		$paymentCurrencyId = $method->payment_currency;
	}

	
	function plgVmOnPaymentResponseReceived(  &$html) {
        // the payment itself should send the parameter needed.
        $virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
    
    	$vendorId = 0;
    	if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
    	    return null; // Another method was selected, do nothing
    	}
    	if (!$this->selectedThisElement($method->payment_element)) {
    	    return false;
    	}
    
    	$payment_data = JRequest::get('get');
    	vmdebug('plgVmOnPaymentResponseReceived', $payment_data);
    	$order_number = $payment_data['Ref'];
    	
    	if (!class_exists('VirtueMartModelOrders')){
    	    require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
    	}
    
    	$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
    	$payment_name = $this->renderPluginName($method);
    	$html = ""; // Here we could add some AsiaPay status info, but we dont
    	if ($virtuemart_order_id) {
    		if (!class_exists('VirtueMartCart')){
    		    require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
    		}
    		// get the correct cart / session
    		$cart = VirtueMartCart::getCart();    		
    		$cart->emptyCart();
    	}
    
    	return true;
    }

    
	function plgVmOnUserPaymentCancel() {
		if (!class_exists('VirtueMartModelOrders')){
		    require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
		}
	
		$order_number = JRequest::getVar('Ref');
		if (!$order_number){
		    return false;
		}
		
		$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber ($order_number);
	
		if (!$virtuemart_order_id) {
		    return null;
		}
		
		//load the order
		$order = VirtueMartModelOrders::getOrder($virtuemart_order_id);
		
		if($order['details']['BT']->order_status == 'P' || $order['details']['BT']->order_status == 'X'){
			$this->handlePaymentUserCancel($virtuemart_order_id);
		}
	
		return true;
    }
    

	/**
     * plgVmOnPaymentNotification() - This event is fired by Offline Payment. It can be used to validate the payment data as entered by the user.
     * AsiaPay Datafeed Function
     */
    function plgVmOnPaymentNotification() {
    	
    	if (!class_exists('VirtueMartModelOrders')){
    		require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
    	}
    	
    	$postData = JRequest::get('post');
    	
    	$src = $postData['src'];
    	$prc = $postData['prc'];
    	$ord = $postData['Ord'];
    	$holder = $postData['Holder'];
    	$successCode = $postData['successcode'];
    	$ref = $postData['Ref'];
    	$payRef = $postData['PayRef'];
    	$amt = $postData['Amt'];
    	$cur = $postData['Cur'];
    	$remark = $postData['remark']; //sessionid
    	$authId = $postData['AuthId'];
    	$eci = $postData['eci'];
    	$payerAuth = $postData['payerAuth'];
    	$sourceIp = $postData['sourceIp'];
    	$ipCountry = $postData['ipCountry'];
    	$secureHash = $postData['secureHash'];
    	
    	echo 'OK!';
    	
    	$post_msg = '';
    	foreach ($postData as $key => $value) {
    		$post_msg .= $key . "=" . $value . "|";    		
    	}
    	
    	$this->logInfo('asiapay callback data [' . $post_msg . ']');
		
    	$return_context = $remark;
    	
		//$this->_debug = true;
		$order_number = $ref;
		$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);		
		
		if ($virtuemart_order_id) {
			$this->logInfo('plgVmOnPaymentNotification: virtuemart_order_id  found ' . $virtuemart_order_id, 'message');
			echo ' - virtuemart_order_id  found ' . $virtuemart_order_id . '.';
		}else{
		    $this->_debug = true; // force debug here
		    $this->logInfo('plgVmOnPaymentNotification: virtuemart_order_id not found', 'ERROR');
		    echo ' - virtuemart_order_id not found';
		    /* send an email to admin, and ofc not update the order status: exit  is fine */
		    //$this->sendEmailToVendorAndAdmins(JText::_('VMPAYMENT_ASIAPAY_ERROR_EMAIL_SUBJECT'), JText::_('VMPAYMENT_ASIAPAY_UNKNOW_ORDER_ID'));
		    exit;
		}
		
		//load the order
		$order = VirtueMartModelOrders::getOrder($virtuemart_order_id);
		
		$payment = $this->getDataByOrderId($virtuemart_order_id);
		
		if (!$payment) {
			$this->logInfo('getDataByOrderId payment not found: exit ', 'ERROR');
			echo ' - getDataByOrderId payment not found: exit';
			return false;
		}
		
		$method = $this->getVmPluginMethod($payment->virtuemart_paymentmethod_id);
		if (!$this->selectedThisElement($method->payment_element)) {
		    return false;
		}
	
		$this->_debug = $method->debug;
		
		// get all know columns of the table
		$db = JFactory::getDBO();
		$query = 'SHOW COLUMNS FROM `' . $this->_tablename . '` ';
		$db->setQuery($query);
		$columns = $db->loadColumn (0);
		foreach ($postData as $key => $value) {
		    $table_key = 'asiapay_' . $key;
		    if (in_array($table_key, $columns)) {
				$response_fields[$table_key] = $value;
		    }
		}
		$response_fields['order_number'] = $order_number;
		$response_fields['payment_name'] = $this->renderPluginName($method);		
		$response_fields['virtuemart_paymentmethod_id'] = $payment->virtuemart_paymentmethod_id;
		$response_fields['payment_currency'] = $payment->payment_currency;
		$response_fields['payment_order_total'] = $payment->payment_order_total;
		$response_fields['cost_per_transaction'] = $payment->cost_per_transaction;
		$response_fields['cost_percent_total'] = $payment->cost_percent_total;
		$response_fields['tax_id'] = $payment->tax_id;
		
	    $this->storePSPluginInternalData($response_fields);
	    
	    $secureHashArr = explode ( ',', $secureHash );
	    while ( list ( $key, $value ) = each ( $secureHashArr ) ) {
	    	$checkSecureHash = $this->verifyPaymentDatafeed($src, $prc, $successCode, $ref, $payRef, $cur, $amt, $payerAuth, $method->asiapay_secure_hash_secret, $value);
	    	if($checkSecureHash){
	    		break;
	    	}
	    }
	    
	    if( $method->asiapay_secure_hash_secret == '' || $checkSecureHash ){
	    
	    	$this->logInfo('plgVmOnPaymentNotification return new_status:' . $new_status, 'message');
	    	$this->logInfo('plgVmOnPaymentNotification session:', $return_context);
	    	
			if ($postData['successcode'] == "0") {
				$new_status = 'C';	   
			    
			    $modelOrder = new VirtueMartModelOrders();
			    $order['order_status'] = $new_status;
			    $order['virtuemart_order_id'] = $virtuemart_order_id;
			    $order['customer_notified'] = 1;
			    $order['comments'] = JTExt::sprintf('VMPAYMENT_ASIAPAY_PAYMENT_SUCCESS', $payRef);
			    $modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);
			    
			    $this->logInfo('process OK, status', 'message');
			    echo ' - Accepted/Authorized';			    
			} else {
				if($order['details']['BT']->order_status == 'P' || $order['details']['BT']->order_status == 'X'){
					$new_status = 'X';			    	
			    	
			    	$modelOrder = new VirtueMartModelOrders();
			    	$order['order_status'] = $new_status;
			    	$order['virtuemart_order_id'] = $virtuemart_order_id;
			    	$order['customer_notified'] = 0;
			    	$order['comments'] = JTExt::sprintf('VMPAYMENT_ASIAPAY_PAYMENT_FAILED', $payRef);
			    	$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);
			    	
			    	$this->logInfo('process ERROR' , 'ERROR');
			    	echo ' - Rejected';
				}
		    }
		    
	    }else{
	    	$this->logInfo('plgVmOnPaymentNotification: SecureHash checking FAILED', 'ERROR');
	    	echo ' - SecureHash checking FAILED';
	    }
	    
		$this->emptyCart($return_context);
		return true;
    }

    
	/**
     * Display stored payment data for an order
     * @see components/com_virtuemart/helpers/vmPSPlugin::plgVmOnShowOrderBEPayment()
     */
    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $payment_method_id) {

		if (!$this->selectedThisByMethodId ($payment_method_id)) {
			return NULL; // Another method was selected, do nothing
		}
		
		if (!($payments = $this->getDatasByOrderId($virtuemart_order_id))) {
			// JError::raiseWarning(500, $db->getErrorMsg());
			return '';
		}
		
		//echo '<pre>';
		//print_r($payments);
		//echo '</pre>';
		
		$html = '<table class="adminlist" width="100%">' . "\n";
		$html .= $this->getHtmlHeaderBE ();
		$code = "asiapay_";
		
		$html .= '<tr class="row1"><td colspan="2"><center><strong>' . $payments[0]->payment_name . '</strong></center></td></tr>';
		
		foreach ($payments as $payment) {	
			if($payment->asiapay_PayRef != 0){
				$html .= '<tr class="row1"><td><strong>' . JText::_ ('VMPAYMENT_ASIAPAY_DATE') . '</strong></td><td align="left">' . $payment->created_on . '</td></tr>';
				foreach ($payment as $key => $value) {
				
					if (substr ($key, 0, strlen ($code)) == $code) {
						if($key == 'asiapay_successcode'){
							if($value == '0'){
								$displayValue = 'Successful';
							}else{
								$displayValue = 'Unsuccessful';
							}							
							$html .= '<tr><td>' . JText::_ ('VMPAYMENT_ASIAPAY_SUCCESSCODE') . '</td><td align="left">' . $displayValue . '</td></tr>';
						}else{
							$html .= $this->getHtmlRowBE ($key, $value);
						}
					}
				
				}
				$html .= '<tr><td colspan="2"><hr/></td></tr>';
			}			
		}
		$html .= '</table>' . "\n";
		return $html;
		
    }

    
	/**
     * Check if the payment conditions are fulfilled for this payment method
     * @author: Valerie Isaksen
     *
     * @param $cart_prices: cart prices
     * @param $payment
     * @return true: if the conditions are fulfilled, false otherwise
     *
     */
    protected function checkConditions($cart, $method, $cart_prices) {

		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);
	
		$amount = $cart_prices['salesPrice'];
		$amount_cond = ($amount >= $method->min_amount AND $amount <= $method->max_amount OR ($method->min_amount <= $amount AND ($method->max_amount == 0) ));
	
		$countries = array();
		if (!empty($method->countries)) {
		    if (!is_array($method->countries)) {
				$countries[0] = $method->countries;
		    } else {
				$countries = $method->countries;
		    }
		}
		// probably did not gave his BT:ST address
		if (!is_array($address)) {
		    $address = array();
		    $address['virtuemart_country_id'] = 0;
		}
	
		if (!isset($address['virtuemart_country_id'])){
		    $address['virtuemart_country_id'] = 0;
		}
		if (in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0) {
		    if ($amount_cond) {
				return true;
		    }
		}

		return false;
    }


	/**
	 * We must reimplement this triggers for joomla 1.7
	 */

	/**
	 * Create the table for this plugin if it does not yet exist.
	 * This functions checks if the called plugin is active one.
	 * When yes it is calling the standard method to create the tables
	 *
	 * @author Valérie Isaksen
	 *
	 */
	function plgVmOnStoreInstallPaymentPluginTable ($jplugin_id) {

		return $this->onStoreInstallPluginTable ($jplugin_id);
	}

	/**
	 * This event is fired after the payment method has been selected. It can be used to store
	 * additional payment info in the cart.
	 *
	 * @author Max Milbers
	 * @author Valérie isaksen
	 *
	 * @param VirtueMartCart $cart: the actual cart
	 * @return null if the payment was not selected, true if the data is valid, error message if the data is not valid
	 *
	 */
	public function plgVmOnSelectCheckPayment (VirtueMartCart $cart,  &$msg) {

		return $this->OnSelectCheck ($cart);
	}

	/**
	 * plgVmDisplayListFEPayment
	 * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
	 *
	 * @param object  $cart Cart object
	 * @param integer $selected ID of the method selected
	 * @return boolean True on success, false on failures, null when this plugin was not selected.
	 * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
	 *
	 * @author Valerie Isaksen
	 * @author Max Milbers
	 */
	public function plgVmDisplayListFEPayment (VirtueMartCart $cart, $selected = 0, &$htmlIn) {

		return $this->displayListFE ($cart, $selected, $htmlIn);
	}


	public function plgVmonSelectedCalculatePricePayment (VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {

		return $this->onSelectedCalculatePrice ($cart, $cart_prices, $cart_prices_name);
	}

	/**
	 * plgVmOnCheckAutomaticSelectedPayment
	 * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
	 * The plugin must check first if it is the correct type
	 *
	 * @author Valerie Isaksen
	 * @param VirtueMartCart cart: the cart object
	 * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
	 *
	 */
	function plgVmOnCheckAutomaticSelectedPayment (VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter) {

		return $this->onCheckAutomaticSelected ($cart, $cart_prices, $paymentCounter);
	}

	/**
	 * This method is fired when showing the order details in the frontend.
	 * It displays the method-specific data.
	 *
	 * @param integer $order_id The order ID
	 * @return mixed Null for methods that aren't active, text (HTML) otherwise
	 * @author Max Milbers
	 * @author Valerie Isaksen
	 */
	public function plgVmOnShowOrderFEPayment ($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {

		$this->onShowOrderFE ($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
	}

	/**
	 * This event is fired during the checkout process. It can be used to validate the
	 * method data as entered by the user.
	 *
	 * @return boolean True when the data was valid, false otherwise. If the plugin is not activated, it should return null.
	 * @author Max Milbers

	public function plgVmOnCheckoutCheckDataPayment($psType, VirtueMartCart $cart) {
	return null;
	}
	 */

	/**
	 * This method is fired when showing when priting an Order
	 * It displays the the payment method-specific data.
	 *
	 * @param integer $_virtuemart_order_id The order ID
	 * @param integer $method_id  method used for this order
	 * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
	 * @author Valerie Isaksen
	 */
	function plgVmonShowOrderPrintPayment ($order_number, $method_id) {

		return $this->onShowOrderPrint ($order_number, $method_id);
	}

	/**
	 * Save updated order data to the method specific table
	 *
	 * @param array $_formData Form data
	 * @return mixed, True on success, false on failures (the rest of the save-process will be
	 * skipped!), or null when this method is not activated.

	public function plgVmOnUpdateOrderPayment(  $_formData) {
	return null;
	}
	 */
	/**
	 * Save updated orderline data to the method specific table
	 *
	 * @param array $_formData Form data
	 * @return mixed, True on success, false on failures (the rest of the save-process will be
	 * skipped!), or null when this method is not actived.

	public function plgVmOnUpdateOrderLine(  $_formData) {
	return null;
	}
	 */
	/**
	 * plgVmOnEditOrderLineBE
	 * This method is fired when editing the order line details in the backend.
	 * It can be used to add line specific package codes
	 *
	 * @param integer $_orderId The order ID
	 * @param integer $_lineId
	 * @return mixed Null for method that aren't active, text (HTML) otherwise

	public function plgVmOnEditOrderLineBE(  $_orderId, $_lineId) {
	return null;
	}
	 */

	/**
	 * This method is fired when showing the order details in the frontend, for every orderline.
	 * It can be used to display line specific package codes, e.g. with a link to external tracking and
	 * tracing systems
	 *
	 * @param integer $_orderId The order ID
	 * @param integer $_lineId
	 * @return mixed Null for method that aren't active, text (HTML) otherwise

	public function plgVmOnShowOrderLineFE(  $_orderId, $_lineId) {
	return null;
	}
	 */
	function plgVmDeclarePluginParamsPaymentVM3( &$data) {
		return $this->declarePluginParams('payment', $data);
	}

	function plgVmSetOnTablePluginParamsPayment ($name, $id, &$table) {

		return $this->setOnTablePluginParams ($name, $id, $table);
	}

	function _setBillingInfo($b){
		$country2Code = ShopFunctions::getCountryByID($b->virtuemart_country_id,'country_2_code');
		$phoneCode = isset($b->virtuemart_country_id) ? $this->getCountryPhoneCode($country2Code) : '';
		return array(
				'threeDSCustomerEmail' => isset($b->email) ?  $b->email : '',
				'threeDSDeliveryEmail' => isset($b->email) ?  $b->email : '',
				'threeDSMobilePhoneCountryCode' => $phoneCode,
				'threeDSHomePhoneCountryCode' => $phoneCode,
				'threeDSWorkPhoneCountryCode' => $phoneCode,
				'threeDSWorkPhoneNumber' => isset($b->phone_1) ? $this->getAllNum($b->phone_1) : '',
				'threeDSHomePhoneNumber' => isset($b->phone_1) ? $this->getAllNum($b->phone_1) : '',
				'threeDSMobilePhoneNumber' => isset($b->phone_2) ? $this->getAllNum($b->phone_2) : '',
				//billing address related
				'threeDSBillingCountryCode' => isset($b->virtuemart_country_id) ? $this->getCountryCodeNumeric($country2Code) : '',
				'threeDSBillingState' => isset($b->virtuemart_country_id) ? ShopFunctions::getStateByID($b->virtuemart_state_id,'state_2_code') : '',
				'threeDSBillingCity' => isset($b->city) ? $b->city : '',
				'threeDSBillingLine1' => isset($b->address_1) ? $b->address_1 : '',
				'threeDSBillingLine2' => isset($b->address_2) ? $b->address_2 : '',
				'threeDSBillingPostalCode' => isset($b->zip) ? $b->zip : '',

		);
	}


	function _setShippingInfo($s){
		$country2Code = ShopFunctions::getCountryByID($s->virtuemart_country_id,'country_2_code');
		$phoneCode = isset($s->virtuemart_country_id) ? $this->getCountryPhoneCode($country2Code) : '';
		return array(
				// 'threeDSCustomerEmail' => isset($s->email) ?  $s->email : '',

				//billing address related
				'threeDSShippingCountryCode' => isset($s->virtuemart_country_id) ? $this->getCountryCodeNumeric($country2Code) : '',
				'threeDSShippingState' => isset($s->virtuemart_country_id) ? ShopFunctions::getStateByID($s->virtuemart_state_id,'state_2_code') : '',
				'threeDSShippingCity' => isset($s->city) ? $s->city : '',
				'threeDSShippingLine1' => isset($s->address_1) ? $s->address_1 : '',
				'threeDSShippingLine2' => isset($s->address_2) ? $s->address_2 : '',
				'threeDSShippingPostalCode' => isset($s->zip) ? $s->zip : '',

		);
	}

	function isSameBillShipAddress($b,$s){


		$cnt = 0;

		if($b['threeDSBillingCountryCode'] == $s['threeDSShippingCountryCode'])$cnt++;
		if($b['threeDSBillingLine1'] == $s['threeDSShippingLine1'])$cnt++;
		if($b['threeDSBillingLine2'] == $s['threeDSShippingLine2'])$cnt++;
		if($b['threeDSBillingState'] == $s['threeDSShippingState'])$cnt++;
		if($b['threeDSBillingCity'] == $s['threeDSShippingCity'])$cnt++;
		if($b['threeDSBillingPostalCode'] == $s['threeDSShippingPostalCode'])$cnt++;


		if($cnt==6)return "T";
		else return "F";



	}

	function getAllNum($n){
		return preg_replace('/\D/', '',$n);
	}

	function getAccountInfo(){

		$b=JFactory::getUser();
		$acctMethod = "01";
		
    	if($b->id > 0){
	    		
			$dte_add = date('Ymd' ,strtotime($b->registerDate));
			// $dte_upd = date('Ymd' ,strtotime($b->modified_on));

			$dteAdd_diff = $this->getDateDiff($dte_add);
	   		// $dteUpd_diff = $this->getDateDiff($dte_upd);

	   		$dteAddAge = $this->getAcctAgeInd($dteAdd_diff);
	   		// $dteUpdAge = $this->getAcctAgeInd($dteUpd_diff,TRUE);
	   		$acctMethod = "02";
    	}
	
   		return array(
   			'threeDSAcctCreateDate' => $dte_add,
   			// 'threeDSAcctLastChangeDate' => $dte_upd,
   			'threeDSAcctAgeInd' => $dteAddAge,
   			// 'threeDSAcctLastChangeInd' => $dteUpdAge,
   			'threeDSAcctAuthTimestamp' => isset($b->lastvisitDate) ?gmdate("Ymd" ,strtotime($b->lastvisitDate)) : '',
   			'threeDSAcctAuthMethod' => $acctMethod,
   		);

	}

	function getDateDiff($d){
	    		$datenow = date('Ymd');
				$dt1 = new \DateTime($datenow);
				$dt2 = new \DateTime($d);
				$interval = $dt1->diff($dt2)->format('%a');
				return $interval;
	    }

	function getAcctAgeInd($d, $isUpDate = FALSE){
	    	switch ($d) {
	    		case 0:
	    			# code...
	    			$ret = "02";
	    			if($isUpDate)$ret = "01";
	    			break;
	    		case $d<30:
	    			# code...
	    			$ret = "03";
	    			if($isUpDate)$ret = "02";
	    			break;
	    		case $d>30 && $d<60:
	    			# code...
	    			$ret = "04";
	    			if($isUpDate)$ret = "03";
	    			break;
	    		case $d>60:
	    			$ret = "05"	;
	    			if($isUpDate)$ret = "04";
					break;	
	    		default:
	    			# code...
	    			break;
	    	}
	    	return $ret;

	    }

	function getUserOrders(){
		$u=JFactory::getUser();
		$uid = $u->id;
		if (!class_exists('VirtueMartModelOrders')){
    	    require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
    	}
    	$modelOrder = new VirtueMartModelOrders();
    	
    	if($uid>0){
    		$customerOrders = $modelOrder->getOrdersList($uid, TRUE);

	    	$timeQ24 = date('Y-m-d H:i:s', strtotime("-1 day"));
			$timeQ6 = date('Y-m-d H:i:s', strtotime("-6 months"));
			$timeQ1 = date('Y-m-d H:i:s', strtotime("-1 year"));
			$countOrderAnyDay = $countOrder = $countOrderAnyYear = 0;

	    	foreach ($customerOrders as $objOrder) {

	    		$dte6 = date('Ymd H:i:s' ,strtotime($objOrder->paid_on));

				$dte = date('Ymd H:i:s' ,strtotime($objOrder->modified_on));

				if($dte >= $timeQ24)$countOrderAnyDay++;
				if($dte6 >= $timeQ6)$countOrder++;
				if($dte >= $timeQ1)$countOrderAnyYear++;

	    	}
    	}
    	

    	return array(
    			'threeDSAcctPurchaseCount' => isset($countOrder) ? (int)($countOrder) : '',
				'threeDSAcctNumTransDay' => isset($countOrderAnyDay) ? (int)($countOrderAnyDay) : '',
				'threeDSAcctNumTransYear' => isset($countOrderAnyYear) ? (int)($countOrderAnyYear) : '',
    	);


	}

	function getCountryCodeNumeric($code){
		$countrycode = array('AF'=>'4','AL'=>'8','DZ'=>'12','AS'=>'16','AD'=>'20','AO'=>'24','AI'=>'660','AQ'=>'10','AG'=>'28','AR'=>'32','AM'=>'51','AW'=>'533','AU'=>'36','AT'=>'40','AZ'=>'31','BS'=>'44','BH'=>'48','BD'=>'50','BB'=>'52','BY'=>'112','BE'=>'56','BZ'=>'84','BJ'=>'204','BM'=>'60','BT'=>'64','BO'=>'68','BO'=>'68','BA'=>'70','BW'=>'72','BV'=>'74','BR'=>'76','IO'=>'86','BN'=>'96','BN'=>'96','BG'=>'100','BF'=>'854','BI'=>'108','KH'=>'116','CM'=>'120','CA'=>'124','CV'=>'132','KY'=>'136','CF'=>'140','TD'=>'148','CL'=>'152','CN'=>'156','CX'=>'162','CC'=>'166','CO'=>'170','KM'=>'174','CG'=>'178','CD'=>'180','CK'=>'184','CR'=>'188','CI'=>'384','CI'=>'384','HR'=>'191','CU'=>'192','CY'=>'196','CZ'=>'203','DK'=>'208','DJ'=>'262','DM'=>'212','DO'=>'214','EC'=>'218','EG'=>'818','SV'=>'222','GQ'=>'226','ER'=>'232','EE'=>'233','ET'=>'231','FK'=>'238','FO'=>'234','FJ'=>'242','FI'=>'246','FR'=>'250','GF'=>'254','PF'=>'258','TF'=>'260','GA'=>'266','GM'=>'270','GE'=>'268','DE'=>'276','GH'=>'288','GI'=>'292','GR'=>'300','GL'=>'304','GD'=>'308','GP'=>'312','GU'=>'316','GT'=>'320','GG'=>'831','GN'=>'324','GW'=>'624','GY'=>'328','HT'=>'332','HM'=>'334','VA'=>'336','HN'=>'340','HK'=>'344','HU'=>'348','IS'=>'352','IN'=>'356','ID'=>'360','IR'=>'364','IQ'=>'368','IE'=>'372','IM'=>'833','IL'=>'376','IT'=>'380','JM'=>'388','JP'=>'392','JE'=>'832','JO'=>'400','KZ'=>'398','KE'=>'404','KI'=>'296','KP'=>'408','KR'=>'410','KR'=>'410','KW'=>'414','KG'=>'417','LA'=>'418','LV'=>'428','LB'=>'422','LS'=>'426','LR'=>'430','LY'=>'434','LY'=>'434','LI'=>'438','LT'=>'440','LU'=>'442','MO'=>'446','MK'=>'807','MG'=>'450','MW'=>'454','MY'=>'458','MV'=>'462','ML'=>'466','MT'=>'470','MH'=>'584','MQ'=>'474','MR'=>'478','MU'=>'480','YT'=>'175','MX'=>'484','FM'=>'583','MD'=>'498','MC'=>'492','MN'=>'496','ME'=>'499','MS'=>'500','MA'=>'504','MZ'=>'508','MM'=>'104','MM'=>'104','NA'=>'516','NR'=>'520','NP'=>'524','NL'=>'528','AN'=>'530','NC'=>'540','NZ'=>'554','NI'=>'558','NE'=>'562','NG'=>'566','NU'=>'570','NF'=>'574','MP'=>'580','NO'=>'578','OM'=>'512','PK'=>'586','PW'=>'585','PS'=>'275','PA'=>'591','PG'=>'598','PY'=>'600','PE'=>'604','PH'=>'608','PN'=>'612','PL'=>'616','PT'=>'620','PR'=>'630','QA'=>'634','RE'=>'638','RO'=>'642','RU'=>'643','RU'=>'643','RW'=>'646','SH'=>'654','KN'=>'659','LC'=>'662','PM'=>'666','VC'=>'670','VC'=>'670','VC'=>'670','WS'=>'882','SM'=>'674','ST'=>'678','SA'=>'682','SN'=>'686','RS'=>'688','SC'=>'690','SL'=>'694','SG'=>'702','SK'=>'703','SI'=>'705','SB'=>'90','SO'=>'706','ZA'=>'710','GS'=>'239','ES'=>'724','LK'=>'144','SD'=>'736','SR'=>'740','SJ'=>'744','SZ'=>'748','SE'=>'752','CH'=>'756','SY'=>'760','TW'=>'158','TW'=>'158','TJ'=>'762','TZ'=>'834','TH'=>'764','TL'=>'626','TG'=>'768','TK'=>'772','TO'=>'776','TT'=>'780','TT'=>'780','TN'=>'788','TR'=>'792','TM'=>'795','TC'=>'796','TV'=>'798','UG'=>'800','UA'=>'804','AE'=>'784','GB'=>'826','US'=>'840','UM'=>'581','UY'=>'858','UZ'=>'860','VU'=>'548','VE'=>'862','VE'=>'862','VN'=>'704','VN'=>'704','VG'=>'92','VI'=>'850','WF'=>'876','EH'=>'732','YE'=>'887','ZM'=>'894','ZW'=>'716');
		return $countrycode[$code];

	}

	function getCountryPhoneCode($c){
		$countrycode = array('AD'=>'376','AE'=>'971','AF'=>'93','AG'=>'1268','AI'=>'1264','AL'=>'355','AM'=>'374','AN'=>'599','AO'=>'244','AQ'=>'672','AR'=>'54','AS'=>'1684','AT'=>'43','AU'=>'61','AW'=>'297','AZ'=>'994','BA'=>'387','BB'=>'1246','BD'=>'880','BE'=>'32','BF'=>'226','BG'=>'359','BH'=>'973','BI'=>'257','BJ'=>'229','BL'=>'590','BM'=>'1441','BN'=>'673','BO'=>'591','BR'=>'55','BS'=>'1242','BT'=>'975','BW'=>'267','BY'=>'375','BZ'=>'501','CA'=>'1','CC'=>'61','CD'=>'243','CF'=>'236','CG'=>'242','CH'=>'41','CI'=>'225','CK'=>'682','CL'=>'56','CM'=>'237','CN'=>'86','CO'=>'57','CR'=>'506','CU'=>'53','CV'=>'238','CX'=>'61','CY'=>'357','CZ'=>'420','DE'=>'49','DJ'=>'253','DK'=>'45','DM'=>'1767','DO'=>'1809','DZ'=>'213','EC'=>'593','EE'=>'372','EG'=>'20','ER'=>'291','ES'=>'34','ET'=>'251','FI'=>'358','FJ'=>'679','FK'=>'500','FM'=>'691','FO'=>'298','FR'=>'33','GA'=>'241','GB'=>'44','GD'=>'1473','GE'=>'995','GH'=>'233','GI'=>'350','GL'=>'299','GM'=>'220','GN'=>'224','GQ'=>'240','GR'=>'30','GT'=>'502','GU'=>'1671','GW'=>'245','GY'=>'592','HK'=>'852','HN'=>'504','HR'=>'385','HT'=>'509','HU'=>'36','ID'=>'62','IE'=>'353','IL'=>'972','IM'=>'44','IN'=>'91','IQ'=>'964','IR'=>'98','IS'=>'354','IT'=>'39','JM'=>'1876','JO'=>'962','JP'=>'81','KE'=>'254','KG'=>'996','KH'=>'855','KI'=>'686','KM'=>'269','KN'=>'1869','KP'=>'850','KR'=>'82','KW'=>'965','KY'=>'1345','KZ'=>'7','LA'=>'856','LB'=>'961','LC'=>'1758','LI'=>'423','LK'=>'94','LR'=>'231','LS'=>'266','LT'=>'370','LU'=>'352','LV'=>'371','LY'=>'218','MA'=>'212','MC'=>'377','MD'=>'373','ME'=>'382','MF'=>'1599','MG'=>'261','MH'=>'692','MK'=>'389','ML'=>'223','MM'=>'95','MN'=>'976','MO'=>'853','MP'=>'1670','MR'=>'222','MS'=>'1664','MT'=>'356','MU'=>'230','MV'=>'960','MW'=>'265','MX'=>'52','MY'=>'60','MZ'=>'258','NA'=>'264','NC'=>'687','NE'=>'227','NG'=>'234','NI'=>'505','NL'=>'31','NO'=>'47','NP'=>'977','NR'=>'674','NU'=>'683','NZ'=>'64','OM'=>'968','PA'=>'507','PE'=>'51','PF'=>'689','PG'=>'675','PH'=>'63','PK'=>'92','PL'=>'48','PM'=>'508','PN'=>'870','PR'=>'1','PT'=>'351','PW'=>'680','PY'=>'595','QA'=>'974','RO'=>'40','RS'=>'381','RU'=>'7','RW'=>'250','SA'=>'966','SB'=>'677','SC'=>'248','SD'=>'249','SE'=>'46','SG'=>'65','SH'=>'290','SI'=>'386','SK'=>'421','SL'=>'232','SM'=>'378','SN'=>'221','SO'=>'252','SR'=>'597','ST'=>'239','SV'=>'503','SY'=>'963','SZ'=>'268','TC'=>'1649','TD'=>'235','TG'=>'228','TH'=>'66','TJ'=>'992','TK'=>'690','TL'=>'670','TM'=>'993','TN'=>'216','TO'=>'676','TR'=>'90','TT'=>'1868','TV'=>'688','TW'=>'886','TZ'=>'255','UA'=>'380','UG'=>'256','US'=>'1','UY'=>'598','UZ'=>'998','VA'=>'39','VC'=>'1784','VE'=>'58','VG'=>'1284','VI'=>'1340','VN'=>'84','VU'=>'678','WF'=>'681','WS'=>'685','XK'=>'381','YE'=>'967','YT'=>'262','ZA'=>'27','ZM'=>'260','ZW'=>'263');

		return $countrycode[$c];
	}

} // end of class plgVmpaymentSkrill

// No closing tag
