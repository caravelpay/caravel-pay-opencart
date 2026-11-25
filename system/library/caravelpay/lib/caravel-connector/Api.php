<?php
namespace CaravelPay\Connector;

/**
 * What the connector needs from the Caravel Pay API. SdkApi implements it on top of the
 * Caravel Pay PHP SDK; tests use a fake. Every failure throws ApiException.
 */
interface Api
{
    /**
     * Unsigned POST /v1/connect.
     *
     * @param array $payload {site_url, payout_address, platform, email?, plugin_version?}
     * @return array {merchant_id, secret, status, fee_rate}
     * @throws ApiException
     */
    public function connect(array $payload);

    /**
     * Signed POST /v1/checkouts.
     *
     * @param Credentials $credentials Merchant that creates the checkout.
     * @param array       $body        Request body.
     * @return array{status:int,body:array} HTTP status (201 created, 200 existing) and the decoded object.
     * @throws ApiException
     */
    public function createCheckout(Credentials $credentials, array $body);

    /**
     * Signed GET /v1/checkouts/{id}.
     *
     * @param Credentials $credentials Merchant that created the checkout.
     * @param string      $checkoutId  Checkout id.
     * @return array Decoded object.
     * @throws ApiException
     */
    public function getCheckout(Credentials $credentials, $checkoutId);

    /**
     * Signed GET /v1/checkouts (newest first).
     *
     * @param Credentials $credentials Merchant.
     * @param int         $limit       1 to 100.
     * @return array[] Decoded checkout objects of the first page.
     * @throws ApiException
     */
    public function listCheckouts(Credentials $credentials, $limit = 20);

    /**
     * Signed GET /v1/merchant.
     *
     * @param Credentials $credentials Merchant.
     * @return array Decoded object.
     * @throws ApiException
     */
    public function getMerchant(Credentials $credentials);

    /**
     * GET /v1/fx-quote: today's indicative rate for a currency other than US dollars.
     *
     * @param Credentials $credentials Merchant credentials.
     * @param string      $currency    Currency code.
     * @return array {currency, fx_rate, quoted_at}
     * @throws ApiException
     */
    public function fxQuote(Credentials $credentials, $currency);

    /**
     * Verify a webhook signature: lowercase hex HMAC-SHA256 over "ts\nPOST\npath\nbody" keyed with
     * the secret, within the 300-second window, where path is the path + query of one of our
     * callback URLs (every candidate is compared, in constant time).
     *
     * @param string   $secret       Secret of the merchant named in Caravel-Merchant.
     * @param string   $timestamp    Caravel-Timestamp.
     * @param string   $signature    Caravel-Signature.
     * @param string[] $callbackUrls Every callback URL this platform registers.
     * @param string   $body         Raw body.
     * @param int      $now          Unix seconds.
     * @return string '' when valid, otherwise timestamp_expired or invalid_signature.
     */
    public function verifyWebhook($secret, $timestamp, $signature, array $callbackUrls, $body, $now);
}
