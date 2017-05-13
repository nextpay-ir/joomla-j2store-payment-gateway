<?php
/**
 * Nextpay Payment Gateway Plugin for J2Store Component
 * PHP version 5.6+
 * @author Nextpay <info@nextpay.ir>
 * @copyright 2016-2017 NextPay.ir
 * @version 1.0.0
 * @link http://www.nextpay.ir
 */
defined('_JEXEC') or die('Restricted access');

require_once (JPATH_ADMINISTRATOR.'/components/com_j2store/library/plugins/payment.php');
require_once (JPATH_ADMINISTRATOR.'/components/com_j2store/helpers/j2store.php');

class plgJ2StorePayment_nextpay extends J2StorePaymentPlugin
{
    /**
     * @var $_element  string  Should always correspond with the plugin's filename,
     *                         forcing it to be unique
     */
    public $_element   = 'payment_nextpay';
    private $apikey = '';
    private $callBackUrl = '';
    private $redirectToNextpay = '';

    public function __construct(& $subject, $config)
    {
        parent::__construct($subject, $config);
        $this->loadLanguage( '', JPATH_ADMINISTRATOR );
        $this->apikey = trim($this->params->get('api_key'));
        $this->callBackUrl = JUri::root().'/index.php?option=com_j2store&view=checkout&task=confirmPayment&orderpayment_type=payment_nextpay&paction=callback';
        $this->redirectToNextpay = 'https://api.nextpay.org/gateway/payment/';
    }

    public function _renderForm( $data )
    {
        $vars = new JObject();
        $vars->message = JText::_("J2STORE_NEXTPAY_PAYMENT_MESSAGE");
        $html = $this->_getLayout('form', $vars);
        return $html;
    }

    public function _prePayment($data)
    {
        $vars = new StdClass();
        $vars->display_name = $this->params->get('display_name', '');
        $vars->onbeforepayment_text = JText::_("J2STORE_NEXTPAY_PAYMENT_PREPARATION_MESSAGE");

        $amount = $data['orderpayment_amount'] / 10;
        $amount = (int)$amount;
        $api_key = trim($this->params->get('api_key'));
        $order_id = $data['order_id'];
        $desc = "پرداخت سفارش شماره " . $order_id . " به مبلغ "  . $amount . " با نکست پی";
        $params = array(
            'api_key' => $api_key,
            'amount' => $amount,
            'order_id' => $order_id,
            'callback_uri' => $this->callBackUrl
        );

        $request = $this->requestNextpay($params);

        if(is_array($request) and array_key_exists('error', $request)){
            $vars->error = $request['error'];
            $html = $this->_getLayout('prepayment', $vars);
            return $html;
        }

        if(is_array($request) and array_key_exists('trans_id', $request)){
            $trans_id = $request['trans_id'];
            $vars->redirectToNextpay = $this->redirectToNextpay . $trans_id;
            $html = $this->_getLayout('prepayment', $vars);
            return $html;
        }

        $vars->error = $this->statusText($request['error']);
        $html = $this->_getLayout('prepayment', $vars);
        return $html;
    }

    public function _postPayment($data)
    {
        $vars = new JObject();
        //get order id
        $orderId = $data['order_id'];
        // get instatnce of j2store table
        F0FTable::addIncludePath(JPATH_ADMINISTRATOR.'/components/com_j2store/tables');
        $order = F0FTable::getInstance('Order', 'J2StoreTable')->getClone();
        $order->load(array('order_id' => $orderId));

        if($order->load(array('order_id' => $orderId))){

            $currency = J2Store::currency();
            $currencyValues= $this->getCurrency($order);
            $orderPaymentAmount = $currency->format($order->order_total, $currencyValues['currency_code'], $currencyValues['currency_value'], false);
            $orderPaymentAmount = (int)($orderPaymentAmount / 10);

            $order->add_history(JText::_('J2STORE_CALLBACK_RESPONSE_RECEIVED'));

            $api_key = $this->params->get('api_key');
            //$orderId = $this->params->get('order_id');
            $trans_id = $this->params->get('trans_id');

            //$app = JFactory::getApplication();

            $params = array(
                'api_key' => $api_key,
                'amount' => (int)$orderPaymentAmount,
                'order_id' => $orderId,
                'trans_id' => $trans_id
            );

            $validate = $this->validateNextpay($params);

            if(is_array($validate) and array_key_exists('error', $validate)){
                $vars->message = $validate['error'];
                $html = $this->_getLayout('postpayment', $vars);
                // $app->close();
                return $html;
            }

            if($validate['code'] == 0){
                $order->payment_complete();
                $order->empty_cart();
                $message = JText::_("J2STORE_NEXTPAY_PAYMENT_SUCCESS") . "\n";
                $message .= JText::_("J2STORE_NEXTPAY_PAYMENT_ZP_REF") . " : ". $trans_id;
                $vars->message = $message;
                $html = $this->_getLayout('postpayment', $vars);
                // $app->close();
                return $html;
            }

            $message = JText::_("J2STORE_NEXTPAY_PAYMENT_FAILED") . "\n";
            $message .= JText::_("J2STORE_NEXTPAY_PAYMENT_ERROR");
            $message .= $this->statusText($validate['code']) . "\n";
            $message .= JText::_("J2STORE_NEXTPAY_PAYMENT_CONTACT") . "\n";
            $vars->message = $message;
            $html = $this->_getLayout('postpayment', $vars);
            // $app->close();
            return $html;

        }

        $vars->message = JText::_("J2STORE_NEXTPAY_PAYMENT_PAGE_ERROR");
        $html = $this->_getLayout('postpayment', $vars);
        return $html;
    }

    private function requestNextpay($params = [])
    {
        try{
            $client = new SoapClient('https://api.nextpay.org/gateway/token.wsdl', array('encoding' => 'UTF-8'));
        } catch(SoapFault $e){
            return ['error' => $e->getMessage()];
        }
        $res = $client->TokenGenerator($params);
        $res = $res->TokenGeneratorResult;
        $trans_id = '0';
        if ($res != "" && $res != NULL && is_object($res)) {
            if (intval($res->code) == -1)
                $trans_id = $res->trans_id;
	        else return array("trans_id" => $trans_id, 'error' => 'خطا در ایجاد درخواست با کد : ' . $res->code);
        }
        else {
            return array("trans_id" => $trans_id, 'error' => "خطا در پاسخ دهی به درخواست با SoapClinet");
        }
        return array("trans_id" => $trans_id);
    }

    private function validateNextpay($params = [])
    {
        try{
            $client = new SoapClient('https://api.nextpay.org/gateway/verify.wsdl', ['encoding' => 'UTF-8']);
        } catch(SoapFault $e){
            return ['error' => $e->getMessage()];
        }
        $res = $client->PaymentVerification($params);
        $res = $res->PaymentVerificationResult;
        $code = -1;
        if ($res != "" && $res != NULL && is_object($res)) {
            $code = $res->code;
        }
        else{
            return array("code" => $code, 'error' => "خطا در پاسخ دهی به درخواست با SoapClinet");
        }
        return array("code" => $code);
    }

    private function statusText($status)
    {
        return JText::_("J2STORE_NEXTPAY_PAYMENT_STATUS_" . $status );
    }
}