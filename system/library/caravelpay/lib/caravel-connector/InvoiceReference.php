<?php
namespace CaravelPay\Connector;

/**
 * order_id for billing platforms that keep no checkout table (Blesta, HostBill, ClientExec):
 * "<base>-<cents>-<attempt>". The base names the invoice(s); the cents pin the amount, so a
 * changed balance always gets a fresh order_id. The webhook handler reads the order_id back from
 * the checkout it re-fetched with signed GET /v1/checkouts/{id}, so it can be trusted.
 */
final class InvoiceReference
{
    const MAX_ATTEMPTS = 25;

    /**
     * @param string $base    Platform part: letters, digits, dots and "x" only.
     * @param string $amount  Two-decimal amount.
     * @param int    $attempt Attempt, 1 or more.
     * @return string|null Null when the result would not fit the API's 128 characters.
     */
    public static function build($base, $amount, $attempt)
    {
        $cents = Money::cents($amount);
        if (!preg_match('/^[A-Za-z][A-Za-z0-9.]{0,100}$/D', (string) $base) || $cents === null || (int) $attempt < 1) {
            return null;
        }
        $id = $base . '-' . $cents . '-' . (int) $attempt;
        return strlen($id) <= 128 ? $id : null;
    }

    /**
     * The newest checkout among $checkouts (newest first, as GET /v1/checkouts lists them) whose
     * order_id is an attempt of this base and amount.
     *
     * @param array[] $checkouts Decoded checkout objects.
     * @param string  $base      Base.
     * @param string  $amount    Two-decimal amount.
     * @return Checkout|null
     */
    public static function latest(array $checkouts, $base, $amount)
    {
        $cents = Money::cents($amount);
        foreach ($checkouts as $value) {
            $checkout = Checkout::fromArray($value);
            if ($checkout === null) {
                continue;
            }
            $parsed = self::parse($checkout->orderId(), '');
            if ($parsed !== null && $parsed['base'] === $base && Money::cents($parsed['amount']) === $cents) {
                return $checkout;
            }
        }
        return null;
    }

    /**
     * @param string $orderId order_id from a verified checkout.
     * @param string $prefix  Expected first letters of the base (the platform's marker).
     * @return array|null {base, amount, attempt}
     */
    public static function parse($orderId, $prefix)
    {
        if (!is_string($orderId) || !preg_match('/^([A-Za-z][A-Za-z0-9.]{0,100})-([0-9]{1,15})-([0-9]{1,3})$/D', $orderId, $m)) {
            return null;
        }
        if ($prefix !== '' && strpos($m[1], $prefix) !== 0) {
            return null;
        }
        return array('base' => $m[1], 'amount' => Money::fromCents($m[2]), 'attempt' => (int) $m[3]);
    }
}
