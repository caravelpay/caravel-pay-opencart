<?php
namespace Opencart\Admin\Controller\Extension\Caravelpay\Payment;

require_once __DIR__ . '/../../../system/library/caravelpay/bootstrap.php';

use CaravelPay\Connector\Urls;
use CaravelPay\OpenCart\Service;

/**
 * Caravel Pay settings (Extensions › Payments › Caravel Pay). Saving a payout wallet connects the
 * store with POST /v1/connect: on the first save, when the wallet changes, or with "Save and reconnect".
 */
class Caravelpay extends \Opencart\System\Engine\Controller
{
    /** @var Service|null tests inject one */
    public static $service;

    private function service(): Service
    {
        if (self::$service) {
            return self::$service;
        }
        return new Service($this->db, DB_PREFIX, new \Opencart\System\Library\Log('caravelpay.log'), Service::texts($this->language->all()));
    }

    public function index(): void
    {
        $this->load->language('extension/caravelpay/payment/caravelpay');
        $this->document->setTitle($this->language->get('heading_title'));
        $token = 'user_token=' . $this->session->data['user_token'];
        $data['breadcrumbs'] = [
            ['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token)],
            ['text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=payment')],
            ['text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/caravelpay/payment/caravelpay', $token)],
        ];
        $data['save'] = $this->url->link('extension/caravelpay/payment/caravelpay.save', $token);
        $data['reconnect'] = $this->url->link('extension/caravelpay/payment/caravelpay.save', $token . '&reconnect=1');
        $data['back'] = $this->url->link('marketplace/extension', $token . '&type=payment');

        foreach (['wallet' => '', 'title' => '', 'pending_status_id' => 1, 'paid_status_id' => 2, 'hold_status_id' => 0, 'expired_status_id' => 7, 'geo_zone_id' => 0, 'status' => 0, 'sort_order' => 0] as $key => $default) {
            $value = $this->config->get('payment_caravelpay_' . $key);
            $data['payment_caravelpay_' . $key] = null === $value || '' === $value ? $default : $value;
        }
        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $this->load->model('localisation/geo_zone');
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        $service = $this->service();
        // Who pays the fees: "seller" (the default), "split" or "buyer".
        $data['payment_caravelpay_fee_mode'] = \CaravelPay\FeeMode::normalize($this->config->get('payment_caravelpay_fee_mode'));
        $data['entry_fee_mode'] = $service->messages->render('fees.setting');
        $data['help_fee_mode'] = $service->messages->render('fees.help');
        $data['fee_modes'] = $service->messages->feeOptions();
        $data['payment_caravelpay_fx_buffer'] = (string) (\CaravelPay\Currency::bufferBps($this->config->get('payment_caravelpay_fx_buffer')) / 100);
        $data['entry_fx_buffer'] = \CaravelPay\Currency::bufferLabel();
        $data['help_fx_buffer'] = \CaravelPay\Currency::bufferHelp();
        $view = $service->connection->statusView();
        $data['caravel_state'] = $view['state'];
        $data['caravel_label'] = $service->messages->render($view['label']);
        $data['caravel_detail'] = $service->messages->pair($view['detail']);
        $data['caravel_hint'] = $service->messages->pair($view['hint']);
        $data['webhook_url'] = Service::callbackUrls(HTTP_CATALOG, (string) $this->config->get('config_language'))[0];

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('extension/caravelpay/payment/caravelpay', $data));
    }

    public function save(): void
    {
        $this->load->language('extension/caravelpay/payment/caravelpay');
        $json = [];
        if (!$this->user->hasPermission('modify', 'extension/caravelpay/payment/caravelpay')) {
            $json['error']['warning'] = $this->language->get('error_permission');
        }
        $wallet = trim((string) ($this->request->post['payment_caravelpay_wallet'] ?? ''));
        if (!$json && '' !== $wallet && null === Urls::address($wallet)) {
            $json['error']['wallet'] = $this->language->get('error_wallet');
        }
        if (!$json && '' === Urls::configuredApiBase()) {
            $json['error']['warning'] = $this->language->get('error_api_base');
        }
        if (!$json) {
            $post = [];
            foreach (['wallet', 'title', 'pending_status_id', 'paid_status_id', 'hold_status_id', 'expired_status_id', 'geo_zone_id', 'status', 'sort_order'] as $key) {
                $value = $this->request->post['payment_caravelpay_' . $key] ?? '';
                $post['payment_caravelpay_' . $key] = 'wallet' === $key ? $wallet : ('title' === $key ? substr(trim(strip_tags((string) $value)), 0, 120) : (int) $value);
            }
            $post['payment_caravelpay_fee_mode'] = \CaravelPay\FeeMode::normalize($this->request->post['payment_caravelpay_fee_mode'] ?? '');
            // A percentage, 0 to 5; anything else is saved as 0.
            $post['payment_caravelpay_fx_buffer'] = (string) (\CaravelPay\Currency::bufferBps($this->request->post['payment_caravelpay_fx_buffer'] ?? '') / 100);
            $this->load->model('setting/setting');
            $this->model_setting_setting->editSetting('payment_caravelpay', $post);
            $json['success'] = $this->language->get('text_success');
            if ('' !== $wallet) {
                $result = $this->service()->connection->afterSave($wallet, !empty($this->request->get['reconnect']), Service::connectInput(HTTP_CATALOG, (string) $this->config->get('config_email')));
                if ('failed' === $result['result']) {
                    unset($json['success']);
                    $json['error']['warning'] = $this->language->get('text_connect_failed');
                } elseif ('connected' === $result['result']) {
                    $json['success'] = $this->language->get(!empty($result['changed']) ? 'text_success_changed' : 'text_success_connected');
                }
            }
        }
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function install(): void
    {
        $this->load->language('extension/caravelpay/payment/caravelpay');
        $this->load->model('extension/caravelpay/payment/caravelpay');
        $this->model_extension_caravelpay_payment_caravelpay->install();
        $hold = $this->model_extension_caravelpay_payment_caravelpay->holdStatus($this->language->get('text_hold_status_name'));
        $this->load->model('setting/setting');
        $this->model_setting_setting->editSetting('payment_caravelpay', [
            'payment_caravelpay_title' => '',
            'payment_caravelpay_pending_status_id' => 1,
            'payment_caravelpay_paid_status_id' => 2,
            'payment_caravelpay_hold_status_id' => $hold,
            'payment_caravelpay_expired_status_id' => 7,
            'payment_caravelpay_fee_mode' => \CaravelPay\FeeMode::SELLER,
            'payment_caravelpay_fx_buffer' => '0',
            'payment_caravelpay_status' => 0,
            'payment_caravelpay_sort_order' => 0,
        ]);
    }

    public function uninstall(): void
    {
        $this->service()->connection->forget();
        $this->load->model('extension/caravelpay/payment/caravelpay');
        $this->model_extension_caravelpay_payment_caravelpay->uninstall();
    }
}
