<?php
namespace CaravelPay\Connector;

/**
 * OrderAdapter for billing platforms without a checkout table (Blesta, HostBill, ClientExec). The
 * invoice is found from the order_id of the checkout re-fetched with the signing merchant's
 * credentials (InvoiceReference), so no local mapping is needed: a checkout another merchant made
 * answers 404 and is acknowledged without changes.
 *
 * Billing systems apply payments themselves (partial payments, overpayments to credit), so every
 * verified checkout is treated as the invoice's current one and the invoice as open:
 *
 * | Checkout status   | Billing platform                                            |
 * |-------------------|-------------------------------------------------------------|
 * | paid              | paid(): record the payment (idempotent by checkout id)      |
 * | underpaid, review | held(): pending / logged, never applied as paid             |
 * | expired           | expired(): logged (the invoice simply stays unpaid)         |
 * | confirming        | noted(): logged                                             |
 */
abstract class InvoiceAdapter implements OrderAdapter
{
    /** @var string */
    protected $prefix;

    /**
     * @param string $prefix InvoiceReference base prefix of this platform ("hb", "ce", "bl").
     */
    public function __construct($prefix)
    {
        $this->prefix = (string) $prefix;
    }

    /**
     * @param array $reference InvoiceReference::parse() result.
     * @return bool True when the invoice(s) it names exist on this platform.
     */
    abstract protected function invoiceExists(array $reference);

    /**
     * Record a confirmed payment of $checkout->amount() (tx hash in $decision->txHash).
     *
     * @param array    $reference Parsed reference.
     * @param Checkout $checkout  Verified paid checkout.
     * @param Decision $decision  Decision (note text).
     * @return bool False = the platform failed; the webhook is retried.
     */
    abstract protected function paid(array $reference, Checkout $checkout, Decision $decision);

    /** @return bool */
    abstract protected function held(array $reference, Checkout $checkout, Decision $decision);

    /** @return bool */
    abstract protected function expired(array $reference, Checkout $checkout, Decision $decision);

    /** @return bool */
    abstract protected function noted(array $reference, Checkout $checkout, Decision $decision);

    public function locate($checkoutId, $fetch)
    {
        $checkout = call_user_func($fetch);
        $reference = InvoiceReference::parse($checkout->orderId(), $this->prefix);
        if ($reference === null || !$this->invoiceExists($reference)) {
            return null;
        }
        return $checkout->orderId();
    }

    public function checkoutMerchant($ref, $checkoutId, $signer)
    {
        // locate() re-fetched the checkout with the signer's credentials: it is the signer's.
        return (string) $signer;
    }

    public function lock($ref)
    {
        return array('ref' => $ref);
    }

    public function unlock($lease)
    {
    }

    public function snapshot($ref, Checkout $checkout)
    {
        $reference = InvoiceReference::parse((string) $ref, $this->prefix);
        if ($reference === null || $checkout->orderId() !== $ref) {
            return null;
        }
        return OrderSnapshot::of(array(
            'ours' => true,
            // No stored request here: the order_id carries the invoice and its amount, and the checkout (re-fetched with
            // this store's credentials) says which currency that amount was sent in.
            'expectedAmount' => $reference['amount'],
            'expectedCurrency' => $checkout->currency(),
            'currentCheckoutId' => $checkout->id(),
            'state' => OrderSnapshot::OPEN,
            'total' => $reference['amount'],
            'currency' => $checkout->currency(),
        ));
    }

    public function apply($ref, Decision $decision, Checkout $checkout)
    {
        $reference = InvoiceReference::parse((string) $ref, $this->prefix);
        if ($reference === null) {
            return false;
        }
        switch ($decision->action) {
            case Decision::COMPLETE:
                return (bool) $this->paid($reference, $checkout, $decision);
            case Decision::HOLD:
                return (bool) $this->held($reference, $checkout, $decision);
            case Decision::CANCEL:
                return (bool) $this->expired($reference, $checkout, $decision);
            case Decision::NOTE:
                return (bool) $this->noted($reference, $checkout, $decision);
            default:
                return true;
        }
    }

    public function recordEvent($ref, $eventId, $type)
    {
    }

    /**
     * A description for the billing platform's transaction list: amount, checkout and payout link.
     *
     * @param Messages $messages Texts.
     * @param Checkout $checkout Checkout.
     * @return string
     */
    public static function describe(Messages $messages, Checkout $checkout)
    {
        $payout = $checkout->payout() ? ' ' . $messages->payoutNote($checkout) : '';
        $amount = $checkout->currency() === 'USD' ? '$' . $checkout->amount() : $checkout->amount() . ' ' . $checkout->currency() . ' = $' . $checkout->usdAmount();
        return 'Caravel Pay ' . $amount . ' (checkout ' . $checkout->id() . ', ' . $checkout->status() . ').' . $payout;
    }
}
