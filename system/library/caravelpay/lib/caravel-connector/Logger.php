<?php
namespace CaravelPay\Connector;

/**
 * Platform log sink. Messages never contain secrets; the connector also redacts known secrets.
 */
interface Logger
{
    /** @param string $message */
    public function info($message);

    /** @param string $message */
    public function warning($message);

    /** @param string $message */
    public function error($message);
}
