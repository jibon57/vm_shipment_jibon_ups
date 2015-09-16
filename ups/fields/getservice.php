<?php
defined('_JEXEC') or die();
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
JFormHelper::loadFieldClass('list');
jimport('joomla.form.formfield');
class  JFormFieldGetservice extends JFormFieldList {
    /**
     * Element name
     * @access	protected
     * @var		string
     */
	var $type = 'getservice';
	
	
	protected function getOptions() {
		
		$serviceByCountry = array (
							
							"US" => array (
										
										array (
											"id" => "01",
											"service" => "UPS Next Day Air"
											),
										array (
											"id" => "02",
											"service" => "UPS Second Day Air"
											),
										array (
											"id" => "03",
											"service" => "UPS Ground"
											),
										array (
											"id" => "07",
											"service" => "UPS Worldwide Express"
											),
										array (
											"id" => "08",
											"service" => "UPS Worldwide Expedited"
											),
										array (
											"id" => "11",
											"service" => "UPS Standard"
											),
										array (
											"id" => "12",
											"service" => "UPS Three-Day Select"
											),
										array (
											"id" => "13",
											"service" => "UPS Next Day Air Saver"
											),
										array (
											"id" => "14",
											"service" => "UPS Next Day Air Early A.M."
											),
										array (
											"id" => "54",
											"service" => "UPS Worldwide Express Plus"
											),
										array (
											"id" => "59",
											"service" => "UPS Second Day Air A.M."
											),
										array (
											"id" => "65",
											"service" => "UPS Saver"
											),										
								),
							"PR" => array (
										array (
											"id" => "01",
											"service" => "UPS Next Day Air"
											),
										array (
											"id" => "02",
											"service" => "UPS Second Day Air"
											),
										array (
											"id" => "03",
											"service" => " UPS Ground"
											),
										array (
											"id" => "07",
											"service" => "UPS Worldwide Express"
											),
										array (
											"id" => "08",
											"service" => "UPS Worldwide Expedited"
											),
										array (
											"id" => "14",
											"service" => "UPS Next Day Air Early A.M."
											),
										array (
											"id" => "54",
											"service" => "UPS Worldwide Express Plus"
											),
										array (
											"id" => "65",
											"service" => "UPS Saver"
											)
								),
							
							"CA" => array (
										array (
											"id" => "01",
											"service" => "UPS Next Day Air"
											),
										array (
											"id" => "02",
											"service" => "UPS Second Day Air"
											),
										array (
											"id" => "07",
											"service" => "UPS Worldwide Express"
											),
										array (
											"id" => "08",
											"service" => "UPS Worldwide Expedited"
											),
										array (
											"id" => "11",
											"service" => "UPS Standard"
											),
										array (
											"id" => "12",
											"service" => "UPS Three-Day Select"
											),
										array (
											"id" => "13",
											"service" => "UPS Next Day Air Saver"
											),
										array (
											"id" => "14",
											"service" => "UPS Next Day Air Early A.M."
											),
										array (
											"id" => "54",
											"service" => "UPS Worldwide Express Plus"
											),
										array (
											"id" => "65",
											"service" => "UPS Saver"
											)	
								),
							
							"MX" => array (
										array (
											"id" => "07",
											"service" => "UPS Worldwide Express"
											),
										array (
											"id" => "08",
											"service" => "UPS Worldwide Expedited"
											),
										array (
											"id" => "54",
											"service" => "UPS Worldwide Express Plus"
											),
										array (
											"id" => "65",
											"service" => "UPS Saver"
											)
								),
							
							"PL" => array (
									
										array (
											"id" => "07",
											"service" => "UPS Worldwide Express"
											),
										array (
											"id" => "08",
											"service" => "UPS Worldwide Expedited"
											),
										array (
											"id" => "11",
											"service" => "UPS Standard"
											),
										array (
											"id" => "54",
											"service" => "UPS Worldwide Express Plus"
											),
										array (
											"id" => "65",
											"service" => "UPS Saver"
											),
										array (
											"id" => "82",
											"service" => "UPS Today Standard"
											),
										array (
											"id" => "83",
											"service" => "UPS Today Dedicated Courrier"
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
											),
								),
							"other" => array (
										
											array (
												"id" => "07",
												"service" => "UPS Worldwide Express"
												),
											array (
												"id" => "08",
												"service" => "UPS Worldwide Expedited"
												),
											array (
												"id" => "11",
												"service" => "UPS Standard"
												),
											array (
												"id" => "54",
												"service" => "UPS Worldwide Express Plus"
												),
											array (
												"id" => "65",
												"service" => "UPS Saver"
												)
								)
	
					);
					
		
		if (!class_exists('VirtueMartModelVendor')) {
			 require_once(JPATH_ADMINISTRATOR . DS . 'components' . DS . 'com_virtuemart' . DS . 'models' . DS . 'vendor');
		}	   
		$vendorModel = VmModel::getModel('vendor');
		$vendorId = $vendorModel->getVendor();
        $vendorModel->setId($vendorId);
		$vendorFields = $vendorModel->getVendorAddressFields($vendorId);
		$vendorCountry = $vendorFields['fields']['virtuemart_country_id']['country_2_code'];
		$country = "";

		if (array_key_exists($vendorCountry, $serviceByCountry)) {
			$country = $vendorCountry;
		} else {
			$country = "other";
		}
	foreach ($serviceByCountry[$country] as $data) {
			$options[] = JHtml::_('select.option',$data['id'], $data['service']);
		}		

		return $options;
	
	}
}

?>