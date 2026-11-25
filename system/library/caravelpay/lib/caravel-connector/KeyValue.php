<?php
namespace CaravelPay\Connector;

/**
 * Where a platform keeps the connector's small settings (credentials, status, event ids).
 * Values are strings; the connector JSON-encodes structures itself.
 */
interface KeyValue
{
    /**
     * @param string $key     Key (lowercase letters, digits and underscores).
     * @param string $default Returned when unset.
     * @return string
     */
    public function get($key, $default = '');

    /**
     * @param string $key   Key.
     * @param string $value Value.
     * @param bool   $secret True for values that must be stored encrypted where the platform can.
     */
    public function set($key, $value, $secret = false);

    /**
     * @param string $key Key.
     */
    public function delete($key);
}
