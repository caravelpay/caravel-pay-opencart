<?php
namespace CaravelPay\Connector;

/**
 * Handles one webhook: verify the signature, deduplicate, find and lock the order, re-fetch the
 * checkout with signed GET /v1/checkouts/{id}, then apply the order rules. The webhook says what
 * to look at; the signed API says what is true.
 *
 * Answers: 200 done / duplicate / ignored, 400 malformed, 401 not signed by a known merchant,
 * 409 the checkout doesn't fit the order, 503 retry later (busy, API unreachable, failure).
 */
final class WebhookProcessor
{
    const EVENT_TYPES = array('checkout.paid', 'checkout.underpaid', 'checkout.expired', 'checkout.review');
    const EVENT_ID = '/^evt_[A-Za-z0-9_-]{4,128}$/D';
    const MAX_BODY = 262144;
    const WINDOW = 300;

    /** @var Connection */
    private $connection;
    /** @var OrderAdapter */
    private $orders;
    /** @var EventLog */
    private $events;
    /** @var OrderSync */
    private $sync;
    /** @var string[] */
    private $callbackUrls;

    /**
     * @param Connection   $connection   Credentials and API.
     * @param OrderAdapter $orders       Platform orders.
     * @param EventLog     $events       Processed event ids.
     * @param Messages     $messages     Texts for notes.
     * @param string[]     $callbackUrls Every URL this platform registers as callback_url (from its
     *                                   own configuration, never from request headers).
     */
    public function __construct(Connection $connection, OrderAdapter $orders, EventLog $events, Messages $messages, array $callbackUrls)
    {
        $this->connection = $connection;
        $this->orders = $orders;
        $this->events = $events;
        $this->sync = new OrderSync($messages);
        $this->callbackUrls = array_values(array_unique(array_filter($callbackUrls, 'is_string')));
    }

    /**
     * @param string $body    Raw request body.
     * @param array  $headers Request headers (any case; "Caravel-Merchant", "HTTP_CARAVEL_MERCHANT" and "caravel_merchant" all work).
     * @param string $method  Request method.
     * @return WebhookResponse
     */
    public function handle($body, array $headers, $method = 'POST')
    {
        $log = $this->connection->logger();
        if (strtoupper((string) $method) !== 'POST') {
            return new WebhookResponse(405, array('error' => 'method_not_allowed'));
        }
        $headers = self::normalizeHeaders($headers);
        $merchant = isset($headers['caravel-merchant']) ? $headers['caravel-merchant'] : '';
        $reason = $this->authenticate((string) $body, isset($headers['caravel-timestamp']) ? $headers['caravel-timestamp'] : '', isset($headers['caravel-signature']) ? $headers['caravel-signature'] : '', $merchant);
        if ($reason === 'not_connected') {
            $log->warning('Webhook received while the store is not connected; answered 503 so it is retried.');
            return new WebhookResponse(503, array('error' => 'not_connected'));
        }
        if ($reason !== '') {
            $log->warning('Webhook rejected: ' . $reason . '.');
            return new WebhookResponse(401, array('error' => $reason));
        }
        $event = json_decode((string) $body, true);
        $eventId = is_array($event) && isset($event['id']) && is_string($event['id']) ? $event['id'] : '';
        $type = is_array($event) && isset($event['type']) && is_string($event['type']) ? $event['type'] : '';
        $checkoutId = is_array($event) && isset($event['data']['id']) && is_string($event['data']['id']) ? $event['data']['id'] : '';
        $headerEvent = isset($headers['caravel-event']) ? $headers['caravel-event'] : '';
        if (!preg_match(self::EVENT_ID, $eventId) || !preg_match('/^[a-z_]{1,32}\.[a-z_]{1,32}$/D', $type) || !preg_match(Checkout::ID, $checkoutId)
            || ($headerEvent !== '' && $headerEvent !== $eventId)) {
            $log->warning('Webhook rejected: malformed event.');
            return new WebhookResponse(400, array('error' => 'invalid_event'));
        }
        $label = 'Webhook ' . $eventId . ' (' . $type . ', ' . $checkoutId . ')';
        if (!in_array($type, self::EVENT_TYPES, true)) {
            $log->info($label . ': event type not handled by this version; acknowledged.');
            return new WebhookResponse(200, array('received' => true, 'ignored' => true));
        }
        if ($this->events->seen($eventId)) {
            $log->info($label . ': duplicate, already processed.');
            return new WebhookResponse(200, array('received' => true, 'duplicate' => true));
        }
        $connection = $this->connection;
        $signer = $connection->credentialsFor($merchant);
        $fetched = null;
        $fetch = function () use ($connection, $signer, $checkoutId, &$fetched) {
            if ($fetched === null) {
                $fetched = self::fetchCheckout($connection, $signer, $checkoutId);
            }
            return $fetched;
        };
        try {
            $ref = $this->orders->locate($checkoutId, $fetch);
        } catch (ApiException $error) {
            if ($error->httpStatus() === 404) {
                $log->warning($label . ': the checkout is not one of ' . $merchant . '\'s; acknowledged without changes.');
                $this->events->remember($eventId);
                return new WebhookResponse(200, array('received' => true, 'ignored' => true));
            }
            $log->warning($label . ': re-fetching the checkout failed (' . $error->errorCode() . '); answered 503 so it is retried.');
            return new WebhookResponse(503, array('error' => 'verification_pending'));
        } catch (\Throwable $error) {
            $log->error($label . ': finding the order failed (' . get_class($error) . '); answered 503 so it is retried.');
            return new WebhookResponse(503, array('error' => 'processing_failed'));
        }
        if ($ref === null || $ref === '' || $ref === 0) {
            $log->warning($label . ': no order uses this checkout; acknowledged without changes.');
            $this->events->remember($eventId);
            return new WebhookResponse(200, array('received' => true, 'ignored' => true));
        }
        $lease = $this->orders->lock($ref);
        if ($lease === null || $lease === false) {
            $log->info($label . ': order ' . $ref . ' is busy; answered 503 so it is retried.');
            return new WebhookResponse(503, array('error' => 'order_busy'));
        }
        try {
            if ($this->events->seen($eventId)) {
                return new WebhookResponse(200, array('received' => true, 'duplicate' => true));
            }
            $owner = $this->orders->checkoutMerchant($ref, $checkoutId, $merchant);
            if ($owner === '' || !hash_equals($owner, $merchant)) {
                // Signed by a merchant of this store, but not the one that created this checkout.
                $log->warning($label . ': signed by ' . $merchant . ', but the checkout belongs to ' . ($owner !== '' ? $owner : 'no known merchant') . '; ignored.');
                return new WebhookResponse(409, array('error' => 'merchant_mismatch'));
            }
            $fetched = null;
            try {
                $checkout = $fetch();
            } catch (ApiException $error) {
                $log->warning($label . ': re-fetching the checkout failed (' . $error->errorCode() . '); answered 503 so it is retried.');
                return new WebhookResponse(503, array('error' => 'verification_pending'));
            }
            $snapshot = $this->orders->snapshot($ref, $checkout);
            if ($snapshot === null) {
                return new WebhookResponse(503, array('error' => 'order_unavailable'));
            }
            $decision = $this->sync->decide($snapshot, $checkout);
            if ($decision->succeeded()) {
                if (!$this->orders->apply($ref, $decision, $checkout)) {
                    $decision = Decision::make('failed', 503, 'The platform could not complete the payment.');
                } else {
                    $this->orders->recordEvent($ref, $eventId, $type);
                    $this->events->remember($eventId);
                }
            }
            $log->info($label . ': order ' . $ref . ' ' . $decision->result . ' (checkout status ' . $checkout->status() . '). ' . $decision->log);
            return new WebhookResponse($decision->http, array('received' => $decision->http < 300, 'result' => $decision->result));
        } catch (\Throwable $error) {
            $log->error($label . ': processing failed (' . get_class($error) . '); answered 503 so it is retried.');
            return new WebhookResponse(503, array('error' => 'processing_failed'));
        } finally {
            $this->orders->unlock($lease);
        }
    }

    /**
     * Verify the Caravel-* signature, keyed with the secret of the merchant named in
     * Caravel-Merchant (the current one, or a replaced one within its 7 days).
     *
     * @param string $body      Raw body.
     * @param string $timestamp Caravel-Timestamp.
     * @param string $signature Caravel-Signature.
     * @param string $merchant  Caravel-Merchant.
     * @return string '' when valid, otherwise the reason (for the log).
     */
    public function authenticate($body, $timestamp, $signature, $merchant)
    {
        $credentials = $this->connection->credentialsFor((string) $merchant);
        if (!$this->connection->isConnected() && $credentials === null) {
            return 'not_connected';
        }
        if (!is_string($body) || strlen($body) > self::MAX_BODY) {
            return 'body_too_large';
        }
        if ($credentials === null) {
            return 'unknown_merchant';
        }
        $now = $this->connection->now();
        if (!is_string($timestamp) || !preg_match('/^[0-9]{1,12}$/D', $timestamp) || abs($now - (int) $timestamp) > self::WINDOW) {
            return 'timestamp_expired';
        }
        if (!is_string($signature) || !preg_match('/^[a-f0-9]{64}$/D', $signature) || !$this->callbackUrls) {
            return 'invalid_signature';
        }
        $reason = $this->connection->api()->verifyWebhook($credentials->secret(), $timestamp, $signature, $this->callbackUrls, $body, $now);
        return $reason === '' ? '' : ($reason === 'timestamp_expired' ? 'timestamp_expired' : 'invalid_signature');
    }

    /**
     * @param Connection       $connection  Connection.
     * @param Credentials|null $credentials Credentials.
     * @param string           $checkoutId  Checkout id.
     * @return Checkout
     * @throws ApiException
     */
    public static function fetchCheckout(Connection $connection, $credentials, $checkoutId)
    {
        if (!$credentials instanceof Credentials) {
            throw new ApiException(0, 'not_connected', 'This store is not connected to Caravel Pay.');
        }
        $checkout = Checkout::fromArray($connection->api()->getCheckout($credentials, $checkoutId));
        if ($checkout === null || $checkout->id() !== $checkoutId) {
            throw new ApiException(0, 'invalid_response', 'Caravel Pay returned an invalid checkout.');
        }
        return $checkout;
    }

    /**
     * Lowercase dash-separated header names: "HTTP_CARAVEL_MERCHANT" and "caravel_merchant" -> "caravel-merchant".
     *
     * @param array $headers Headers.
     * @return array<string,string>
     */
    public static function normalizeHeaders(array $headers)
    {
        $out = array();
        foreach ($headers as $name => $value) {
            if (!is_string($name)) {
                continue;
            }
            $name = strtolower(str_replace('_', '-', $name));
            if (strpos($name, 'http-') === 0) {
                $name = substr($name, 5);
            }
            if (is_array($value)) {
                $value = reset($value);
            }
            if (is_string($value) || is_int($value)) {
                $out[$name] = trim((string) $value);
            }
        }
        return $out;
    }
}
