<?php
namespace CaravelPay\OpenCart;

use CaravelPay\Connector\KeyValue;

/**
 * Connector storage in the extension's caravelpay_setting table (not OpenCart's setting table,
 * which editSetting() rewrites on every save of the extension form).
 */
class Settings implements KeyValue
{
    /** @var Store */
    private $store;

    public function __construct(Store $store)
    {
        $this->store = $store;
    }

    public function get($key, $default = '')
    {
        $value = $this->store->getSetting((string) $key);
        return $value === null ? $default : $value;
    }

    public function set($key, $value, $secret = false)
    {
        $this->store->setSetting((string) $key, (string) $value);
    }

    public function delete($key)
    {
        $this->store->deleteSetting((string) $key);
    }
}
