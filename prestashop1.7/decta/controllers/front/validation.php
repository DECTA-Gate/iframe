<?php
require_once _PS_ROOT_DIR_ . '/modules/decta/lib/decta_api.php';
require_once _PS_ROOT_DIR_ . '/modules/decta/lib/decta_logger_prestashop.php';

class DectaValidationModuleFrontController extends ModuleFrontController
{
	private $decta;

	public function initContent()
    {
        parent::initContent();

        $this->decta = new DectaAPI(
			Configuration::get('DECTA_PRIVATE_KEY'),
			Configuration::get('DECTA_PUBLIC_KEY'),
			Configuration::get('EXPIRATION_TIME'),
			Configuration::get('DECTA_IFRAME'),
			new DectaLoggerPrestashop()
		);	

		$redirectingUrl = 'index.php?controller=order&step=1';

		$this->decta->log_info('Processing success callback');
		$cart = $this->context->cart;


		if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active) {
			$this->decta->log_error('Internal prestashop error occured', [$cart->id_customer, $cart->id_address_delivery, $cart->id_address_invoice]);
			//Tools::redirect('index.php?controller=order&step=1');
		}

		$customer = new Customer($cart->id_customer);
		if (!Validate::isLoadedObject($customer)) {
			$this->decta->log_error('Internal prestashop customer error occured', $customer);
			//Tools::redirect('index.php?controller=order&step=1');
		}

		$cartId = $this->context->cookie->decta_cart_id;
		$paymentId = $this->context->cookie->decta_payment_id;

	    $order = null;
		$orderId = null;

		if (!Configuration::get('DECTA_IFRAME')) {
			$orderId = $this->context->cookie->decta_order_id;
			$order = new Order($orderId);
			if (!Validate::isLoadedObject($order)) {
				$this->decta->log_info('Internal prestashop order error occured order_id = ' .  $orderId);
				//Tools::redirect('index.php?controller=order&step=1');
			}
		} else {
			$total = $this->context->cookie->decta_total;
			$currencyId = $this->context->cookie->decta_currencyId;
			$this->module->validateOrder($cart->id, _PS_OS_BANKWIRE_, $total, $this->module->l('Visa / MasterCard'), $this->module->l('Payment successful'), null, (int)$currencyId, false, $customer->secure_key);
			$orderId = Order::getIdByCartId((int)$cartId);
			$order = new Order($orderId);
		}
				
		$redirectingUrl = 'index.php?controller=order-confirmation&id_cart='.$cartId.'&id_module='.$this->module->id.'&id_order='.$orderId.'&key='.$customer->secure_key;
		
		if ($this->decta->was_payment_successful($cartId, $paymentId)) {
			$order->setCurrentState(Configuration::get('PS_OS_PAYMENT'));
			$this->decta->log_info('Verification order #' . $cartId . ' done, redirecting to ' . $redirectingUrl);
		} else {
			$order->setCurrentState(Configuration::get('PS_OS_ERROR'));
			$this->decta->log_info('Verification order #' . $cartId . ' failed, redirecting to ' . $redirectingUrl);
		}

		$this->context->smarty->assign('url', $redirectingUrl);
		$this->setTemplate('module:decta/views/templates/hook/order_return.tpl');
    }
}
