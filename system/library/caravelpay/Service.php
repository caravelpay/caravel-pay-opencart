<?php
namespace CaravelPay\OpenCart;

use CaravelPay\Connector\Api;
use CaravelPay\Connector\CheckoutStarter;
use CaravelPay\Connector\ConnectInput;
use CaravelPay\Connector\Connection;
use CaravelPay\Connector\EventLog;
use CaravelPay\Connector\Messages;
use CaravelPay\Connector\SdkApi;
use CaravelPay\Connector\Urls;
use CaravelPay\Connector\WebhookProcessor;

/**
 * Wires the connector for OpenCart. Built by the admin and catalog controllers with the registry's
 * db, log and language; tests pass a fake Api.
 */
class Service implements EventLog
{
    const VERSION = '1.2.2';
    const ROUTE = 'extension/caravelpay/payment/caravelpay';

    /** @var Store */
    public $store;
    /** @var Connection */
    public $connection;
    /** @var Messages */
    public $messages;

    /**
     * @param object        $db       OpenCart db.
     * @param string        $prefix   DB_PREFIX.
     * @param object|null   $log      OpenCart Log for caravelpay.log.
     * @param array         $texts    Translated connector texts (language file keys "caravel_<key>").
     * @param Api|null      $api      API (tests).
     * @param callable|null $clock    Clock (tests).
     * @param Store|null    $store    Tables (tests).
     */
    public function __construct($db, $prefix, $log, array $texts = array(), ?Api $api = null, $clock = null, ?Store $store = null)
    {
        $this->store = $store ?: new Store($db, $prefix);
        $api = $api ?: new SdkApi(array('api_base' => Urls::configuredApiBase(), 'user_agent' => 'CaravelPay-OpenCart/' . self::VERSION));
        $this->connection = new Connection(new Settings($this->store), $api, new Logger($log), $clock);
        $this->messages = new Messages($texts);
    }

    /**
     * Connector texts from an OpenCart language array: "caravel_note_paid" -> "note.paid".
     *
     * @param array $language Language data ($this->language->all()).
     * @return array<string,string>
     */
    public static function texts(array $language)
    {
        $texts = array();
        foreach ($language as $key => $value) {
            if (is_string($value) && strpos((string) $key, 'caravel_') === 0) {
                $parts = explode('_', substr($key, 8), 2);
                if (count($parts) === 2) {
                    $texts[$parts[0] . '.' . $parts[1]] = $value;
                }
            }
        }
        return $texts;
    }

    /**
     * @param string $catalogUrl HTTPS catalog URL (HTTP_SERVER / HTTP_CATALOG).
     * @param string $email      Store email.
     * @return ConnectInput
     */
    public static function connectInput($catalogUrl, $email)
    {
        return new ConnectInput($catalogUrl, $email, 'opencart', self::VERSION);
    }

    /**
     * The webhook URL, and the forms checkouts may carry it in (with or without a language argument).
     *
     * @param string $catalogUrl Catalog URL ending in "/".
     * @param string $language   Store language code.
     * @return string[]
     */
    public static function callbackUrls($catalogUrl, $language)
    {
        $base = rtrim((string) $catalogUrl, '/') . '/index.php?route=' . self::ROUTE . '.callback';
        return array($base, $base . '&language=' . rawurlencode((string) $language));
    }

    /**
     * @param Orders $orders       Orders adapter.
     * @param array  $callbackUrls Callback URLs.
     * @return WebhookProcessor
     */
    public function processor(Orders $orders, array $callbackUrls)
    {
        return new WebhookProcessor($this->connection, $orders, $this, $this->messages, $callbackUrls);
    }

    /**
     * @param Orders $orders Orders adapter.
     * @return CheckoutStarter
     */
    public function starter(Orders $orders)
    {
        return new CheckoutStarter($this->connection, $orders, $this->messages);
    }

    public function seen($eventId)
    {
        return $this->store->eventSeen($eventId);
    }

    public function remember($eventId)
    {
        $this->store->rememberEvent($eventId);
    }
}
