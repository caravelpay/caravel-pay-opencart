<?php
namespace CaravelPay\Connector;

/**
 * In-memory KeyValue, for platforms that hand settings over as an array (Blesta meta, Laravel
 * config) and for tests. all() returns what to persist.
 */
final class ArrayKeyValue implements KeyValue
{
    /** @var array<string,string> */
    private $values;

    /**
     * @param array $values Initial values.
     */
    public function __construct(array $values = array())
    {
        $this->values = array();
        foreach ($values as $key => $value) {
            if (is_string($key) && (is_string($value) || is_int($value))) {
                $this->values[$key] = (string) $value;
            }
        }
    }

    public function get($key, $default = '')
    {
        return isset($this->values[$key]) ? $this->values[$key] : $default;
    }

    public function set($key, $value, $secret = false)
    {
        $this->values[$key] = (string) $value;
    }

    public function delete($key)
    {
        unset($this->values[$key]);
    }

    /** @return array<string,string> */
    public function all()
    {
        return $this->values;
    }
}
