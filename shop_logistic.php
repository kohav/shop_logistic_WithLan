<?php

defined ('_JEXEC') or die('Restricted access');
//для подгрузки русского языка
JFactory::getLanguage()->load('plg_vmshipment_shop_logistic', JPATH_ADMINISTRATOR, "ru-RU");

/**
 * Shipment plugin for shop_logistic shipments
 *
 * @version 1: shop_logistic.php March 2017
 * @package VirtueMart
 * @subpackage Plugins - shipment
 * @copyright Copyright (C) 2017 Kohav - All rights reserved.
 * @license license
 *
 * http://webveles.com/workshop/
 * @author Kohav
 */
if (!class_exists ('vmPSPlugin')) {
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

class plgVmShipmentShopLogistic extends vmPSPlugin {

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
        // сохраняем параметры, заполненные в админ-панели
        $varsToPush = $this->getVarsToPush ();
        $this->setConfigParameterable ($this->_configTableFieldName, $varsToPush);
//        $this->setConvertable(array('orderamount_start','orderamount_stop','shipment_cost','package_fee'));
        $this->setConvertable(array('orderamount_start','orderamount_stop','shipment_cost','package_fee'));
    }

    /**
     * Создание таблицы для этого плагина, если она еще не существует.
     * Поля берутся из структуры $SQLfields (ф-ция getTableSQLFields)
     * + вспомогательные поля (описаны в getTableSQLLoggablefields родительского класса)
     */
    public function getVmPluginCreateTableSQL () {

        return $this->createTableSQL ('Shipment Shop logistic Table');
    }

    /**
     * 	 Поля для новой таблицы в БД virtuemart_shipment_plg_shop_logistic
     *   Обращение к функции идет из createTableSQL класса vmpsplugin
     *   для сохранения данных о доставке заказов данным способом
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
            'shipment_package_fee'         => 'decimal(10,2)'
            /*			'tax_id'                       => 'smallint(1)' */
        );
        return $SQLfields;
    }

    /**
     * Отображение информации о доставке в заказе.
     * @param integer $virtuemart_order_id 			Номер заказа
     * @param integer $virtuemart_shipmentmethod_id Код выбранного способа доставки
     * @param string  $shipment_name 				Название способа доставки
     * @return mixed Null for shipments that aren't active, text (HTML) otherwise
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
            $values['shipment_cost'] = $method->shipment_cost;
            $values['shipment_package_fee'] = $method->package_fee;
        }

        $values['tax_id'] = $method->tax_id;
        $this->storePSPluginInternalData ($values);

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
     * Возвращает информацио о заказе по его номеру из таблицы доставок
     * и формирует html-вывод данных в виде таблицы
     * @param $virtuemart_order_id  код заказа
     * @return string               возвращает строку с результатами запроса
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
        $html .= $this->getHtmlRowBE ('SHOP_LOGISTIC_SHIPPING_NAME', $shipinfo->shipment_name);
        $html .= $this->getHtmlRowBE ('SHOP_LOGISTIC_WEIGHT', $shipinfo->order_weight . ' ' . ShopFunctions::renderWeightUnit ($shipinfo->shipment_weight_unit));
        $html .= $this->getHtmlRowBE ('SHOP_LOGISTIC_COST', $currency->priceDisplay ($shipinfo->shipment_cost));
        $html .= $this->getHtmlRowBE ('SHOP_LOGISTIC_PACKAGE_FEE', $currency->priceDisplay ($shipinfo->shipment_package_fee));
        $html .= $this->getHtmlRowBE ('SHOP_LOGISTIC_TAX', $taxDisplay);
        $html .= '</table>' . "\n";

        return $html;
    }

    /**
     * расчет стоимости доставки
     * @param VirtueMartCart $cart
     * @param                $method
     * @param                $cart_prices
     * @return int
     */
    function getCosts (VirtueMartCart $cart, $method, $cart_prices) {
        if ($method->free_shipment && $cart_prices['salesPrice'] >= $method->free_shipment) {
            return 0.0;
        } else {

            /// обращение на вебсервис
            $api_id= '577888574a3e4df01867cd5ccc9f18a5';//'f2b21f76a12c240c6dd4200a2f3d9658';
            $from_city = 405065;
            $to_city   = 958281;
            $pickup_place = 470558; ///для курьерской не указываеться
            $weight = 10;
            $order_length ='';
            $order_width='';
            $order_height='';
            $num = 100;
            $order_price = 1000.00;
            $ocen_price = 1000.00;
            $tarifs_type = 1; // 1 - курьерская 2 - самовывоз
            $delivery_partner='';

            $xml  = '<?xml version="1.0" encoding="utf-8"?>';
            $xml .= '<request>';
            $xml .= '<function>get_delivery_price</function>';
            $xml .= '<api_id>'.$api_id.'</api_id>';
            $xml .= '<from_city>'.$from_city.'</from_city>';
            $xml .= '<to_city>'.$to_city.'</to_city>';
            $xml .= '<pickup_place>'.$pickup_place.'</pickup_place>';
            $xml .= '<weight>'.$weight.'</weight>';
            $xml .= '<order_length>'.$order_length.'</order_length>';
            $xml .= '<order_width>'.$order_width.'</order_width>';
            $xml .= '<order_height>'.$order_height.'</order_height>';
            $xml .= '<num>'.$num.'</num>';
            $xml .= '<order_price>'.$order_price.'</order_price>';
            $xml .= '<ocen_price>'.$ocen_price.'</ocen_price>';
            $xml .= '<tarifs_type>'.$tarifs_type.'</tarifs_type>';
            $xml .= '<delivery_partner>'.$delivery_partner.'</delivery_partner>';
            $xml .= '</request>';

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, 'http://client-shop-logistic.ru/index.php?route=deliveries/api');
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, 'xml='.urlencode(base64_encode($xml)));
            curl_setopt($curl, CURLOPT_USERAGENT, 'Opera 10.00');
            $res = curl_exec($curl);
            curl_close($curl);

            $xmlRes = simplexml_load_string($res);
            $json = json_encode($xmlRes);
            $Result = json_decode($json,TRUE);

            if (!($Result['error'])) {
                return $Result['price'];
            }
            else
            {
                return 0.0;
            }
        }
    }

    /**
     * @param VirtueMartCart  $cart
     * @param int             $method
     * @param array           $cart_prices
     * @return bool
     */
    protected function checkConditions ($cart, $method, $cart_prices) {

        static $result = array();

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

        if($this->_toConvert){
            $this->convertToVendorCurrency($method);
        }


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

        $cat_cond = true;
        if($method->categories or $method->blocking_categories){
            if($method->categories)$cat_cond = false;
            //vmdebug('hmm, my value',$method);
            //if at least one product is  in a certain category, display this shipment
            if(!is_array($method->categories)) $method->categories = array($method->categories);
            if(!is_array($method->blocking_categories)) $method->blocking_categories = array($method->blocking_categories);
            //Gather used cats
            foreach($cart->products as $product){
                if(array_intersect($product->categories,$method->categories)){
                    $cat_cond = true;
                    //break;
                }
                if(array_intersect($product->categories,$method->blocking_categories)){
                    $cat_cond = false;
                    break;
                }
            }
            //if all products in a certain category, display the shipment
            //if a product has a certain category, DO NOT display the shipment
        }

        $allconditions = (int) $weight_cond + (int)$zip_cond + (int)$nbproducts_cond + (int)$orderamount_cond + (int)$country_cond + (int)$cat_cond;
        if($allconditions === 6){
            $result[$hash] = true;
            return TRUE;
        } else {
            $result[$hash] = false;
            //vmdebug('checkConditions '.$method->shipment_name.' does not fit ',(int)$weight_cond,(int)$zip_cond,(int)$nbproducts_cond,(int)$orderamount_cond);
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

        if(!isset($cart)){
            $cart = VirtueMartCart::getCart();
            $cart->products['virtual'] = $product;
            $cart->_productAdded = true;
            $cart->prepareCartData();
        }

        foreach ($this->methods as $this->_currentMethod) {

            if($this->_currentMethod->show_on_pdetails){

                if($this->checkConditions($cart,$this->_currentMethod,$cart->cartPrices,$product)){

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
        unset($cart->products['virtual']);
        $cart->_productAdded = true;
        $cart->prepareCartData();

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

        return $this->OnSelectCheck ($cart);
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

        return $this->displayListFE ($cart, $selected, $htmlIn);
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
            $tCon = array('weight_start','weight_stop','orderamount_start','orderamount_stop','shipment_cost','package_fee');
            foreach($tCon as $f){
                if(!empty($data[$f])){
                    $data[$f] = str_replace(array(',',' '),array('.',''),$data[$f]);
                }
            }

            $data['nbproducts_start'] = (int) $data['nbproducts_start'];
            $data['nbproducts_stop'] = (int) $data['nbproducts_stop'];

            //Reasonable tests:
            if(!empty($data['zip_start']) and !empty($data['zip_stop']) and (int)$data['zip_start']>=(int)$data['zip_stop']){
                vmWarn('VMSHIPMENT_SHOP_LOGISTIC_ZIP_CONDITION_WRONG');
            }
            if(!empty($data['weight_start']) and !empty($data['weight_stop']) and (float)$data['weight_start']>=(float)$data['weight_stop']){
                vmWarn('VMSHIPMENT_SHOP_LOGISTIC_WEIGHT_CONDITION_WRONG');
            }

            if(!empty($data['orderamount_start']) and !empty($data['orderamount_stop']) and (float)$data['orderamount_start']>=(float)$data['orderamount_stop']){
                vmWarn('VMSHIPMENT_SHOP_LOGISTIC_AMOUNT_CONDITION_WRONG');
            }

            if(!empty($data['nbproducts_start']) and !empty($data['nbproducts_stop']) and (float)$data['nbproducts_start']>=(float)$data['nbproducts_stop']){
                vmWarn('VMSHIPMENT_SHOP_LOGISTIC_NBPRODUCTS_CONDITION_WRONG');
            }

            $data['show_on_pdetails'] = (int) $data['show_on_pdetails'];
            return $this->setOnTablePluginParams ($name, $id, $table);
        }
    }


}




