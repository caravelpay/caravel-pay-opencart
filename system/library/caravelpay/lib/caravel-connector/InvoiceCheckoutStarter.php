<?php
namespace CaravelPay\Connector;

/**
 * Starts (or resumes) a checkout for an invoice on platforms without a checkout table. The
 * order_id is deterministic (InvoiceReference), so a repeated POST returns the same checkout;
 * an expired attempt moves on to the next attempt number. A payment in progress, an underpaid
 * checkout that can still be topped up, or a paid one is never doubled.
 *
 * The request body must be identical on every call for the same invoice and amount, so no
 * customer_email is sent (it can change between visits) and the platform passes stable URLs.
 * A 409 order_id_conflict therefore means something unexpected changed: it stops, rather than
 * skipping to a new attempt while the old checkout might still be paid.
 *
 * One expected change is the "Who pays the fees" setting. A checkout keeps the mode it was created
 * with, so after a conflict the same attempt is looked up under the other modes: an open checkout is
 * resumed as it is, and only an expired one gives way to a new attempt under the current setting.
 */
final class InvoiceCheckoutStarter
{
    /** @var Connection */
    private $connection;
    /** @var string Who pays the fees for new checkouts. */
    private $feeMode = \CaravelPay\FeeMode::SELLER;

    /**
     * @param Connection $connection Connection.
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * The "Who pays the fees" setting: "seller" (the default), "split" or "buyer". Anything else is the default.
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
     * @param string $base        InvoiceReference base.
     * @param mixed  $amount      Amount due.
     * @param string $currency    Invoice currency.
     * @param string $returnUrl   Return URL.
     * @param string $cancelUrl   Cancel URL.
     * @param string $callbackUrl Webhook URL.
     * @return StartResult
     */
    public function start($base, $amount, $currency, $returnUrl, $cancelUrl, $callbackUrl)
    {
        $log = $this->connection->logger();
        // The invoice's own currency and amount go out as they are: US dollars as before, another supported currency for
        // Caravel Pay to convert when it creates the checkout. $usd names the amount in the order_id (two decimals, in
        // the invoice currency); $sent is the same amount as the API takes it ("5000" for a currency without decimals).
        $currency = \CaravelPay\Currency::code($currency);
        if (!\CaravelPay\Currency::isSupported($currency)) {
            return StartResult::error('start.usd_only');
        }
        $usd = Money::usd($amount);
        $sent = Money::amount($amount, $currency);
        if ($usd === null || $sent === null || Money::isZero($usd) || ($currency === 'USD' && !Money::inApiRange($usd))) {
            return StartResult::error('start.amount');
        }
        $credentials = $this->connection->credentials();
        if ($credentials === null) {
            return StartResult::error('start.unavailable');
        }
        if (InvoiceReference::build($base, $usd, InvoiceReference::MAX_ATTEMPTS) === null) {
            return StartResult::error('start.too_many_invoices');
        }
        $now = $this->connection->now();
        // The current setting first; the other modes only to find a checkout an earlier setting created.
        $modes = array_merge(array($this->feeMode), array_values(array_diff(\CaravelPay\FeeMode::ALL, array($this->feeMode))));
        for ($attempt = 1; $attempt <= InvoiceReference::MAX_ATTEMPTS; $attempt++) {
            $response = null;
            $mode = $this->feeMode;
            foreach ($modes as $index => $mode) {
                $body = \CaravelPay\FeeMode::apply(array(
                    'order_id' => InvoiceReference::build($base, $usd, $attempt),
                    'amount' => $sent,
                    'currency' => $currency,
                    'return_url' => (string) $returnUrl,
                    'cancel_url' => (string) $cancelUrl,
                    'callback_url' => (string) $callbackUrl,
                ), $mode);
                try {
                    $response = $this->connection->api()->createCheckout($credentials, $body);
                    break;
                } catch (ApiException $error) {
                    if ($error->errorCode() === 'order_id_conflict' && $index + 1 < count($modes)) {
                        // This attempt may exist under another fee setting: look for it before giving up.
                        continue;
                    }
                    $log->warning('Invoice ' . $base . ': creating the checkout failed: ' . $error->errorCode() . ' (HTTP ' . $error->httpStatus() . ').');
                    $this->connection->noteRefusal($error->errorCode());
                    return StartResult::error(CheckoutStarter::refusalKey($error));
                }
            }
            $checkout = Checkout::fromArray(isset($response['body']) ? $response['body'] : null);
            if ($checkout === null || !$checkout->hasValidCheckoutUrl() || $checkout->amount() !== $sent || $checkout->currency() !== $currency) {
                $log->error('Invoice ' . $base . ': Caravel Pay returned an invalid checkout.');
                return StartResult::error('start.invalid_response');
            }
            if ($checkout->feeMode() !== $mode) {
                // Never send the buyer to a checkout that charges fees differently from what was asked for.
                $log->error('Invoice ' . $base . ': the checkout was not created with the requested "Who pays the fees" setting.');
                return StartResult::error('start.invalid_response');
            }
            $expires = $checkout->expiresAtTime();
            $open = $expires !== null && $expires > $now + 30;
            switch ($checkout->status()) {
                case 'awaiting_payment':
                    if ($open) {
                        $log->info('Invoice ' . $base . ': checkout ' . $checkout->id() . (isset($response['status']) && (int) $response['status'] === 201 ? ' created.' : ' resumed.'));
                        return StartResult::redirect($checkout->checkoutUrl(), $checkout);
                    }
                    // About to expire, or past expiry but not marked expired yet: never start a
                    // parallel checkout while this one could still be paid. The buyer retries shortly.
                    return StartResult::error('start.in_progress');
                case 'expired':
                    continue 2;
                case 'underpaid':
                    // The buyer can still top up the same checkout before it expires.
                    return $open ? StartResult::redirect($checkout->checkoutUrl(), $checkout) : StartResult::error('start.in_progress');
                case 'paid':
                    return StartResult::error('start.already_paid');
                default:
                    return StartResult::error('start.in_progress');
            }
        }
        $log->warning('Invoice ' . $base . ': every attempt number is used; contact Caravel Pay support.');
        return StartResult::error('start.temporarily');
    }
}
