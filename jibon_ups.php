<?php

defined ('_JEXEC') or die('Restricted access');

/**
 * Shipment plugin for UPS shipments
 *
 * @ Plugins - shipment
 * @ Author: Jibon Lawrence Costa
 * @ email: jiboncosta57@gmail.com
 * @copyright Copyright (C) 2004-2012 Hoicoimasti.com - All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 *
 */
if (!class_exists ('vmPSPlugin')) {
	require_once(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}
if (!class_exists ('ups')) {
	require_once(JPATH_ROOT."/plugins/vmshipment/jibon_ups/ups/classes/class.ups.php");
}
if (!class_exists ('upsRate')) {
	require_once(JPATH_ROOT."/plugins/vmshipment/jibon_ups/ups/classes/class.upsRate.php");
}
if (!class_exists('CurrencyDisplay')) {
	require_once(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php');
}

class plgVmShipmentJibon_ups extends vmPSPlugin {
	
	var $method;
	private $ups_rate;
	private $ups_service_name;
	private $ups_service_id;
	private $vendor;
	var $UPSresponse = array();
	var $status = 1;

	/**
	 * @param object $subject
	 * @param array  $config
	 */
	function __construct (& $subject, $config) {

		parent::__construct ($subject, $config);

		$this->_loggable = TRUE;
		$this->_tablepkey = 'id';
		$this->_tableId = 'id';
		$this->tableFields = array_keys ($this->getTableSQLFields ());
		$varsToPush = $this->getVarsToPush ();
		$this->setConfigParameterable ($this->_configTableFieldName, $varsToPush);
		//vmdebug('Muh constructed plgVmShipmentWeight_countries',$varsToPush);
	}

	/**
	 * Create the table for this plugin if it does not yet exist.
	 *
	 * @author Valérie Isaksen
	 */
	public function getVmPluginCreateTableSQL () {

		return $this->createTableSQL ('Shipment Weight Countries Table');
	}

	/**
	 * @return array
	 */
	function getTableSQLFields () {

		$SQLfields = array(
			'id'                           => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id'          => 'int(11) UNSIGNED',
			'order_number'                 => 'char(32)',
			'virtuemart_shipmentmethod_id' => 'mediumint(1) UNSIGNED',
			'shipment_name'                => 'varchar(5000)',
			'order_weight'                 => 'decimal(10,4)',
			'shipment_weight_unit'         => 'char(3) DEFAULT \'KG\'',
			'shipment_cost'                => 'decimal(10,2)',
			'shipment_package_fee'         => 'decimal(10,2)',
			'tax_id'                       => 'smallint(1)'
		);
		return $SQLfields;
	}

	/**
	 * This method is fired when showing the order details in the frontend.
	 * It displays the shipment-specific data.
	 *
	 * @param integer $virtuemart_order_id The order ID
	 * @param integer $virtuemart_shipmentmethod_id The selected shipment method id
	 * @param string  $shipment_name Shipment Name
	 * @return mixed Null for shipments that aren't active, text (HTML) otherwise
	 * @author Valérie Isaksen
	 * @author Max Milbers
	 */
	public function plgVmOnShowOrderFEShipment ($virtuemart_order_id, $virtuemart_shipmentmethod_id, &$shipment_name) {

		$this->onShowOrderFE ($virtuemart_order_id, $virtuemart_shipmentmethod_id, $shipment_name);
	}

	/**
	 * This event is fired after the order has been stored; it gets the shipment method-
	 * specific data.
	 *
	 * @param int    $order_id The order_id being processed
	 * @param object $cart  the cart
	 * @param array  $order The actual order saved in the DB
	 * @return mixed Null when this method was not selected, otherwise true
	 * @author Valerie Isaksen
	 */
	function plgVmConfirmedOrder (VirtueMartCart $cart, $order) {

		if (!($method = $this->getVmPluginMethod ($order['details']['BT']->virtuemart_shipmentmethod_id))) {
			return NULL; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement ($method->shipment_element)) {
			return FALSE;
		}
		$values['virtuemart_order_id'] = $order['details']['BT']->virtuemart_order_id;
		$values['order_number'] = $order['details']['BT']->order_number;
		$values['virtuemart_shipmentmethod_id'] = $order['details']['BT']->virtuemart_shipmentmethod_id;
		$values['shipment_name'] = $this->renderPluginName ($method);
		$values['order_weight'] = $this->getOrderWeight ($cart, $method->weight_unit);
		$values['shipment_weight_unit'] = $method->weight_unit;

		$costs = $this->getCosts($cart,$method,$cart->cartPrices);
		if(empty($costs)){
			$values['shipment_cost'] = 0;
			$values['shipment_package_fee'] = 0;
		} else {
			$values['shipment_cost'] = $this->ups_rate;
			$values['shipment_package_fee'] = $method->package_fee;
		}

		$values['tax_id'] = $method->tax_id;
		$this->storePSPluginInternalData ($values);
		$this->clear();

		return TRUE;
	}

	/**
	 * This method is fired when showing the order details in the backend.
	 * It displays the shipment-specific data.
	 * NOTE, this plugin should NOT be used to display form fields, since it's called outside
	 * a form! Use plgVmOnUpdateOrderBE() instead!
	 *
	 * @param integer $virtuemart_order_id The order ID
	 * @param integer $virtuemart_shipmentmethod_id The order shipment method ID
	 * @param object  $_shipInfo Object with the properties 'shipment' and 'name'
	 * @return mixed Null for shipments that aren't active, text (HTML) otherwise
	 * @author Valerie Isaksen
	 */
	public function plgVmOnShowOrderBEShipment ($virtuemart_order_id, $virtuemart_shipmentmethod_id) {

		if (!($this->selectedThisByMethodId ($virtuemart_shipmentmethod_id))) {
			return NULL;
		}
		$html = $this->getOrderShipmentHtml ($virtuemart_order_id);
		return $html;
	}

	/**
	 * @param $virtuemart_order_id
	 * @return string
	 */
	function getOrderShipmentHtml ($virtuemart_order_id) {

		$db = JFactory::getDBO ();
		$q = 'SELECT * FROM `' . $this->_tablename . '` '
			. 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
		$db->setQuery ($q);
		if (!($shipinfo = $db->loadObject ())) {
			vmWarn (500, $q . " " . $db->getErrorMsg ());
			return '';
		}

		if (!class_exists ('CurrencyDisplay')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php');
		}

		$currency = CurrencyDisplay::getInstance ();
		$tax = ShopFunctions::getTaxByID ($shipinfo->tax_id);
		$taxDisplay = is_array ($tax) ? $tax['calc_value'] . ' ' . $tax['calc_value_mathop'] : $shipinfo->tax_id;
		$taxDisplay = ($taxDisplay == -1) ? vmText::_ ('COM_VIRTUEMART_PRODUCT_TAX_NONE') : $taxDisplay;

		$html = '<table class="adminlist table">' . "\n";
		$html .= $this->getHtmlHeaderBE ();
		$html .= $this->getHtmlRowBE ('WEIGHT_COUNTRIES_SHIPPING_NAME', $shipinfo->shipment_name);
		$html .= $this->getHtmlRowBE ('WEIGHT_COUNTRIES_WEIGHT', $shipinfo->order_weight . ' ' . ShopFunctions::renderWeightUnit ($shipinfo->shipment_weight_unit));
		$html .= $this->getHtmlRowBE ('WEIGHT_COUNTRIES_COST', $currency->priceDisplay ($shipinfo->shipment_cost));
		$html .= $this->getHtmlRowBE ('WEIGHT_COUNTRIES_PACKAGE_FEE', $currency->priceDisplay ($shipinfo->shipment_package_fee));
		$html .= $this->getHtmlRowBE ('WEIGHT_COUNTRIES_TAX', $taxDisplay);
		$html .= '</table>' . "\n";

		return $html;
	}

	/**
	 * @param VirtueMartCart $cart
	 * @param                $method
	 * @param                $cart_prices
	 * @return int
	 */
	function getCosts (VirtueMartCart $cart, $method, $cart_prices) {
		$this->getUPSresponse($cart, $method);
		if ($method->free_shipment && $cart_prices['salesPrice'] >= $method->free_shipment) {
			return 0.0;
		} else {
			return $this->ups_rate + $method->package_fee;
		}
	}

	/**
	 * @param \VirtueMartCart $cart
	 * @param int             $method
	 * @param array           $cart_prices
	 * @return bool
	 */
	protected function checkConditions ($cart, $method, $cart_prices) {

		static $result = array();
		$this->status = 1;
		if($cart->STsameAsBT == 0){
			$type = ($cart->ST == 0 ) ? 'BT' : 'ST';
		} else {
			$type = 'BT';
		}

		$address = $cart -> getST();

		if(!is_array($address)) $address = array();
		if(isset($cart_prices['salesPrice'])){
			$hashSalesPrice = $cart_prices['salesPrice'];
		} else {
			$hashSalesPrice = '';
		}


		if(empty($address['virtuemart_country_id'])) $address['virtuemart_country_id'] = 0;
		if(empty($address['zip'])) $address['zip'] = 0;

		$hash = $method->virtuemart_shipmentmethod_id.$type.$address['virtuemart_country_id'].'_'.$address['zip'].'_'.$hashSalesPrice;

		if(isset($result[$hash])){
			return $result[$hash];
		}
		$this->convert ($method);
		$orderWeight = $this->getOrderWeight ($cart, $method->weight_unit);

		$countries = array();
		if (!empty($method->countries)) {
			if (!is_array ($method->countries)) {
				$countries[0] = $method->countries;
			} else {
				$countries = $method->countries;
			}
		}


		$weight_cond = $this->testRange($orderWeight,$method,'weight_start','weight_stop','weight');
		$nbproducts_cond = $this->_nbproductsCond ($cart, $method);

		if(isset($cart_prices['salesPrice'])){
			$orderamount_cond = $this->testRange($cart_prices['salesPrice'],$method,'orderamount_start','orderamount_stop','order amount');
		} else {
			$orderamount_cond = FALSE;
		}

		$userFieldsModel =VmModel::getModel('Userfields');
		if ($userFieldsModel->fieldPublished('zip', $type)){
			if (!isset($address['zip'])) {
				$address['zip'] = '';
			}
			$zip_cond = $this->testRange($address['zip'],$method,'zip_start','zip_stop','zip');
		} else {
			$zip_cond = true;
		}

		if ($userFieldsModel->fieldPublished('virtuemart_country_id', $type)){

			if (!isset($address['virtuemart_country_id'])) {
				$address['virtuemart_country_id'] = 0;
			}

			if (in_array ($address['virtuemart_country_id'], $countries) || count ($countries) == 0) {

				//vmdebug('checkConditions '.$method->shipment_name.' fit ',$weight_cond,(int)$zip_cond,$nbproducts_cond,$orderamount_cond);
				vmdebug('shipmentmethod '.$method->shipment_name.' = TRUE for variable virtuemart_country_id = '.$address['virtuemart_country_id'].', Reason: Countries in rule '.implode($countries,', ').' or none set');
				$country_cond = true;
			}
			else{
				vmdebug('shipmentmethod '.$method->shipment_name.' = FALSE for variable virtuemart_country_id = '.$address['virtuemart_country_id'].', Reason: Country '.implode($countries,', ').' does not fit');
				$country_cond = false;
			}
		} else {
			vmdebug('shipmentmethod '.$method->shipment_name.' = TRUE for variable virtuemart_country_id, Reason: no boundary conditions set');
			$country_cond = true;
		}
		$this->getUPSresponse($cart, $method);
		
		if ($this->status > 0) {
			$ups_status = true;
		} else {
			$ups_status = false;
		}		

		$allconditions = (int) $weight_cond + (int)$zip_cond + (int)$nbproducts_cond + (int)$orderamount_cond + (int)$country_cond + (int)$ups_status;
		if($allconditions === 6){
			$result[$hash] = true;
			return TRUE;
		} else {
			$result[$hash] = false;
			//vmdebug('checkConditions '.$method->shipment_name.' does not fit ',(int)$weight_cond,(int)$zip_cond,(int)$nbproducts_cond,(int)$orderamount_cond,(int)$country_cond);
			return FALSE;
		}

		$result[$hash] = false;
		return FALSE;
	}

	/**
	 * @param $method
	 */
	function convert (&$method) {

		//$method->weight_start = (float) $method->weight_start;
		//$method->weight_stop = (float) $method->weight_stop;
		$method->orderamount_start =  (float)str_replace(',','.',$method->orderamount_start);
		$method->orderamount_stop =   (float)str_replace(',','.',$method->orderamount_stop);
		$method->zip_start = (int)$method->zip_start;
		$method->zip_stop = (int)$method->zip_stop;
		$method->nbproducts_start = (int)$method->nbproducts_start;
		$method->nbproducts_stop = (int)$method->nbproducts_stop;
		$method->free_shipment = (float)str_replace(',','.',$method->free_shipment);
	}

	/**
	 * @param $cart
	 * @param $method
	 * @return bool
	 */
	private function _nbproductsCond ($cart, $method) {

		if (empty($method->nbproducts_start) and empty($method->nbproducts_stop)) {
			//vmdebug('_nbproductsCond',$method);
			return true;
		}

		$nbproducts = 0;
		foreach ($cart->products as $product) {
			$nbproducts += $product->quantity;
		}

		if ($nbproducts) {

			$nbproducts_cond = $this->testRange($nbproducts,$method,'nbproducts_start','nbproducts_stop','products quantity');

		} else {
			$nbproducts_cond = false;
		}

		return $nbproducts_cond;
	}


	private function testRange($value, $method, $floor, $ceiling,$name){

		$cond = true;
		if(!empty($method->$floor) and !empty($method->$ceiling)){
			$cond = (($value >= $method->$floor AND $value <= $method->$ceiling));
			if(!$cond){
				$result = 'FALSE';
				$reason = 'is NOT within Range of the condition from '.$method->$floor.' to '.$method->$ceiling;
			} else {
				$result = 'TRUE';
				$reason = 'is within Range of the condition from '.$method->$floor.' to '.$method->$ceiling;
			}
		} else if(!empty($method->$floor)){
			$cond = ($value >= $method->$floor);
			if(!$cond){
				$result = 'FALSE';
				$reason = 'is not at least '.$method->$floor;
			} else {
				$result = 'TRUE';
				$reason = 'is over min limit '.$method->$floor;
			}
		} else if(!empty($method->$ceiling)){
			$cond = ($value <= $method->$ceiling);
			if(!$cond){
				$result = 'FALSE';
				$reason = 'is over '.$method->$ceiling;
			} else {
				$result = 'TRUE';
				$reason = 'is lower than the set '.$method->$ceiling;
			}
		} else {
			$result = 'TRUE';
			$reason = 'no boundary conditions set';
		}

		vmdebug('shipmentmethod '.$method->shipment_name.' = '.$result.' for variable '.$name.' = '.$value.' Reason: '.$reason);
		return $cond;
	}


	function plgVmOnProductDisplayShipment($product, &$productDisplayShipments){

		if ($this->getPluginMethods($product->virtuemart_vendor_id) === 0) {

			return FALSE;
		}
		if (!class_exists('VirtueMartCart'))
			require(VMPATH_SITE . DS . 'helpers' . DS . 'cart.php');

		$html = '';
		if (!class_exists('CurrencyDisplay'))
			require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php');
		$currency = CurrencyDisplay::getInstance();
		$this->getPost();

		foreach ($this->methods as $this->_currentMethod) {

			if($this->_currentMethod->show_on_pdetails && !empty($this->ups_rate)){
				if(!isset($cart)){
					$cart = VirtueMartCart::getCart();
					$cart->prepareCartData();
				}
				$prices=array('salesPrice'=>0.0);
				if(isset($cart->cartPrices)){
					$prices['salesPrice'] = $cart->cartPrices['salesPrice'];
				}
				if(isset($product->prices)){
					$prices['salesPrice'] += $product->prices['salesPrice'];
				}

				if($this->checkConditions($cart,$this->_currentMethod,$prices,$product)){

					$product->prices['shipmentPrice'] = $this->getCosts($cart,$this->_currentMethod,$cart->cartPrices);

					if(isset($product->prices['VatTax']) and count($product->prices['VatTax'])>0){
						reset($product->prices['VatTax']);
						$rule = current($product->prices['VatTax']);
						if(isset($rule[1])){
							$product->prices['shipmentTax'] = $product->prices['shipmentPrice'] * $rule[1]/100.0;
							$product->prices['shipmentPrice'] = $product->prices['shipmentPrice'] * (1 + $rule[1]/100.0);
						}
					}

					$html = $this->renderByLayout( 'default', array("method" => $this->_currentMethod, "cart" => $cart,"product" => $product,"currency" => $currency) );
				}
			}

		}

		$productDisplayShipments[] = $html;

	}

	/**
	 * Create the table for this plugin if it does not yet exist.
	 * This functions checks if the called plugin is active one.
	 * When yes it is calling the standard method to create the tables
	 *
	 * @author Valérie Isaksen
	 *
	 */
	function plgVmOnStoreInstallShipmentPluginTable ($jplugin_id) {

		return $this->onStoreInstallPluginTable ($jplugin_id);
	}

	/**
	 * @param VirtueMartCart $cart
	 * @return null
	 */
	public function plgVmOnSelectCheckShipment (VirtueMartCart &$cart) {
		
		if (!$this->selectedThisByMethodId($cart->virtuemart_shipmentmethod_id)) {
			return NULL; // Another method was selected, do nothing
		}

		if (!$this->method = $this->getVmPluginMethod($cart->virtuemart_shipmentmethod_id)) {
			return NULL; // Another method was selected, do nothing
		}
		$this->getPost();
		if (empty($this->ups_rate)){
			return NULL;
		}
		return true;

	}
	
	function plgVmOnCheckoutCheckDataShipment(VirtueMartCart $cart) {
		
		
		if (!$this->selectedThisByMethodId($cart->virtuemart_shipmentmethod_id)) {
			return NULL; // Another method was selected, do nothing
		}

		if (!$this->method = $this->getVmPluginMethod($cart->virtuemart_shipmentmethod_id)) {
			return NULL; // Another method was selected, do nothing
		}
		if (empty($this->ups_rate)){
			return NULL;
		}
		 
		$this->getPost();
		$this->loadPost($cart->virtuemart_shipmentmethod_id);
		return $this->plgVmOnSelectCheckShipment($cart);
	}
		
	public function loadPost($virtuemart_shipmentmethod_id) {
				
		$ups_rate = vRequest::getVar('ups_rate_'.$virtuemart_shipmentmethod_id);
		if ($ups_rate) {
			$this->ups_rate = $ups_rate;
		}
		
		$ups_service_name = vRequest::getVar('ups_name_'.$virtuemart_shipmentmethod_id);		
		if ($ups_service_name) {
			$this->ups_service_name = $ups_service_name;
		}
		
		$ups_service_id = vRequest::getVar('ups_id_'.$virtuemart_shipmentmethod_id);		
		if ($ups_service_id) {
			$this->ups_service_id = $ups_service_id;
		}
		$this->save();
	}
	
	public function save() {

		$session = JFactory::getSession();
		$sessionData = new stdClass();
		$sessionData->ups_rate = $this->ups_rate;
		$sessionData->ups_service_name = $this->ups_service_name;
		$sessionData->ups_service_id = $this->ups_service_id;
		$session->set('jibon_ups', json_encode($sessionData), 'vm');
	}
	
	public function getPost(){
		$session = JFactory::getSession();
		$getSession = $session->get('jibon_ups', 0, 'vm');
		$upsData = "";
		if (!empty($getSession)) {
			$upsData = (object)json_decode($getSession,true);
			$this->ups_rate = $upsData->ups_rate;
			$this->ups_service_name = $upsData->ups_service_name;	
			$this->ups_service_id = $upsData->ups_service_id;			
		}
		$this->save();
		return $upsData;
	}
	public function clear() {
		$this->ups_rate = "";
		$this->ups_service_name = "";	
		$this->ups_service_id = "";
		$this->save();
		$session = JFactory::getSession();
		$session->clear('jibon_ups', 'vm');
	}
	
	
	/**
	 * plgVmDisplayListFE
	 * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for example
	 *
	 * @param object  $cart Cart object
	 * @param integer $selected ID of the method selected
	 * @return boolean True on success, false on failures, null when this plugin was not selected.
	 * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
	 *
	 * @author Valerie Isaksen
	 * @author Max Milbers
	 */
	public function plgVmDisplayListFEShipment (VirtueMartCart $cart, $selected = 0, &$htmlIn) {

		if ($this->getPluginMethods($cart->vendorId) === 0) {
			if (empty($this->_name)) {
				$app = JFactory::getApplication();
				$app->enqueueMessage(vmText::_('COM_VIRTUEMART_CART_NO_' . strtoupper($this->_psType)));
				return FALSE;
			} else {
				return FALSE;
			}
		}
		$htmla = array();
		foreach ($this->methods as $this->method) {
			if ($this->checkConditions($cart, $this->method, $cart->pricesUnformatted)) {
				$this->getPost();				
				$this->getUPSresponse ($cart, $this->method);
				$response = $this->UPSresponse;
				$this->vendor = $cart->vendor->virtuemart_vendor_id;				
				$currency = CurrencyDisplay::getInstance();
				if (empty($this->method->service_name)){
					$service = array ("0" => "all");
				}else{
					$service = $this->method->service_name;
				}
				foreach ($response['RatingServiceSelectionResponse']['RatedShipment']  as $rate) {
					if (in_array($rate["Service"]["Code"]["VALUE"], $service, TRUE) or $service["0"] == "all") {
						if ($this->ups_service_id === $rate["Service"]["Code"]["VALUE"]){
							$checked = "checked=checked";
							$this->ups_rate = $currency->convertCurrencyTo("USD",$rate["TotalCharges"]["MonetaryValue"]["VALUE"]);
							$this->ups_service_name = $this->getServiceName($rate["Service"]["Code"]["VALUE"]);	
							$this->ups_service_id = $rate["Service"]["Code"]["VALUE"];
							$this->save();
						}else {
							$checked = "";
						}
						$html = '<input class="upsOption" type="radio" '.$checked.' id ="shipment_id_' . $this->method->virtuemart_shipmentmethod_id . '" service_id ="'.$rate["Service"]["Code"]["VALUE"].'" service_name ="'.$this->getServiceName($rate["Service"]["Code"]["VALUE"]).'" rate = "'.$currency->convertCurrencyTo("USD",$rate["TotalCharges"]["MonetaryValue"]["VALUE"]).'" name="virtuemart_shipmentmethod_id" value="'.$this->method->virtuemart_shipmentmethod_id.'"></input><label>'.$this->getServiceName($rate["Service"]["Code"]["VALUE"]).' '.$currency->getFormattedCurrency($currency->convertCurrencyTo("USD",$rate["TotalCharges"]["MonetaryValue"]["VALUE"])).'</label>';
				
						$htmla [] = $html;
					}
					
				}
				$htmla []= '<input type="hidden" id="ups_rate_'.$this->method->virtuemart_shipmentmethod_id.'" name="ups_rate_'.$this->method->virtuemart_shipmentmethod_id.'" value="'.$this->ups_rate.'"></input>';
				$htmla []= '<input type="hidden" id="ups_name_'.$this->method->virtuemart_shipmentmethod_id.'" name="ups_name_'.$this->method->virtuemart_shipmentmethod_id.'" value="'.$this->ups_service_name.'"></input>';
				$htmla []= '<input type="hidden" id="ups_id_'.$this->method->virtuemart_shipmentmethod_id.'" name="ups_id_'.$this->method->virtuemart_shipmentmethod_id.'" value="'.$this->ups_service_id.'"></input>';
				$htmla [] = '<script>jQuery("document").ready(function ($){
					jQuery("#shipment_id_'.$this->method->virtuemart_shipmentmethod_id.'").live("click", function(){
						var rate = $(this).attr("rate");
						var service_id = $(this).attr("service_id");
						var service_name = $(this).attr("service_name");
						jQuery("#ups_rate_'.$this->method->virtuemart_shipmentmethod_id.'").val(rate);
						jQuery("#ups_name_'.$this->method->virtuemart_shipmentmethod_id.'").val(service_name);
						jQuery("#ups_id_'.$this->method->virtuemart_shipmentmethod_id.'").val(service_id);
					});
				});</script>';
			}
		}		

		$htmlIn[] = $htmla;
		
		return true;
		
		//return $this->displayListFE ($cart, $selected, $htmlIn);
	}
	
	public function getUPSresponse($cart, $method){
		
		$vendorId = $this->vendor;
		$vendorModel = VmModel::getModel('vendor');
		$vendorFields = $vendorModel->getVendorAddressFields();

		$weight = 0;
		foreach ($cart->products as $product) {
			(float)$product_weight = ShopFunctions::convertWeigthUnit($product->product_weight, $product->product_weight_uom, "LB");
			$weight += $product_weight * $product->quantity;
		}
		if ($weight == 0) {
			JFactory::getApplication()->enqueueMessage("UPS Error: Product Weight not found", "error");
			$this->clear();
			$mainframe = JFactory::getApplication();
			$redirectMsg = "UPS Error: Product Weight not found";
			$mainframe->redirect(JRoute::_('index.php?option=com_virtuemart&view=user&task=editaddresscart&addrtype=BT'), $redirectMsg);
			return FALSE;
		}

		$accessNumber = trim($method->api);
		$username = trim($method->username);
		$password = trim($method->password);
		$upsConnect = new ups($accessNumber,$username,$password);
		$upsConnect->setTemplatePath(JPATH_ROOT.'/plugins/vmshipment/jibon_ups/ups/xml/');
		$upsConnect->setTestingMode($method->mood); // Change this to 0 for production

		$upsRate = new upsRate($upsConnect);
		$upsRate->request(array('Shop' => true));
		
		$upsRate->shipper(array(
							 'name' => $vendorFields['fields']['first_name']['value']." ".$vendorFields['fields']['last_name']['value'],
							 'phone' => $vendorFields['fields']['phone_1']['value'], 
							 'shipperNumber' => '', 
							 'address1' => $vendorFields['fields']['address_1']['value'], 
							 'address2' => '', 
							 'address3' => '', 
							 'city' => $vendorFields['fields']['city']['value'], 
							 'state' => $vendorFields['fields']['virtuemart_state_id']['state_2_code'], 
							 'postalCode' => $vendorFields['fields']['zip']['value'], 
							 'country' => $vendorFields['fields']['virtuemart_country_id']['country_2_code']));
				

		if (!is_array($cart->BT)) {			
			JFactory::getApplication()->enqueueMessage("UPS Error: Please put valid shipping information !!", "error");
			return false;
		}
		if (is_array($cart->ST)) {
			
			$upsRate->shipTo(array('companyName' => $cart->ST['company'], 
								'attentionName' => $cart->ST['first_name']." ".$cart->ST['last_name'], 
								'phone' => $cart->ST['phone_1'], 
								'address1' => $cart->ST['address_1'], 
								'address2' => '', 
								'address3' => '', 
								'city' => $cart->ST['city'], 
								'state' => ShopFunctions::getStateByID($cart->ST['virtuemart_state_id'],"state_2_code"), 
								'postalCode' => $cart->ST['zip'], 
								'countryCode' => ShopFunctions::getCountryByID($cart->ST['virtuemart_country_id'],"country_2_code")));
		} else {
			
			$upsRate->shipTo(array('companyName' => $cart->BT['company'], 
								'attentionName' => $cart->BT['first_name']." ".$cart->BT['last_name'], 
								'phone' => $cart->BT['phone_1'], 
								'address1' => $cart->BT['address_1'], 
								'address2' => '', 
								'address3' => '', 
								'city' => $cart->BT['city'], 
								'state' => ShopFunctions::getStateByID($cart->BT['virtuemart_state_id'],"state_2_code"), 
								'postalCode' => $cart->BT['zip'], 
								'countryCode' => ShopFunctions::getCountryByID($cart->BT['virtuemart_country_id'],"country_2_code")));
		}
		
		$upsRate->package(array('description' => 'my description', 
										'weight' => $weight,
										'code' => '02', // Package type page 40
										'length' => 0,
										'width' => 0,
										'height' => 0,
										));

		$upsRate->shipment(array('description' => 'my description','serviceType' => '03'));//service type

		$upsRate->sendRateRequest();
		$this->UPSresponse = $upsRate->returnResponseArray();
		if (!empty($this->UPSresponse["RatingServiceSelectionResponse"]["Response"]["Error"]["ErrorCode"]) or empty($this->UPSresponse)){
			$this->ups_rate = "";
			$this->ups_service_name = "";
			$this->ups_service_id = "";
			$this->status = 0;
			$this->loadPost($method->virtuemart_shipmentmethod_id);
			JFactory::getApplication()->enqueueMessage("UPS Error: ".$this->UPSresponse["RatingServiceSelectionResponse"]["Response"]["Error"]["ErrorDescription"]["VALUE"], "error");
		}
		$currency = CurrencyDisplay::getInstance();
		if ($this->UPSresponse['RatingServiceSelectionResponse']['RatedShipment']) {
			foreach ($this->UPSresponse['RatingServiceSelectionResponse']['RatedShipment'] as $rate){
				if ($this->ups_service_id === $rate["Service"]["Code"]["VALUE"]){
					$this->ups_rate = $currency->convertCurrencyTo("USD",$rate["TotalCharges"]["MonetaryValue"]["VALUE"]);
					$this->ups_service_name = $this->getServiceName($rate["Service"]["Code"]["VALUE"]);	
					$this->ups_service_id = $rate["Service"]["Code"]["VALUE"];
					$this->save();
					break;
				}
			}	
		}			
		
		return $this->UPSresponse;
	}
	
	function getServiceName($code){
		
		$serviceDetail = array (
										array (
											"id" => "01",
											"service" => "UPS Next Day Air®"
											),
										array (
											"id" => "02",
											"service" => "UPS Second Day Air®"
											),
										array (
											"id" => "03",
											"service" => "UPS Ground"
											),
										array (
											"id" => "07",
											"service" => "UPS Worldwide Express<sup>SM</sup>"
											),
										array (
											"id" => "08",
											"service" => "UPS Worldwide Expedited<sup>SM</sup>"
											),
										array (
											"id" => "11",
											"service" => "UPS Standard"
											),
										array (
											"id" => "12",
											"service" => "UPS Three-Day Select®"
											),
										array (
											"id" => "13",
											"service" => "UPS Next Day Air Saver®"
											),
										array (
											"id" => "14",
											"service" => "UPS Next Day Air® Early A.M.<sup>SM</sup>"
											),
										array (
											"id" => "54",
											"service" => "UPS Worldwide Express Plus<sup>SM</sup>"
											),
										array (
											"id" => "59",
											"service" => "UPS Second Day Air A.M.®"
											),
										array (
											"id" => "65",
											"service" => "UPS Saver"
											),array (
											"id" => "82",
											"service" => "UPS Today Standard<sup>SM</sup>"
											),
										array (
											"id" => "83",
											"service" => "UPS Today Dedicated Courrier<sup>SM</sup>"
											),
										array (
											"id" => "84",
											"service" => "UPS Today Intercity"
											),
										array (
											"id" => "85",
											"service" => "UPS Today Express"
											),
										array (
											"id" => "86",
											"service" => "UPS Today Express Saver"
											)
						);
		foreach ($serviceDetail as $service) {
			if ($service["id"] == $code) {
				$service_name = $service["service"];
				break;
			}
		}
		return $service_name;
	}

	
	protected function renderPluginName($activeMethod) {
		
		$this->getPost();
		$this->loadPost($activeMethod->virtuemart_shipmentmethod_id);
		$return = '';
		$plugin_name = $this->_psType . '_name';
		$plugin_desc = $this->_psType . '_desc';
		$description = '';
		$logosFieldName = $this->_psType . '_logos';
		$logos = $activeMethod->$logosFieldName;
		if (!empty($logos)) {
			$return = $this->displayLogos($logos) . ' ';
		}
		$pluginName = $return . '<span class="' . $this->_type . '_name">' . $activeMethod->$plugin_name . '</span><br />';
		$pluginName .= $this->ups_service_name;
		return $pluginName;
	}
	
	
	/**
	 * @param VirtueMartCart $cart
	 * @param array          $cart_prices
	 * @param                $cart_prices_name
	 * @return bool|null
	 */
	public function plgVmOnSelectedCalculatePriceShipment (VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
		return $this->onSelectedCalculatePrice ($cart, $cart_prices, $cart_prices_name);
	}

	/**
	 * plgVmOnCheckAutomaticSelected
	 * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
	 * The plugin must check first if it is the correct type
	 *
	 * @author Valerie Isaksen
	 * @param VirtueMartCart cart: the cart object
	 * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
	 *
	 */
	function plgVmOnCheckAutomaticSelectedShipment (VirtueMartCart $cart, array $cart_prices, &$shipCounter) {

		if ($shipCounter > 1) {
			return 0;
		}

		return $this->onCheckAutomaticSelected ($cart, $cart_prices, $shipCounter);
	}

	/**
	 * This method is fired when showing when priting an Order
	 * It displays the the payment method-specific data.
	 *
	 * @param integer $_virtuemart_order_id The order ID
	 * @param integer $method_id  method used for this order
	 * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
	 * @author Valerie Isaksen
	 */
	function plgVmonShowOrderPrint ($order_number, $method_id) {
		return $this->onShowOrderPrint ($order_number, $method_id);
	}

	function plgVmDeclarePluginParamsShipment ($name, $id, &$dataOld) {
		return $this->declarePluginParams ('shipment', $name, $id, $dataOld);
	}

	function plgVmDeclarePluginParamsShipmentVM3 (&$data) {
		return $this->declarePluginParams ('shipment', $data);
	}


	/**
	 * @author Max Milbers
	 * @param $data
	 * @param $table
	 * @return bool
	 */
	function plgVmSetOnTablePluginShipment(&$data,&$table){

		$name = $data['shipment_element'];
		$id = $data['shipment_jplugin_id'];

		if (!empty($this->_psType) and !$this->selectedThis ($this->_psType, $name, $id)) {
			return FALSE;
		} else {
			$toConvert = array('weight_start','weight_stop','orderamount_start','orderamount_stop');
			foreach($toConvert as $field){

				if(!empty($data[$field])){
					$data[$field] = str_replace(array(',',' '),array('.',''),$data[$field]);
				} else {
					unset($data[$field]);
				}
			}

			$data['nbproducts_start'] = (int) $data['nbproducts_start'];
			$data['nbproducts_stop'] = (int) $data['nbproducts_stop'];

			//Reasonable tests:
			if(!empty($data['zip_start']) and !empty($data['zip_stop']) and (int)$data['zip_start']>=(int)$data['zip_stop']){
				vmWarn('VMSHIPMENT_JIBON_UPS_ZIP_CONDITION_WRONG');
			}
			if(!empty($data['weight_start']) and !empty($data['weight_stop']) and (float)$data['weight_start']>=(float)$data['weight_stop']){
				vmWarn('VMSHIPMENT_JIBON_UPS_WEIGHT_CONDITION_WRONG');
			}

			if(!empty($data['orderamount_start']) and !empty($data['orderamount_stop']) and (float)$data['orderamount_start']>=(float)$data['orderamount_stop']){
				vmWarn('VMSHIPMENT_JIBON_UPS_AMOUNT_CONDITION_WRONG');
			}

			if(!empty($data['nbproducts_start']) and !empty($data['nbproducts_stop']) and (float)$data['nbproducts_start']>=(float)$data['nbproducts_stop']){
				vmWarn('VMSHIPMENT_JIBON_UPS_NBPRODUCTS_CONDITION_WRONG');
			}
			

			return $this->setOnTablePluginParams ($name, $id, $table);
		}
	}


}

// No closing tag
