<?php
namespace CaravelPay\Connector;

use CaravelPay\Client;
use CaravelPay\Exception\ApiException as SdkApiException;
use CaravelPay\Exception\NetworkException;
use CaravelPay\Exception\UnexpectedResponseException;
use CaravelPay\Signature;

/**
 * The connector's Api on top of the Caravel Pay PHP SDK (vendored as ../caravel-php/): request
 * signing, transport, response validation and webhook signature checks all come from the SDK.
 */
final class SdkApi implements Api
{
    /** @var array SDK client options. */
    private $options;

    /**
     * @param array $options SDK options: api_base, timeout, connect_timeout, user_agent (a product
     *                       token such as "CaravelPay-PrestaShop/1.0.0"), transport, ca_bundle, clock.
     */
    public function __construct(array $options = array())
    {
        $allowed = array('api_base', 'timeout', 'connect_timeout', 'user_agent', 'transport', 'ca_bundle', 'clock');
        $this->options = array_intersect_key($options, array_flip($allowed));
        if (isset($this->options['api_base']) && $this->options['api_base'] === '') {
            unset($this->options['api_base']);
        }
        // Webhooks must be answered within 10 seconds, and they re-fetch the checkout first.
        $this->options += array('timeout' => 8, 'connect_timeout' => 5);
    }

    public function connect(array $payload)
    {
        try {
            // Never retried: every connect creates a new merchant.
            return Client::connect($payload, array_merge($this->options, array('timeout' => 30)));
        } catch (\Throwable $error) {
            throw self::convert($error);
        }
    }

    public function createCheckout(Credentials $credentials, array $body)
    {
        try {
            $client = $this->client($credentials);
            $checkout = $client->createCheckout($body);
            // 201 = created, 200 = the existing checkout for this order_id (0 when the SDK can't tell).
            $status = method_exists($client, 'lastStatus') ? (int) $client->lastStatus() : 0;
            return array('status' => $status, 'body' => $checkout);
        } catch (\Throwable $error) {
            throw self::convert($error);
        }
    }

    public function getCheckout(Credentials $credentials, $checkoutId)
    {
        try {
            return $this->client($credentials)->getCheckout((string) $checkoutId);
        } catch (\Throwable $error) {
            throw self::convert($error);
        }
    }

    public function listCheckouts(Credentials $credentials, $limit = 20)
    {
        try {
            $page = $this->client($credentials)->listCheckouts(array('limit' => max(1, min(100, (int) $limit))));
            return isset($page['data']) && is_array($page['data']) ? $page['data'] : array();
        } catch (\Throwable $error) {
            throw self::convert($error);
        }
    }

    public function fxQuote(Credentials $credentials, $currency)
    {
        try {
            return $this->client($credentials)->fxQuote((string) $currency);
        } catch (\Throwable $error) {
            throw self::convert($error);
        }
    }

    public function getMerchant(Credentials $credentials)
    {
        try {
            return $this->client($credentials)->getMerchant();
        } catch (\Throwable $error) {
            throw self::convert($error);
        }
    }

    public function verifyWebhook($secret, $timestamp, $signature, array $callbackUrls, $body, $now)
    {
        if (!Signature::isFresh((string) $timestamp, (int) $now)) {
            return 'timestamp_expired';
        }
        $valid = false;
        foreach ($callbackUrls as $url) {
            try {
                $path = Signature::signedPath((string) $url);
            } catch (\InvalidArgumentException $error) {
                continue;
            }
            // Compare every candidate, so timing doesn't reveal which one matched.
            if (Signature::matches((string) $signature, (string) $secret, (string) $timestamp, 'POST', $path, (string) $body)) {
                $valid = true;
            }
        }
        return $valid ? '' : 'invalid_signature';
    }

    /**
     * @param Credentials $credentials Merchant.
     * @return Client
     */
    private function client(Credentials $credentials)
    {
        return new Client($credentials->merchantId(), $credentials->secret(), $this->options);
    }

    /**
     * SDK exceptions -> connector ApiException (codes the connector branches on).
     *
     * @param \Throwable $error SDK error.
     * @return ApiException
     */
    public static function convert(\Throwable $error)
    {
        if ($error instanceof ApiException) {
            return $error;
        }
        if ($error instanceof SdkApiException) {
            return new ApiException($error->getHttpStatus(), $error->getErrorCode(), $error->getMessage(), (string) $error->getParam(), $error->getRetryAfter());
        }
        if ($error instanceof NetworkException) {
            return new ApiException(0, 'network', 'Caravel Pay could not be reached. Please try again.');
        }
        if ($error instanceof UnexpectedResponseException) {
            return new ApiException(0, 'invalid_response', 'Caravel Pay returned an unexpected answer. Please try again.');
        }
        if ($error instanceof \InvalidArgumentException) {
            return new ApiException(0, 'invalid_request', $error->getMessage());
        }
        return new ApiException(0, 'server_error', 'Unexpected error (' . get_class($error) . ').');
    }
}
