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
    		'asiapay_secure_hash_secret' 	=> array('', 'char')
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
		if($method->asiapay_secure_hash_secret != ''){
			$secureHash = $this -> generatePaymentSecureHash($merchantId, $orderRef, $currency_numeric_code, $amount, $payType, $method->asiapay_secure_hash_secret);
		}
		$remark = $session->getId(); //sessionid
    	
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
    	);
    
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

} // end of class plgVmpaymentSkrill

// No closing tag
