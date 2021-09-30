<?php
require_once('./system/library/decta/decta_api.php');
require_once('./system/library/decta/decta_logger_opencart.php');

class ControllerExtensionPaymentDecta extends Controller
{
    public function __construct($arg)
    {
        parent::__construct($arg);

        $this->private_key = ($this->config->get('payment_decta_private_key'));
        $this->public_key = ($this->config->get('payment_decta_public_key'));
        $this->iframe = ($this->config->get('payment_decta_iframe'));
    }

    public function index()
    {
        $this->language->load('extension/payment/decta');
        $this->load->model('checkout/order');

        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['token'] = true;

        $data['action'] = $this->url->link('extension/payment/decta/confirm_order', '', 'SSL');
        $payment = null;

        if ($this->iframe) {
            $payment = $this->createPayment();

            if ($payment) {
                $data['src'] = $payment['iframe_checkout'];  
            }
        } else {
            $data['redirect'] = true;
        }

        return $this->load->view('extension/payment/decta', $data);
    }

    public function confirm_order()
    {
        $payment = $this->createPayment();

        if ($payment) {
            $this->response->redirect($payment['full_page_checkout']); 
        }
    }

    public function callback_failure()
    {
        $this->language->load('extension/payment/decta');
        $this->load->model('checkout/order');

        $decta = new DectaApi(
            $this->private_key,
            $this->public_key,
            new DectaLoggerOpencart(new \Log('decta.log'))
        );

        $decta->log_info('Failure callback');
        $url = $this->url->link('checkout/checkout', '', 'SSL');
        $decta->log_info($url);
        $data['url'] = $url;    

        return $this->response->setOutput($this->load->view('extension/payment/decta', $data));
        //$this->response->redirect($this->url->link('checkout/checkout', '', 'SSL'));
    }

    public function callback_success()
    {
        $decta = new DectaApi(
            $this->private_key,
            $this->public_key,
            new DectaLoggerOpencart(new \Log('decta.log'))
        );
        
        $this->language->load('extension/payment/decta');
        $this->load->model('checkout/order');

        $decta->log_info('Success callback');

        $order_id = $_COOKIE['order_id'];
        $payment_id = $_COOKIE['payment_id'];


        if ($decta->was_payment_successful($order_id, $payment_id)) {
            $this->model_checkout_order->addOrderHistory(
                $order_id,
                $this->config->get('payment_decta_completed_status_id'),
                $this->language->get('payment_decta_order_status_success'),
                true
            );
        } else {
            $this->model_checkout_order->addOrderHistory(
                $order_id,
                $this->config->get('payment_decta_failed_status_id'),
                $this->language->get('payment_decta_order_status_verification_failed'),
                true
            );
        }

        $decta->log_info('Success callback processed, redirecting');
        $url = $this->url->link('checkout/success', '', 'SSL');
        $decta->log_info($url);
        $data['url'] = $url; 

        return $this->response->setOutput($this->load->view('extension/payment/decta', $data));
        //$this->response->redirect($this->url->link('checkout/success', '', 'SSL'));
    }

    protected function addUserData($decta, $order_info, &$params)
    {
        $user_data = [
            'email' => $order_info['email'],
            'phone' => $order_info['telephone'],
            'first_name' => $order_info['payment_firstname'],
            'last_name' => $order_info['payment_lastname'],
            'send_to_email' => true
        ];

        $findUser = $decta->getUser($user_data['email'], $user_data['phone']);
        if (!$findUser) {
            if ($decta->createUser($user_data)) {
                $findUser = $decta->getUser($user_data['email'], $user_data['phone']);
            }
        }
        $user_data['original_client'] = $findUser['id'];
        $params['client'] = $user_data;
    }

    protected function createPayment()
    {
        $this->load->model('checkout/order');
        $this->language->load('extension/payment/decta');

        $order_id = (string)$this->session->data['order_id'];
        $order_info = $this->model_checkout_order->getOrder($order_id);

        $language = $this->_language($this->session->data['language']);
        
        $decta = new DectaApi(
            $this->private_key,
            $this->public_key,
            new DectaLoggerOpencart(new \Log('decta.log'))
        );

        $params = array(
            'number' => $order_id,
            'referrer' => 'opencart module ' . DECTA_MODULE_VERSION,
            'language' => $language/*$this->_language('en')*/,
            'success_redirect' => $this->url->link('extension/payment/decta/callback_success', '', 'SSL'),
            'failure_redirect' => $this->url->link('extension/payment/decta/callback_failure&id='.$order_id, '', 'SSL'),
            'currency' => $order_info['currency_code']
        );

        $this->addUserData($decta, $order_info, $params);
        $total = $this->currency->format($order_info['total'], $this->session->data['currency'], '', false);

        $params['products'][] = array(
            'price' => round($total, 2),
            'title' => $this->language->get('payment_decta_invoice_for_payment') . $order_id,
            'quantity' => 1
        );

        $payment = $decta->create_payment($params);
        $orderId = $this->session->data['order_id'];
        if ($payment) {
            $this->model_checkout_order->addOrderHistory(
                (string)$orderId,
                $this->config->get('payment_decta_pending_status_id'),
                $this->language->get('payment_decta_order_status_pending'),
                true
            );
            $set = 'Set-Cookie: payment_id=' . $payment['id'] . '; SameSite=None; Secure';
            header($set, false);
            $set = 'Set-Cookie: order_id=' . $order_id . '; SameSite=None; Secure';
            header($set, false);  
            $decta->log_info('Payment created successfully:' . $payment['id'] . ' ' . $order_id);
            //$decta->log_info('Got checkout url, redirecting');
            //$this->response->redirect($payment['full_page_checkout']);
        } else {
            $decta->log_info('Payment creating error');
            //$decta->log_error('Error getting checkout url, redirecting');
            $this->response->redirect($this->url->link('checkout/checkout', '', 'SSL'));
        }

        return $payment;
    }

    private function _language($lang_id)
    {
        $languages = array('en', 'ru', 'lv', 'lt');
        $lang_id = strtolower(substr($lang_id, 0, 2));
        if (in_array($lang_id, $languages)) {
            return $lang_id;
        } else {
            return 'en';
        }
    }
}
