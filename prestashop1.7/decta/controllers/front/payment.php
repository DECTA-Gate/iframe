<?php
require_once _PS_ROOT_DIR_ . '/modules/decta/lib/payment_wrapper.php';

class DectaPaymentModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        $paymentWrapper = new PaymentWrapper();
        $paymentWrapper->createPayment($this->context, $this->module);
    }
}
