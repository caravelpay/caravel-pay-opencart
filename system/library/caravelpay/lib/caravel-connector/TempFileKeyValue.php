<?php
namespace CaravelPay\Connector;

/**
 * A small KeyValue in one JSON file in the system's temporary directory, for platforms that give a module no storage
 * of its own. It is a cache for values that are public and cheap to fetch again (today's exchange rate for the line
 * buyers read): nothing else belongs here, and secrets are never written. Every failure (no temporary directory,
 * not writable, a broken file) reads as "nothing stored", so callers simply fetch again.
 */
final class TempFileKeyValue implements KeyValue
{
    const MAX_BYTES = 16384;

    /** @var string */
    private $file;

    /**
     * @param string      $name Names the file (hashed), for example "hostbill|mch_…": one file per store.
     * @param string|null $dir  Directory; the system's temporary directory when null.
     */
    public function __construct($name, $dir = null)
    {
        $dir = $dir !== null ? (string) $dir : (string) sys_get_temp_dir();
        $this->file = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'caravelpay-' . substr(hash('sha256', (string) $name), 0, 24) . '.json';
    }

    /** @return string The file this store reads and writes. */
    public function path()
    {
        return $this->file;
    }

    /** @return array<string,string> */
    private function read()
    {
        if (!is_file($this->file) || !is_readable($this->file) || filesize($this->file) > self::MAX_BYTES) {
            return array();
        }
        $data = json_decode((string) file_get_contents($this->file), true);
        $values = array();
        foreach (is_array($data) ? $data : array() as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $values[$key] = $value;
            }
        }
        return $values;
    }

    /** @param array<string,string> $values */
    private function write(array $values)
    {
        $dir = dirname($this->file);
        $json = json_encode($values);
        if (!is_string($json) || strlen($json) > self::MAX_BYTES || !is_dir($dir) || !is_writable($dir)) {
            return;
        }
        // Written beside the file and renamed over it, so a reader never sees half a file.
        $temp = $this->file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temp, $json, LOCK_EX) !== strlen($json) || !rename($temp, $this->file)) {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    public function get($key, $default = '')
    {
        $values = $this->read();
        return isset($values[$key]) ? $values[$key] : $default;
    }

    public function set($key, $value, $secret = false)
    {
        if ($secret) {
            return; // Never in a temporary file.
        }
        $values = $this->read();
        $values[(string) $key] = (string) $value;
        $this->write($values);
    }

    public function delete($key)
    {
        $values = $this->read();
        if (isset($values[$key])) {
            unset($values[$key]);
            $this->write($values);
        }
    }
}
