<?php
namespace CaravelPay\Connector;

/**
 * Creates (or resumes) the hosted checkout for an order with its own checkout record.
 *
 * The request body is saved on the order before the HTTP call, so a timeout or an ambiguous
 * failure is retried with the identical body: the API returns the same checkout for the same
 * order_id instead of creating a second one. A new order_id (suffix -2, -3…) is used only after
 * the previous checkout is confirmed expired.
 */
final class CheckoutStarter
{
    /** Grace after expires_at before a new attempt is considered, seconds. */
    const EXPIRY_GRACE = 30;

    /** @var Connection */
    private $connection;
    /** @var CheckoutOrders */
    private $orders;
    /** @var Messages */
    private $messages;
    /** @var string Who pays the fees for new checkouts. */
    private $feeMode = \CaravelPay\FeeMode::SELLER;
    /** @var int The store's margin on a converted amount, in basis points (0 unless set). */
    private $fxBufferBps = 0;

    /**
     * @param Connection     $connection Connection.
     * @param CheckoutOrders $orders     Platform orders.
     * @param Messages       $messages   Texts.
     */
    public function __construct(Connection $connection, CheckoutOrders $orders, Messages $messages)
    {
        $this->connection = $connection;
        $this->orders = $orders;
        $this->messages = $messages;
    }

    /**
     * The store's "Who pays the fees" setting: "seller" (the default), "split" or "buyer". Anything else is the
     * default. It applies to new requests only: a saved request keeps the mode it was built with.
     *
     * @param mixed $mode Saved setting.
     * @return $this
     */
    public function withFeeMode($mode)
    {
        $this->feeMode = \CaravelPay\FeeMode::normalize($mode);
        return $this;
    }

    /**
     * The store's "Exchange-rate buffer" setting, a percentage ("0" to "5"): the margin added to the converted amount
     * of an order in another currency. Anything else is 0. It applies to new requests only.
     *
     * @param mixed $percent Saved setting.
     * @return $this
     */
    public function withFxBuffer($percent)
    {
        $this->fxBufferBps = \CaravelPay\Currency::bufferBps($percent);
        return $this;
    }

    /**
     * @param string|int $ref         Order reference.
     * @param string     $returnUrl   Where the buyer returns after paying.
     * @param string     $cancelUrl   Where the buyer goes when they back out.
     * @param string     $callbackUrl Webhook URL.
     * @return StartResult
     */
    public function start($ref, $returnUrl, $cancelUrl, $callbackUrl)
    {
        $lease = $this->orders->lock($ref);
        if ($lease === null || $lease === false) {
            return StartResult::error('start.busy');
        }
        try {
            return $this->startLocked($ref, (string) $returnUrl, (string) $cancelUrl, (string) $callbackUrl, $lease);
        } finally {
            $this->orders->unlock($lease);
        }
    }

    /**
     * @param string|int $ref         Order.
     * @param string     $returnUrl   Return URL.
     * @param string     $cancelUrl   Cancel URL.
     * @param string     $callbackUrl Callback URL.
     * @param mixed      $lease       Lease.
     * @return StartResult
     */
    private function startLocked($ref, $returnUrl, $cancelUrl, $callbackUrl, $lease)
    {
        $log = $this->connection->logger();
        $order = $this->orders->startSnapshot($ref);
        if ($order === null || !$order->payable) {
            return StartResult::error('start.cannot_pay');
        }
        // The order's own currency and amount go out as they are: US dollars as before, another supported currency for
        // Caravel Pay to convert when it creates the checkout.
        $currency = \CaravelPay\Currency::code($order->currency);
        if (!\CaravelPay\Currency::isSupported($currency)) {
            return StartResult::error('start.usd_only');
        }
        $amount = $order->amount !== null ? Money::amount($order->amount, $currency) : null;
        if ($amount === null || Money::isZero($amount) || ($currency === 'USD' && !Money::inApiRange($amount))) {
            return StartResult::error('start.amount');
        }
        $credentials = $this->connection->credentials();
        if ($credentials === null) {
            return StartResult::error('start.unavailable');
        }
        $merchant = $credentials->merchantId();
        $saved = is_array($order->request) && $order->request ? $order->request : null;
        $current = (string) $order->currentCheckoutId;
        $attempt = max(1, (int) $order->attempt);
        if ($saved !== null && ((isset($saved['amount']) ? $saved['amount'] : '') !== $amount || (isset($saved['currency']) ? $saved['currency'] : 'USD') !== $currency)) {
            if ($current !== '') {
                return StartResult::error('start.changed');
            }
            // Nothing was issued for the old total, but its order_id may be held: use a new one.
            $saved = null;
            $attempt++;
        }
        if ($saved !== null && $current !== '') {
            $expires = $order->expiresAt !== '' ? strtotime($order->expiresAt) : false;
            $url = (string) $order->url;
            if ($expires && $expires + self::EXPIRY_GRACE > $this->connection->now() && $url !== '') {
                // Still open, even if the store has since reconnected: orders already started keep their checkout.
                return StartResult::redirect($url);
            }
            // Past its expiry: confirm before starting a new checkout, so a payment in flight is never doubled.
            $owner = $this->connection->credentialsFor((string) $order->currentMerchant);
            if ($owner === null && $expires && $expires + 86400 < $this->connection->now()) {
                $status = 'expired'; // Its credentials were retired days after it expired.
            } else {
                try {
                    $status = WebhookProcessor::fetchCheckout($this->connection, $owner, $current)->status();
                } catch (ApiException $error) {
                    return StartResult::error('start.temporarily');
                }
            }
            if ($status === 'awaiting_payment' && $url !== '') {
                return StartResult::redirect($url);
            }
            if ($status !== 'expired') {
                return StartResult::error('start.in_progress');
            }
            $saved = null;
            $attempt++;
        }
        if ($saved !== null && (string) $order->requestMerchant !== $merchant) {
            // An unanswered request made before the store reconnected: start over under the current merchant.
            $saved = null;
            $attempt++;
        }
        if ($saved === null) {
            $body = array(
                'order_id' => $this->orders->reference($ref, $attempt),
                'amount' => $amount,
                'currency' => $currency,
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
                'callback_url' => $callbackUrl,
            );
            if ($order->email !== '' && filter_var($order->email, FILTER_VALIDATE_EMAIL) !== false && strlen($order->email) <= 254) {
                $body['customer_email'] = $order->email;
            }
            // Who pays the fees: nothing is added for the default, so a default store sends the same request as before.
            $body = \CaravelPay\FeeMode::apply($body, $this->feeMode);
            // Another currency may carry the store's margin on the converted amount (default 0: nothing is added).
            if ($currency !== 'USD' && $this->fxBufferBps > 0) {
                $body['fx_buffer_bps'] = $this->fxBufferBps;
            }
            $this->orders->saveRequest($ref, $body, $attempt, $merchant);
        } else {
            $body = $saved;
        }
        try {
            $response = $this->connection->api()->createCheckout($credentials, $body);
        } catch (ApiException $error) {
            return $this->refused($ref, $body, $error);
        }
        $checkout = Checkout::fromArray(isset($response['body']) ? $response['body'] : null);
        if ($checkout === null || !$checkout->hasValidCheckoutUrl() || $checkout->amount() !== $amount || $checkout->currency() !== $currency || $checkout->expiresAt() === '') {
            $log->error('Order ' . $ref . ': Caravel Pay returned an invalid checkout.');
            return StartResult::error('start.invalid_response');
        }
        if ($checkout->feeMode() !== \CaravelPay\FeeMode::normalize(isset($body['fee_mode']) ? $body['fee_mode'] : null)) {
            // Never send the buyer to a checkout that charges fees differently from what the store asked for.
            $log->error('Order ' . $ref . ': the checkout was not created with the requested "Who pays the fees" setting.');
            return StartResult::error('start.invalid_response');
        }
        $after = $this->orders->startSnapshot($ref);
        if ($after === null || $after->request !== $body || !$this->orders->owns($lease)) {
            return StartResult::error('start.changed_during');
        }
        $isNew = (string) $after->currentCheckoutId !== $checkout->id();
        $this->orders->linkCheckout($ref, $checkout, $merchant, $isNew, $isNew ? trim(trim($this->messages->render('note.created', array($checkout->id())) . ' ' . $this->messages->feeNote($checkout)) . ' ' . $this->messages->currencyNote($checkout)) : '');
        $log->info('Order ' . $ref . ': checkout ' . $checkout->id() . ($isNew ? ' created.' : ' resumed.'));
        return StartResult::redirect($checkout->checkoutUrl(), $checkout);
    }

    /**
     * Map an API refusal to a buyer message. Drops the saved request only when the API proves nothing was created.
     *
     * @param string|int   $ref   Order.
     * @param array        $body  Body.
     * @param ApiException $error Error.
     * @return StartResult
     */
    private function refused($ref, array $body, ApiException $error)
    {
        $this->connection->logger()->warning('Order ' . $ref . ': creating the checkout failed: ' . $error->errorCode() . ' (HTTP ' . $error->httpStatus() . ').');
        $this->connection->noteRefusal($error->errorCode());
        if ($error->createdNothing()) {
            $this->orders->dropRequest($ref, $body);
        }
        return StartResult::error(self::refusalKey($error));
    }

    /**
     * @param ApiException $error Error.
     * @return string Message key.
     */
    public static function refusalKey(ApiException $error)
    {
        $code = $error->errorCode();
        if (in_array($code, array('merchant_paused', 'merchant_pending_review', 'unknown_merchant', 'invalid_signature', 'timestamp_expired', 'not_connected'), true)) {
            return 'start.unavailable';
        }
        if ($code === 'order_id_conflict') {
            return 'start.changed';
        }
        if ($code === 'limit_exceeded') {
            return 'start.limit';
        }
        if (in_array($code, array('invalid_amount', 'unsupported_currency'), true)) {
            return 'start.amount';
        }
        if ($error->httpStatus() === 400) {
            return 'start.misconfigured';
        }
        return 'start.temporarily';
    }
}
