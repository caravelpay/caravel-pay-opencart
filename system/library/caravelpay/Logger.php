<?php
namespace CaravelPay\OpenCart;

use CaravelPay\Connector\Logger as ConnectorLogger;

/**
 * Writes to storage/logs/caravelpay.log through OpenCart's Log class.
 */
class Logger implements ConnectorLogger
{
    /** @var object|null */
    private $log;

    /** @param object|null $log An OpenCart Log (write()). */
    public function __construct($log)
    {
        $this->log = $log;
    }

    public function info($message)
    {
        $this->write('INFO', $message);
    }

    public function warning($message)
    {
        $this->write('WARNING', $message);
    }

    public function error($message)
    {
        $this->write('ERROR', $message);
    }

    private function write($level, $message)
    {
        if ($this->log && method_exists($this->log, 'write')) {
            $this->log->write('Caravel Pay ' . $level . ': ' . substr((string) $message, 0, 900));
        }
    }
}
