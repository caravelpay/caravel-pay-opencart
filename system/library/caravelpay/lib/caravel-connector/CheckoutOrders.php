<?php
namespace CaravelPay\Connector;

/**
 * The platform side of starting a payment for an order that keeps its own checkout record.
 */
interface CheckoutOrders
{
    /**
     * @param string|int $ref Order reference.
     * @return mixed Lease, or null when busy.
     */
    public function lock($ref);

    /** @param mixed $lease Lease. */
    public function unlock($lease);

    /**
     * @param mixed $lease Lease.
     * @return bool True while the lease is still ours.
     */
    public function owns($lease);

    /**
     * @param string|int $ref Order reference.
     * @return StartSnapshot|null
     */
    public function startSnapshot($ref);

    /**
     * Persist the request body before the network call, so an ambiguous failure is retried
     * with the identical body (the API then returns the same checkout).
     *
     * @param string|int $ref        Order reference.
     * @param array      $body       Request body.
     * @param int        $attempt    Attempt number.
     * @param string     $merchantId Merchant that sends it.
     */
    public function saveRequest($ref, array $body, $attempt, $merchantId);

    /**
     * Forget the saved request when the API proved nothing was created and the order has no
     * checkout yet (only if the saved body still equals $body).
     *
     * @param string|int $ref  Order reference.
     * @param array      $body Request body.
     */
    public function dropRequest($ref, array $body);

    /**
     * Bind the checkout to the order and the merchant that created it.
     *
     * @param string|int $ref        Order reference.
     * @param Checkout   $checkout   Checkout.
     * @param string     $merchantId Merchant id.
     * @param bool       $isNew      First time this checkout is linked (clear the remembered status).
     * @param string     $note       Order note ('' = none).
     */
    public function linkCheckout($ref, Checkout $checkout, $merchantId, $isNew, $note);

    /**
     * The order_id sent to the API for an attempt: the platform's order id plus a random
     * per-order component, so a restored database can never collide with an older order.
     *
     * @param string|int $ref     Order reference.
     * @param int        $attempt Attempt (1 = no suffix).
     * @return string
     */
    public function reference($ref, $attempt);
}
