<?php

// app/code/local/Envato/espaypaymentmethod/controllers/PaymentController.php
class Plus_Espaypaymentmethod_PaymentController extends Mage_Core_Controller_Front_Action {

    /**
     * @return Mage_Checkout_Model_Session
     */
    protected function _getCheckout() {
        return Mage::getSingleton('checkout/session');
    }

    protected function _getResponseInquiry($status, $message = 'Success', $order = array()) {
        $return = '';

        if ($status === '0') {
            $amount = floatval($order['grand_total']) - floatval($order['espay_fee_amount']);
            $espayPayment = explode(':', $order['espay_payment_method']);

            $productCode = $espayPayment[0];
            if ($productCode === 'CREDITCARD' || $productCode === 'BNIDBO') {

                $amount = floatval($order['grand_total']) - floatval(Mage::getStoreConfig('payment/espay/creditcardtrxfee'));
            }
            $return = '0;' . $message . ';' . $order['increment_id'] . ';' . number_format($amount, 2, '.', '') . ';' . $order['order_currency_code'] . ';Payment ' . $order['increment_id'] . ';' . date('d/m/Y h:i:s');
        } else {
            $return = '1;' . $message . ';;;;;';
        }

        return $return;
    }

    protected function _getResponseReport($status, $message = 'Success', $order = array()) {
        $return = '';

        if ($status === '0') {
            $return = '0,' . $message . ',' . $order['increment_id'] . ',' . $order['increment_id'] . ',' . date('Y-m-d H:i:s');
        } else {
            $return = '1,' . $message . ',,,';
        }

        return $return;
    }

    public function redirectAction() {
        $orderIncrementId = $this->_getCheckout()->getLastRealOrderId();
        $order = Mage::getModel('sales/order')
                ->loadByIncrementId($orderIncrementId);
        $sessionId = Mage::getSingleton('core/session');
        $orderData = $order->getData();

        Mage::log(print_r($orderData, 1), null, 'espay_orderdata.log');

        $paymentData = $sessionId->getEspayPaymentMethod();
        $espayPayment = explode(':', $paymentData);

        $productCode = $espayPayment[0];
        $bankCode = $espayPayment[1];


        $urlJs = Mage::getStoreConfig('payment/espay/environment') == 'production' ? 'https://kit.espay.id' : 'https://sandbox-kit.espay.id';
        $key = Mage::getStoreConfig('payment/espay/paymentid');
        Mage::getSingleton('checkout/session')->unsQuoteId();
        foreach (Mage::getSingleton('checkout/session')->getQuote()->getItemsCollection() as $item) {
            Mage::getSingleton('checkout/cart')->removeItem($item->getId())->save();
        }
        //delete item from cart
        foreach (Mage::getSingleton('checkout/session')->getQuote()->getItemsCollection() as $item) {
            Mage::getSingleton('checkout/cart')->removeItem($item->getId())->save();
        }

        // Send payment to vendor dashboard 
        $name = $orderData['customer_firstname'] . " " . $orderData['customer_middlename'] . " " . $orderData['customer_lastname'];
        $email = $orderData['customer_email'];
        $order_no = $orderData['increment_id'];
        $total = explode(".", $orderData['grand_total'])[0];
        $transfer_no = $orderData['customer_id'];
        $transfer_bank = "Espay";

        $url = "http://vendor.tinkerlust.com/api_paymentconfirm.php?payment_sd=yes&name=". urlencode($name) . "&email=". urlencode($email) . "&order_no=". urlencode($order_no)."&total=". urlencode($total) ."&transfer_no=" . urlencode($transfer_no) . "&transfer_bank=".urlencode($transfer_bank);
        // Mage::log(print_r($url, 1), null, 'espay_querystring.log');

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_USERAGENT, 1);
        curl_setopt($curl, CURLOPT_POST, 1);
        $resp = curl_exec($curl);
        curl_close($curl);        
        // end

        $this->loadLayout();
        $block = $this->getLayout()->createBlock('Mage_Core_Block_Template', 'espaypaymentmethod', array('template' => 'espaypaymentmethod/redirect.phtml'));
        $block->assign(array('urlJs' => $urlJs, 'key' => $key, 'orderId' => $orderIncrementId, 'bankCode' => $bankCode, 'productCode' => $productCode));
        $this->getLayout()->getBlock('content')->append($block);
        $this->renderLayout();
    }

    public function inquiryAction() {
        $vr = Zend_Controller_Action_HelperBroker::getStaticHelper('viewRenderer');
        $vr->setNoRender(true);

        $this->loadLayout();
        $this->getResponse()->setBody(
                $this->getLayout()->createBlock('adminhtml/sales_order_view_tab_invoices')->toHtml()
        );

        $password = Mage::getStoreConfig('payment/espay/password');
        $defaultPaymentStatus = Mage::getStoreConfig('payment/espay/default_order_status');
        $ccTrxFee = Mage::getStoreConfig('payment/espay/ccfee');


        $webServicePassword = $this->getRequest()->getPost('password');
        $signature = $this->getRequest()->getPost('signature');
        $orderId = $this->getRequest()->getPost('order_id');
        $rqDatetime = $this->getRequest()->getPost('rq_datetime');
        $mode = 'INQUIRY';
        $selfSignature = Mage::helper('espaypaymentmethod/data')->generateTrxSignature($rqDatetime, $orderId, $mode);


        if ($signature === $selfSignature) {
            if ($webServicePassword == $password) {
                $order = Mage::getModel('sales/order')
                        ->loadByIncrementId($orderId);

                $orderData = $order->getData();


                if (!empty($orderData)) {
                    $orderData['ccfee'] = $ccTrxFee;
                    $orderData['espay_payment_method'] = $order->getPayment()->getData('espay_payment_method');

                    if ($orderData['status'] === $defaultPaymentStatus) {
                        echo $this->_getResponseInquiry('0', 'Success', $orderData);
                    } else {
                        echo $this->_getResponseInquiry('1', 'Order Has been Processed');
                    }
                } else {
                    echo $this->_getResponseInquiry('1', 'Order Id Not Valid');
                }
            } else {
                echo $this->_getResponseInquiry('1', 'Failed');
            }
        } else {
            echo $this->_getResponseInquiry('1', 'Invalid Signature');
        }
    }

    public function reportAction() {
        $vr = Zend_Controller_Action_HelperBroker::getStaticHelper('viewRenderer');
        $vr->setNoRender(true);

        $this->loadLayout();
        $this->getResponse()->setBody(
                $this->getLayout()->createBlock('adminhtml/sales_order_view_tab_invoices')->toHtml()
        );


        $password = Mage::getStoreConfig('payment/espay/password');
        $defaultPaymentStatus = Mage::getStoreConfig('payment/espay/default_order_status');


        $webServicePassword = $this->getRequest()->getPost('password');
        $orderId = $this->getRequest()->getPost('order_id');
        $paymentRef = $this->getRequest()->getPost('payment_ref');
        $product_code = $this->getRequest()->getPost('product_code');

        $signature = $this->getRequest()->getPost('signature');
        $rqDatetime = $this->getRequest()->getPost('rq_datetime');
        $mode = 'PAYMENTREPORT';

        $selfSignature = Mage::helper('espaypaymentmethod/data')->generateTrxSignature($rqDatetime, $orderId, $mode);

        $pr_status = Mage::getModel('sales/order_status')->load('accpt_espay_' . strtolower($product_code));
        $count_status = count($pr_status->getData('status'));

        if ($signature === $selfSignature) {
            if ($webServicePassword == $password) {
                $order = Mage::getModel('sales/order')
                        ->loadByIncrementId($orderId);

                $orderData = $order->getData();
                if (!empty($orderData)) {
                    if ($orderData['status'] === $defaultPaymentStatus) {
                        try {

                            $invoice = $order->prepareInvoice();

                            $invoice->setTransactionId();
                            $invoice->addComment('Payment successfully processed by Espay.');
                            $invoice->register();
                            $invoice->pay();

                            Mage::getModel('core/resource_transaction')
                                    ->addObject($invoice)
                                    ->addObject($invoice->getOrder($order->getId()))
                                    ->save();
                            #$invoice->sendEmail(true, '');

                            $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, 'Payment Success With Ref <b>' . $paymentRef . '</b>.');
                            if ($count_status != 0) {
                                $order->setStatus('accpt_espay_' . strtolower($product_code));
                            } else {
                                $order->setStatus('payment_accepted_espay');
                            }
                            $order->save();
                            $order->sendOrderUpdateEmail(true, 'Thank you, your payment is successfully processed.');
                            #$$order->setEmailSent(true);

                            $status = '0';
                            $message = 'success';
                        } catch (Exception $e) {
                            $status = '1';
                            $message = 'Update Order Failed';
                        }
                    } else {
                        $status = '1';
                        $message = 'Order has been processed';
                    }
                } else {
                    $status = '1';
                    $message = 'Order Id Not Valid';
                }
            } else {
                $status = '1';
                $message = 'Failed';
            }
        } else {
            $status = '1';
            $message = 'Invalid Signature';
        }
        echo $this->_getResponseReport($status, $message, $orderData);
    }

    public function responseAction() {
        $redirect = FALSE;
        $productModel = Mage::getModel('espaypaymentmethod/paymentmethod');
        $atmProducts = $productModel->atmProduct();
        $product = $this->getRequest()->get("product");

        Mage::log(print_r($product, 1), null, 'espay_responsedata.log');

        $atm = FALSE;

        if ($this->getRequest()->get("id") && $this->getRequest()->get("product")) {
            if ($this->getRequest()->get("product") !== '' && $this->getRequest()->get("id") !== '') {
                if (in_array($this->getRequest()->get("product"), $atmProducts)) {
                    $redirect = TRUE;
                    $atm = TRUE;
                } else {
                    $order = Mage::getModel('sales/order')
                            ->loadByIncrementId($this->getRequest()->get("id"));

                    $orderData = $order->getData();
                    if (!empty($orderData)) {
                        if ($orderData['status'] === 'accpt_espay_' . strtolower($product)) {
                            $redirect = TRUE;
                        }
                    }
                }
            }
        }


        if ($redirect) {
            if ($atm === TRUE) {
                Mage_Core_Controller_Varien_Action::_redirect('espaypaymentmethod/payment/pending', array('_secure' => false, '_use_rewrite' => true, '_query' => array('id' => $this->getRequest()->get("id"))));
            } else {
                Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/success', array('_secure' => false));
                // Mage::log(print_r($order, 1), null, 'espay_responsesuccess.log');

                // Start
                $order_no = $this->getRequest()->get("id");
                $name = "init"; 
                $email = "init@init.com"; 
                $total = ""; 
                $transfer_no = "init";
                $transfer_bank = "init";
                $url = "http://vendor.tinkerlust.com/api_paymentconfirm.php?payment_sd=yes&payment_update=yes&confirm=1&name=". urlencode($name) . "&email=". urlencode($email) . "&order_no=". urlencode($order_no)."&total=". urlencode($total) ."&transfer_no=" . urlencode($transfer_no) . "&transfer_bank=".urlencode($transfer_bank);
                Mage::log(print_r($url, 1), null, 'espay_querystring.log');

                $curl = curl_init();
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($curl, CURLOPT_URL, $url);
                curl_setopt($curl, CURLOPT_USERAGENT, 1);
                curl_setopt($curl, CURLOPT_POST, 1);
                $resp = curl_exec($curl);
                curl_close($curl);        
                // end
            }
        } else {
            Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/failure', array('_secure' => false));
            if ($order->canCancel()) {
                try {
                    $order->cancel();
                    $order->getStatusHistoryCollection(true);
                    $order->save();
                    // Mage::log("Order canceled", null, 'espay_responsefailed.log');

                    // Start
                    $order_no = $this->getRequest()->get("id");
                    $name = "init"; 
                    $email = "init@init.com"; 
                    $total = ""; 
                    $transfer_no = "init";
                    $transfer_bank = "init";                    
                    $url = "http://vendor.tinkerlust.com/api_paymentconfirm.php?payment_sd=yes&payment_update=yes&confirm=0&name=". urlencode($name) . "&email=". urlencode($email) . "&order_no=". urlencode($order_no)."&total=". urlencode($total) ."&transfer_no=" . urlencode($transfer_no) . "&transfer_bank=".urlencode($transfer_bank);
                    // Mage::log(print_r($url, 1), null, 'espay_querystring.log');

                    $curl = curl_init();
                    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($curl, CURLOPT_URL, $url);
                    curl_setopt($curl, CURLOPT_USERAGENT, 1);
                    curl_setopt($curl, CURLOPT_POST, 1);
                    $resp = curl_exec($curl);
                    curl_close($curl);        
                    // end

                } catch (Exception $e) {
                    Mage::logException($e);
                }
            }
        }
    }

    public function pendingAction() {

        $increment_id = $this->getRequest()->get("id");
        $order = Mage::getModel('sales/order')->loadByIncrementId($increment_id);

        $this->loadLayout();
        $block = $this->getLayout()->createBlock('Mage_Core_Block_Template', 'espaypaymentmethod', array('template' => 'espaypaymentmethod/pending.phtml'));
        $block->assign(array('incrementId' => $increment_id, 'orderId' => $order->entity_id));
        $this->getLayout()->getBlock('content')->append($block);
        $this->renderLayout();
    }

}
