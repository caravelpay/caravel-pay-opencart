<?php
namespace CaravelPay\Connector;

/**
 * Processed webhook event ids, so retries are answered 200 without doing the work again.
 */
interface EventLog
{
    /**
     * @param string $eventId Event id.
     * @return bool
     */
    public function seen($eventId);

    /**
     * @param string $eventId Event id.
     */
    public function remember($eventId);
}
