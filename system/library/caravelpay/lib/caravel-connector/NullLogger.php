<?php
namespace CaravelPay\Connector;

/** Discards log lines. */
final class NullLogger implements Logger
{
    public function info($message)
    {
    }

    public function warning($message)
    {
    }

    public function error($message)
    {
    }
}
