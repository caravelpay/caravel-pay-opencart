<?php
namespace Opencart\Admin\Model\Extension\Caravelpay\Payment;

require_once __DIR__ . '/../../../system/library/caravelpay/bootstrap.php';

/**
 * Caravel Pay: install and uninstall (tables, an On Hold order status).
 */
class Caravelpay extends \Opencart\System\Engine\Model
{
    public function install(): void
    {
        (new \CaravelPay\OpenCart\Store($this->db, DB_PREFIX))->install();
    }

    public function uninstall(): void
    {
        (new \CaravelPay\OpenCart\Store($this->db, DB_PREFIX))->uninstall();
    }

    /**
     * The id of an order status with this name in the admin language, creating it in every
     * language when none exists.
     */
    public function holdStatus(string $name): int
    {
        $query = $this->db->query("SELECT `order_status_id` FROM `" . DB_PREFIX . "order_status` WHERE LOWER(`name`) = '" . $this->db->escape(strtolower($name)) . "' LIMIT 1");
        if ($query->num_rows) {
            return (int) $query->row['order_status_id'];
        }
        $this->load->model('localisation/language');
        $names = [];
        foreach ($this->model_localisation_language->getLanguages() as $language) {
            $names[(int) $language['language_id']] = ['name' => $name];
        }
        $this->load->model('localisation/order_status');
        return (int) $this->model_localisation_order_status->addOrderStatus(['order_status' => $names]);
    }
}
