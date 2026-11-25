<?php
namespace Opencart\Catalog\Controller\Extension\Caravelpay\Payment;

require_once __DIR__ . '/../../../system/library/caravelpay/bootstrap.php';

use CaravelPay\OpenCart\Orders;
use CaravelPay\OpenCart\Service;

/**
 * Storefront: the "Pay now" button on the confirm step, starting the hosted checkout, and the
 * webhook endpoint index.php?route=extension/caravelpay/payment/caravelpay.callback.
 */
class Caravelpay extends \Opencart\System\Engine\Controller
{
    /** @var Service|null tests inject one */
    public static $service;
    /** @var string|null tests inject the request body */
    public static $rawBody;

    /** The connector service for a registry (shared by the controller and the model). */
    public static function serviceFor($registry): Service
    {
        if (self::$service) {
            return self::$service;
        }
        $language = $registry->get('language');
        $language->load('extension/caravelpay/payment/caravelpay');
        return self::$service = new Service($registry->get('db'), DB_PREFIX, new \Opencart\System\Library\Log('caravelpay.log'), Service::texts($language->all()));
    }

    private function orders(): Orders
    {
        $this->load->model('checkout/order');
        $config = $this->config;
        $currency = $this->currency;
        $paid = array_merge((array) $config->get('config_processing_status'), (array) $config->get('config_complete_status'));
        return new Orders(self::serviceFor($this->registry)->store, $this->model_checkout_order, [
            'pending' => (int) ($config->get('payment_caravelpay_pending_status_id') ?: 1),
            'paid' => (int) ($config->get('payment_caravelpay_paid_status_id') ?: 2),
            'hold' => (int) $config->get('payment_caravelpay_hold_status_id'),
            'expired' => (int) ($config->get('payment_caravelpay_expired_status_id') ?: 7),
            'paid_statuses' => $paid,
        ], static function (array $order) use ($currency) {
            // The total in the order's own currency, rounded as OpenCart shows it.
            return number_format((float) $currency->format((float) $order['total'], (string) $order['currency_code'], (float) $order['currency_value'], false), 2, '.', '');
        });
    }

    /** @return string The confirm-step button. */
    public function index(): string
    {
        $this->load->language('extension/caravelpay/payment/caravelpay');
        $data['language'] = $this->config->get('config_language');
        // '' unless the store passes a fee on to the buyer ("Who pays the fees").
        $data['fee_notice'] = self::serviceFor($this->registry)->messages->feeNotice($this->config->get('payment_caravelpay_fee_mode'));
        // '' for a US dollar cart; otherwise the line saying the charge is in US dollars, with an estimate when a rate is at hand.
        $data['currency_notice'] = $this->currencyNotice();
        return $this->load->view('extension/caravelpay/payment/caravelpay', $data);
    }

    private function currencyNotice(): string
    {
        try {
            $service = self::serviceFor($this->registry);
            $code = strtoupper((string) ($this->session->data['currency'] ?? ''));
            $total = null;
            if (isset($this->session->data['order_id'])) {
                $this->load->model('checkout/order');
                $order = $this->model_checkout_order->getOrder((int) $this->session->data['order_id']);
                if ($order) {
                    $total = (float) $this->currency->format((float) $order['total'], (string) $order['currency_code'], (float) $order['currency_value'], false);
                    $code = strtoupper((string) $order['currency_code']);
                }
            }
            return \CaravelPay\Connector\CurrencyNotice::text($service->connection, new \CaravelPay\OpenCart\Settings($service->store), $code, $total, $this->config->get('payment_caravelpay_fx_buffer'));
        } catch (\Throwable $error) {
            return '';
        }
    }

    /** AJAX from the button: make the order real (Pending), then start the hosted checkout. */
    public function confirm(): void
    {
        $this->load->language('extension/caravelpay/payment/caravelpay');
        $json = [];
        $orderId = (int) ($this->session->data['order_id'] ?? 0);
        $method = $this->session->data['payment_method'] ?? [];
        if (!$orderId) {
            $json['error'] = $this->language->get('error_order');
        } elseif (!is_array($method) || ($method['code'] ?? '') !== 'caravelpay.caravelpay') {
            $json['error'] = $this->language->get('error_payment_method');
        }
        if (!$json) {
            $this->load->model('checkout/order');
            $order = $this->model_checkout_order->getOrder($orderId);
            if (!$order) {
                $json['error'] = $this->language->get('error_order');
            } else {
                if ((int) $order['order_status_id'] === 0) {
                    $this->model_checkout_order->addHistory($orderId, (int) ($this->config->get('payment_caravelpay_pending_status_id') ?: 1));
                }
                $language = (string) $this->config->get('config_language');
                $service = self::serviceFor($this->registry);
                $result = $service->starter($this->orders())->withFeeMode($this->config->get('payment_caravelpay_fee_mode'))->withFxBuffer($this->config->get('payment_caravelpay_fx_buffer'))->start(
                    $orderId,
                    $this->url->link('checkout/success', 'language=' . $language, true),
                    $this->url->link('checkout/checkout', 'language=' . $language, true),
                    Service::callbackUrls(HTTP_SERVER, $language)[0]
                );
                if ($result->ok()) {
                    $json['redirect'] = $result->redirect;
                } else {
                    $json['error'] = $service->messages->render($result->error);
                }
            }
        }
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /** The raw request body. */
    private static function rawBody(): string
    {
        return null !== self::$rawBody ? self::$rawBody : (string) file_get_contents('php://input');
    }

    /** POST webhooks from Caravel Pay, authenticated by the HMAC signature over the raw request. */
    public function callback(): void
    {
        $service = self::serviceFor($this->registry);
        $processor = $service->processor($this->orders(), Service::callbackUrls(HTTP_SERVER, (string) $this->config->get('config_language')));
        // Raw $_SERVER: OpenCart's request object HTML-escapes values.
        $response = $processor->handle(self::rawBody(), $_SERVER, (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $protocol = (string) ($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1');
        $this->response->addHeader($protocol . ' ' . $response->status . ' ' . $response->reason());
        foreach (\CaravelPay\Connector\WebhookResponse::headers() as $name => $value) {
            $this->response->addHeader($name . ': ' . $value);
        }
        $this->response->setOutput($response->json());
    }
}
