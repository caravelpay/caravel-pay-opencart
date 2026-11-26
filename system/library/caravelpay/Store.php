<?php
namespace CaravelPay\OpenCart;

/**
 * The extension's own tables (all SQL of the extension lives here):
 *
 *   caravelpay_order    order_id PK, nonce, request (JSON), attempt, request_merchant, checkout_id,
 *                       checkout_url, expires_at, marker, paid_amount, tx_hash, events (JSON)
 *   caravelpay_checkout checkout_id PK, order_id, merchant_id
 *   caravelpay_event    event_id PK
 *   caravelpay_setting  `key` PK, value: credentials and merchant status (never shown or logged)
 */
class Store
{
    /** @var object OpenCart DB (query(), escape()). */
    protected $db;
    /** @var string */
    protected $prefix;

    /**
     * @param object $db     OpenCart registry db.
     * @param string $prefix DB_PREFIX.
     */
    public function __construct($db, $prefix)
    {
        $this->db = $db;
        $this->prefix = (string) $prefix;
    }

    /** CREATE TABLE statements (admin install). */
    public function install()
    {
        $p = $this->prefix;
        $this->db->query("CREATE TABLE IF NOT EXISTS `{$p}caravelpay_order` (
            `order_id` INT(11) NOT NULL,
            `nonce` VARCHAR(16) NOT NULL DEFAULT '',
            `request` TEXT NULL,
            `attempt` INT(11) NOT NULL DEFAULT 1,
            `request_merchant` VARCHAR(64) NOT NULL DEFAULT '',
            `checkout_id` VARCHAR(140) NOT NULL DEFAULT '',
            `checkout_url` VARCHAR(2048) NOT NULL DEFAULT '',
            `expires_at` VARCHAR(32) NOT NULL DEFAULT '',
            `marker` VARCHAR(200) NOT NULL DEFAULT '',
            `paid_amount` VARCHAR(20) NOT NULL DEFAULT '',
            `tx_hash` VARCHAR(66) NOT NULL DEFAULT '',
            `events` TEXT NULL,
            `date_modified` DATETIME NOT NULL,
            PRIMARY KEY (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->query("CREATE TABLE IF NOT EXISTS `{$p}caravelpay_checkout` (
            `checkout_id` VARCHAR(140) NOT NULL,
            `order_id` INT(11) NOT NULL,
            `merchant_id` VARCHAR(64) NOT NULL,
            PRIMARY KEY (`checkout_id`),
            KEY `order_id` (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->query("CREATE TABLE IF NOT EXISTS `{$p}caravelpay_event` (
            `event_id` VARCHAR(140) NOT NULL,
            `date_added` DATETIME NOT NULL,
            PRIMARY KEY (`event_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->query("CREATE TABLE IF NOT EXISTS `{$p}caravelpay_setting` (
            `key` VARCHAR(64) NOT NULL,
            `value` MEDIUMTEXT NOT NULL,
            PRIMARY KEY (`key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** Uninstall: drop the event log only; credentials and order records stay for late webhooks after a reinstall. */
    public function uninstall()
    {
        $this->db->query("DROP TABLE IF EXISTS `{$this->prefix}caravelpay_event`");
    }

    /**
     * @param int $orderId Order id.
     * @return array
     */
    public function record($orderId)
    {
        $query = $this->db->query("SELECT * FROM `{$this->prefix}caravelpay_order` WHERE `order_id` = '" . (int) $orderId . "'");
        $row = $query->num_rows ? $query->row : null;
        $request = $row ? json_decode((string) $row['request'], true) : null;
        $events = $row ? json_decode((string) $row['events'], true) : null;
        return array(
            'nonce' => $row ? (string) $row['nonce'] : '',
            'request' => is_array($request) ? $request : null,
            'attempt' => $row ? max(1, (int) $row['attempt']) : 1,
            'request_merchant' => $row ? (string) $row['request_merchant'] : '',
            'checkout_id' => $row ? (string) $row['checkout_id'] : '',
            'checkout_url' => $row ? (string) $row['checkout_url'] : '',
            'expires_at' => $row ? (string) $row['expires_at'] : '',
            'marker' => $row ? (string) $row['marker'] : '',
            'paid_amount' => $row ? (string) $row['paid_amount'] : '',
            'tx_hash' => $row ? (string) $row['tx_hash'] : '',
            'events' => is_array($events) ? $events : array(),
        );
    }

    /**
     * Insert or update an order's record.
     *
     * @param int   $orderId Order id.
     * @param array $fields  Field => value (request/events are JSON-encoded).
     */
    public function save($orderId, array $fields)
    {
        $allowed = array('nonce', 'request', 'attempt', 'request_merchant', 'checkout_id', 'checkout_url', 'expires_at', 'marker', 'paid_amount', 'tx_hash', 'events');
        $sets = array();
        foreach ($fields as $name => $value) {
            if (!in_array($name, $allowed, true)) {
                continue;
            }
            if (in_array($name, array('request', 'events'), true)) {
                $sets[] = "`{$name}` = " . ($value === null ? 'NULL' : "'" . $this->db->escape(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "'");
            } else {
                $sets[] = "`{$name}` = '" . $this->db->escape((string) $value) . "'";
            }
        }
        $sets[] = '`date_modified` = NOW()';
        $this->db->query("INSERT INTO `{$this->prefix}caravelpay_order` SET `order_id` = '" . (int) $orderId . "', " . implode(', ', $sets)
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $sets));
    }

    public function link($checkoutId, $orderId, $merchantId)
    {
        $this->db->query("INSERT INTO `{$this->prefix}caravelpay_checkout` SET `checkout_id` = '" . $this->db->escape($checkoutId) . "', `order_id` = '" . (int) $orderId
            . "', `merchant_id` = '" . $this->db->escape($merchantId) . "' ON DUPLICATE KEY UPDATE `merchant_id` = `merchant_id`");
    }

    /** @return array|null {order_id, merchant_id} */
    public function findCheckout($checkoutId)
    {
        $query = $this->db->query("SELECT `order_id`, `merchant_id` FROM `{$this->prefix}caravelpay_checkout` WHERE `checkout_id` = '" . $this->db->escape($checkoutId) . "'");
        return $query->num_rows ? array('order_id' => (int) $query->row['order_id'], 'merchant_id' => (string) $query->row['merchant_id']) : null;
    }

    /** @return array<string,string> checkout id => merchant id */
    public function checkouts($orderId)
    {
        $query = $this->db->query("SELECT `checkout_id`, `merchant_id` FROM `{$this->prefix}caravelpay_checkout` WHERE `order_id` = '" . (int) $orderId . "'");
        $map = array();
        foreach ($query->rows as $row) {
            $map[(string) $row['checkout_id']] = (string) $row['merchant_id'];
        }
        return $map;
    }

    /** A MySQL named lock per order (43 chars: always within MySQL 5.7+/8.0's 64-char limit). */
    public function lock($orderId)
    {
        $query = $this->db->query("SELECT GET_LOCK('" . $this->db->escape(self::lockName($orderId)) . "', 0) AS `locked`");
        return (int) $query->row['locked'] === 1;
    }

    public function unlock($orderId)
    {
        $this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape(self::lockName($orderId)) . "') AS `released`");
    }

    /**
     * @param int         $orderId Order id.
     * @param string|null $dbName  Database name (defaults to DB_DATABASE).
     * @return string
     */
    public static function lockName($orderId, $dbName = null)
    {
        $db = $dbName !== null ? (string) $dbName : (string) DB_DATABASE;
        return 'caravelpay_' . substr(hash('sha256', $db . ':' . (int) $orderId), 0, 32);
    }

    public function eventSeen($eventId)
    {
        return (bool) $this->db->query("SELECT 1 FROM `{$this->prefix}caravelpay_event` WHERE `event_id` = '" . $this->db->escape($eventId) . "'")->num_rows;
    }

    public function rememberEvent($eventId)
    {
        $this->db->query("INSERT IGNORE INTO `{$this->prefix}caravelpay_event` SET `event_id` = '" . $this->db->escape($eventId) . "', `date_added` = NOW()");
    }

    public function getSetting($key)
    {
        $query = $this->db->query("SELECT `value` FROM `{$this->prefix}caravelpay_setting` WHERE `key` = '" . $this->db->escape($key) . "'");
        return $query->num_rows ? (string) $query->row['value'] : null;
    }

    public function setSetting($key, $value)
    {
        $this->db->query("INSERT INTO `{$this->prefix}caravelpay_setting` SET `key` = '" . $this->db->escape($key) . "', `value` = '" . $this->db->escape($value)
            . "' ON DUPLICATE KEY UPDATE `value` = '" . $this->db->escape($value) . "'");
    }

    public function deleteSetting($key)
    {
        $this->db->query("DELETE FROM `{$this->prefix}caravelpay_setting` WHERE `key` = '" . $this->db->escape($key) . "'");
    }
}
