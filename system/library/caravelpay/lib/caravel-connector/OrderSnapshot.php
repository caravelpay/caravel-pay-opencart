<?php
namespace CaravelPay\Connector;

/**
 * What the decision rules need to know about one order (or invoice), read fresh under its lock.
 * The platform adapter builds it from its own order model.
 */
final class OrderSnapshot
{
    /** Waiting for this payment (WooCommerce pending/failed). */
    const OPEN = 'open';
    /** On hold (or the platform's equivalent). */
    const HELD = 'held';
    /** Cancelled (may still be completed by a late payment where the platform allows it). */
    const CANCELLED = 'cancelled';
    /** Already paid. */
    const PAID = 'paid';
    /** Any other state: shipped, refunded, closed... never changed automatically. */
    const OTHER = 'other';

    /** @var bool */
    public $ours = false;
    /** @var string|null The amount this order requested from Caravel Pay, in $expectedCurrency. */
    public $expectedAmount;
    /** @var string The currency of that request ("currency" of the saved request; US dollars before 1.2.0). */
    public $expectedCurrency = 'USD';
    /** @var string The order's newest checkout id. */
    public $currentCheckoutId = '';
    /** @var string Last checkout status the connector recorded ("paid", "underpaid@chk_…"). */
    public $marker = '';
    /** @var string One of the constants above. */
    public $state = self::OPEN;
    /** @var string The platform's own status name, for notes. */
    public $stateLabel = '';
    /** @var string|null The order total now, two decimals. */
    public $total;
    /** @var string ISO currency of the order. */
    public $currency = 'USD';
    /** @var string Last recorded paid_amount for an underpaid checkout. */
    public $paidAmount = '';
    /** @var string Transaction id recorded when the order was paid. */
    public $transactionId = '';

    /**
     * @param array $fields Property => value.
     * @return OrderSnapshot
     */
    public static function of(array $fields)
    {
        $snapshot = new self();
        foreach ($fields as $name => $value) {
            if (!property_exists($snapshot, $name)) {
                throw new \InvalidArgumentException('Unknown snapshot field ' . $name);
            }
            $snapshot->$name = $value;
        }
        return $snapshot;
    }
}
