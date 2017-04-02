<?php
defined('_JEXEC' ) or die;

$currency = $viewData['currency'];
if(!empty($viewData['method']->countries) and is_array($viewData['method']->countries) and count($viewData['method']->countries)>0){
    $countryM = VmModel::getModel('country');
    echo vmText::_('VMSHIPMENT_SHOP_LOGISTIC_SHIP_TO');
    foreach($viewData['method']->countries as $virtuemart_country_id){
        $country = $countryM->getData($virtuemart_country_id);
        echo $country->country_name;
    }
    echo '</br>';
}
