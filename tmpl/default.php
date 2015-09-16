<?php
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

//vmdebug('we have here ',$viewData['product']->prices,$viewData['method']);
$currency = $viewData['currency'];
if(!empty($viewData['method']->countries) and is_array($viewData['method']->countries) and count($viewData['method']->countries)>0){
	$countryM = VmModel::getModel('country');
	echo Jtext::_('VMSHIPMENT_JIBON_UPS_SHIP_TO');
	foreach($viewData['method']->countries as $virtuemart_country_id){
		$country = $countryM->getData($virtuemart_country_id);
		echo $country->country_name;
		//vmdebug('my country ',$country);
	}
}
echo '</br>';
echo vmtext::sprintf('VMSHIPMENT_JIBON_UPS_WITH_SHIPMENT', $viewData['method']->shipment_name, $currency->priceDisplay($viewData['product']->prices['shipmentPrice']));
?>