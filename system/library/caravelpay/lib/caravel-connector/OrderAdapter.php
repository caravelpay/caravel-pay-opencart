<?php
namespace CaravelPay\Connector;

/**
 * The platform side of webhook processing: find, lock, read and update one order or invoice.
 */
interface OrderAdapter
{
    /**
     * The order a checkout belongs to, or null when none does (the webhook is acknowledged).
     * Platforms that keep a checkout table look it up by id; platforms that don't call $fetch()
     * (the checkout re-fetched with the signing merchant's credentials) and read its order_id.
     *
     * @param string   $checkoutId Checkout id from the verified webhook.
     * @param callable $fetch      Returns the verified Checkout (throws ApiException).
     * @return string|int|null Order reference.
     */
    public function locate($checkoutId, $fetch);

    /**
     * The merchant id that created this checkout of the order ('' when unknown). Webhooks are
     * signed by it and re-fetches use its credentials.
     *
     * @param string|int $ref        Order reference.
     * @param string     $checkoutId Checkout id.
     * @param string     $signer     Merchant id that signed the webhook (stateless platforms return it).
     * @return string
     */
    public function checkoutMerchant($ref, $checkoutId, $signer);

    /**
     * @param string|int $ref Order reference.
     * @return mixed A lease, or null when another request holds the order.
     */
    public function lock($ref);

    /**
     * @param mixed $lease Lease from lock().
     */
    public function unlock($lease);

    /**
     * Fresh order state for the decision rules, or null when the order is gone.
     *
     * @param string|int $ref      Order reference.
     * @param Checkout   $checkout Verified checkout.
     * @return OrderSnapshot|null
     */
    public function snapshot($ref, Checkout $checkout);

    /**
     * Apply the decision (status change, note, remembered marker). Return false when the platform
     * could not complete a payment, so the webhook is answered 503 and retried.
     *
     * @param string|int $ref      Order reference.
     * @param Decision   $decision Decision.
     * @param Checkout   $checkout Verified checkout.
     * @return bool
     */
    public function apply($ref, Decision $decision, Checkout $checkout);

    /**
     * Keep the event id on the order for support (optional; may do nothing).
     *
     * @param string|int $ref     Order reference.
     * @param string     $eventId Event id.
     * @param string     $type    Event type.
     */
    public function recordEvent($ref, $eventId, $type);
}
