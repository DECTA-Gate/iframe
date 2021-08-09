<?php
require_once _PS_ROOT_DIR_ . '/modules/decta/lib/payment_wrapper.php';

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Decta extends PaymentModule
{
    protected $_html = '';
    protected $_postErrors = array();

    public function __construct()
    {
        $this->name = 'decta';
        $this->author = 'Decta';
        $this->version = '3.0';
        $this->tab = 'payments_gateways';
        $this->need_instance = 1;

        $this->controllers = array('payment', 'validation');
        $this->is_eu_compatible = 1;

        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Decta');
        $this->description = $this->l('Accept payments for your products via bank cards');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall the module?');

        if (!count(Currency::checkPaymentCurrencies($this->id))) {
            $this->warning = $this->l('No currency has been set for this module.');
        }

    }

    public function install()
    {
        if (!parent::install() || 
            !$this->registerHook('paymentReturn') || 
            !$this->registerHook('paymentOptions') || 
            !$this->registerHook('displayHeader') ||
            !$this->registerHook('displayPaymentByBinaries')) {
            return false;
        }

        Configuration::updateValue('EXPIRATION_TIME', '30');

        return true;
    }

    public function uninstall()
    {
        if (!Configuration::deleteByName('DECTA_PUBLIC_KEY')
            || !Configuration::deleteByName('DECTA_PRIVATE_KEY')
            || !Configuration::deleteByName('EXPIRATION_TIME')
            || !Configuration::deleteByName('DECTA_IFRAME')
            || !parent::uninstall()) {
            return false;
        }

        return true;
    }

    protected function _postValidation()
    {
        if (Tools::isSubmit('btnSubmit')) {
            if (!Tools::getValue('DECTA_PUBLIC_KEY')) {
                $this->_postErrors[] = $this->l('Public key is required.');
            }

            if (!Tools::getValue('DECTA_PRIVATE_KEY')) {
                $this->_postErrors[] = $this->l('Private key is required.');
            }

            $expirationTime = (int)Tools::getValue('EXPIRATION_TIME');

            if (!$expirationTime) {
                $this->_postErrors[] = $this->l('Expiration time is required.');
            }


            if ($expirationTime < 5) {
                $this->_postErrors[] = $this->l('Expiration time cannot be less than 5 minutes.');
                Configuration::updateValue('EXPIRATION_TIME', '5');
            }
        }
    }

    protected function _postProcess()
    {
        if (Tools::isSubmit('btnSubmit')) {
            Configuration::updateValue('DECTA_PUBLIC_KEY', Tools::getValue('DECTA_PUBLIC_KEY'));
            Configuration::updateValue('DECTA_PRIVATE_KEY', Tools::getValue('DECTA_PRIVATE_KEY'));
            $expirationTime = (int)Tools::getValue('EXPIRATION_TIME');
            $expirationTime = empty($expirationTime) ? 30 : $expirationTime;
            Configuration::updateValue('EXPIRATION_TIME', $expirationTime);
            Configuration::updateValue('DECTA_IFRAME', Tools::getValue('DECTA_IFRAME'));
        }
        $this->_html .= $this->displayConfirmation($this->l('Settings updated'));
    }

    public function getContent()
    {
        if (Tools::isSubmit('btnSubmit')) {
            $this->_postValidation();
            if (!count($this->_postErrors)) {
                $this->_postProcess();
            } else {
                foreach ($this->_postErrors as $err) {
                    $this->_html .= $this->displayError($err);
                }
            }
        } else {
            $this->_html .= '<br />';
        }

        $this->_html .= $this->fetch('module:decta/views/templates/hook/decta_intro.tpl');
        $this->_html .= $this->renderForm();

        return $this->_html;
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return;
        }

        $paymentWrapper = new PaymentWrapper();

        $cart = $params['cart'];

        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);


        if (!$paymentWrapper->checkCurrency($currency_order, $currencies_module)) {
            return;
        }

        $newOption = new PaymentOption();
    
        $newOption->setModuleName($this->name)
            ->setCallToActionText($this->l('Pay with Visa / Mastercard'))
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', array(), true))
            ->setAction($this->context->link->getModuleLink($this->name, 'payment', array(), true))
            ->setAdditionalInformation($this->fetch('module:decta/views/templates/hook/decta_options.tpl'));
  
        return Configuration::get('DECTA_IFRAME') ? [$this->getIframePaymentOption()] : [$newOption];
    }

    public function getIframePaymentOption()
    {
        $iframeOption = new PaymentOption();
        $iframeOption->setModuleName($this->name)
            ->setCallToActionText($this->l('Pay with Visa / Mastercard'))
            ->setBinary(true);
                    
        return $iframeOption;
    }

    public function hookPaymentReturn($params)
    {
        if (!$this->active) {
            return;
        }

        $state = $params['order']->getCurrentState();

        if($state == _PS_OS_PAYMENT_) {
            $this->smarty->assign(array(
                'shop_name' => $this->context->shop->name,
                'total' => Tools::displayPrice($params['order']->getOrdersTotalPaid(), new Currency($params['order']->id_currency), false),
                'status' => 'ok',
                'id_order' => $params['order']->id,
                'contact_url' => $this->context->link->getPageLink('contact', true),
            ));
            if (isset($params['order']->reference) && !empty($params['order']->reference)) {
                $this->smarty->assign('reference', $params['order']->reference);
            }
        } else {
            $this->smarty->assign(array(
                        'status' => 'failed',
                        'contact_url' => $this->context->link->getPageLink('contact', true),
                    ));
        }

        return $this->fetch('module:decta/views/templates/hook/payment_return.tpl');
    }

    public function renderForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('API Keys'),
                    'icon' => 'icon-envelope',
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('Public key'),
                        'name' => 'DECTA_PUBLIC_KEY',
                        'required' => true,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Secret key'),
                        'name' => 'DECTA_PRIVATE_KEY',
                        'required' => true,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Payment expiration time (min)'),
                        'name' => 'EXPIRATION_TIME',
                        'required' => true,
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Use iframe'),
                        'name' => 'DECTA_IFRAME',
                        'required' => false,
                        'values' => array(
                            array(
                                'id' => 'iframe_on',
                                'value' => 1,
                                'label' => $this->trans('Yes', array())
                            ),
                            array(
                                'id' => 'iframe_off',
                                'value' => 0,
                                'label' => $this->trans('No', array())
                            )
                        )
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = array();
        $helper->id = (int) Tools::getValue('id_carrier');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'btnSubmit';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($fields_form));
    }

    public function getConfigFieldsValues()
    {
        return array(
            'DECTA_PUBLIC_KEY' => Tools::getValue('DECTA_PUBLIC_KEY', Configuration::get('DECTA_PUBLIC_KEY')),
            'DECTA_PRIVATE_KEY' => Tools::getValue('DECTA_PRIVATE_KEY', Configuration::get('DECTA_PRIVATE_KEY')),
            'EXPIRATION_TIME' => Tools::getValue('EXPIRATION_TIME', Configuration::get('EXPIRATION_TIME')),
            'DECTA_IFRAME' => Tools::getValue('DECTA_IFRAME', Configuration::get('DECTA_IFRAME')),

        );
    }

    public function hookDisplayHeader()
    {
        $this->context->controller->addCSS($this->_path . 'css/decta.css', 'all');
    }

    public function hookDisplayPaymentByBinaries()
    {
        if (Configuration::get('DECTA_IFRAME')) {
            $paymentWrapper = new PaymentWrapper();
            return $paymentWrapper->createPayment($this->context, $this); 
        }
    }
}
