<?php
namespace CaravelPay\Connector;

/** For platforms whose own transaction records already make processing idempotent. */
final class NullEventLog implements EventLog
{
    public function seen($eventId)
    {
        return false;
    }

    public function remember($eventId)
    {
    }
}
