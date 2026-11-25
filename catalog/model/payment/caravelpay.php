<?php
namespace Opencart\Catalog\Model\Extension\Caravelpay\Payment;

require_once __DIR__ . '/../../../system/library/caravelpay/bootstrap.php';
require_once __DIR__ . '/../../controller/payment/caravelpay.php';

/**
 * Caravel Pay at checkout (OpenCart 4.0.2+ getMethods): shown for carts in US dollars or in a currency Caravel Pay converts, inside the
 * configured geo zone, while the store is connected and its merchant is active.
 */
class Caravelpay extends \Opencart\System\Engine\Model
{
    public function getMethods(array $address = []): array
    {
        $this->load->language('extension/caravelpay/payment/caravelpay');
        if (!$this->config->get('payment_caravelpay_status')) {
            return [];
        }
        if ((int) $this->config->get('payment_caravelpay_geo_zone_id') && $address) {
            $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "zone_to_geo_zone` WHERE `geo_zone_id` = '" . (int) $this->config->get('payment_caravelpay_geo_zone_id')
                . "' AND `country_id` = '" . (int) ($address['country_id'] ?? 0) . "' AND (`zone_id` = '" . (int) ($address['zone_id'] ?? 0) . "' OR `zone_id` = '0')");
            if (!$query->num_rows) {
                return [];
            }
        }
        // US dollars, or a currency Caravel Pay converts to US dollars when the buyer pays.
        if (!\CaravelPay\Currency::isSupported(strtoupper((string) ($this->session->data['currency'] ?? '')))) {
            return [];
        }
        $service = \Opencart\Catalog\Controller\Extension\Caravelpay\Payment\Caravelpay::serviceFor($this->registry);
        if (!$service->connection->checkoutReady()) {
            return [];
        }
        $title = trim((string) $this->config->get('payment_caravelpay_title'));
        $title = '' !== $title ? $title : $this->language->get('text_title');
        return [
            'code' => 'caravelpay',
            'name' => $title,
            'option' => ['caravelpay' => ['code' => 'caravelpay.caravelpay', 'name' => $title]],
            'sort_order' => (int) $this->config->get('payment_caravelpay_sort_order'),
        ];
    }
}
