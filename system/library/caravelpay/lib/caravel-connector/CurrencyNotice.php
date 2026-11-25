<?php
namespace CaravelPay\Connector;

/**
 * The line buyers read before they are sent to the checkout, in a store that does not price in US dollars:
 * "You'll be charged in US dollars: about $48.60. You see the exact amount before you pay."
 *
 * The estimate uses today's rate from Caravel Pay, kept in the module's own key-value store for ten minutes (a missing
 * rate for two), so a checkout page never waits for it on every load. Without a rate the line has no figure. The
 * exact amount is fixed when the checkout is created, and the hosted page shows both prices.
 */
final class CurrencyNotice
{
    const RATE_SECONDS = 600;
    const RETRY_SECONDS = 120;

    /**
     * @param Connection $connection   The store's connection (credentials, API, clock).
     * @param KeyValue   $store        Where the rate is cached.
     * @param mixed      $currency     The order or store currency.
     * @param mixed      $total        The total in that currency, when known.
     * @param mixed      $bufferPercent The store's "Exchange-rate buffer" setting, when it has one.
     * @return string '' for US dollars and for currencies Caravel Pay does not convert.
     */
    public static function text(Connection $connection, KeyValue $store, $currency, $total = null, $bufferPercent = 0)
    {
        $currency = \CaravelPay\Currency::code($currency);
        if ($currency === 'USD' || !\CaravelPay\Currency::isSupported($currency)) {
            return '';
        }
        $estimate = null;
        if ($total !== null && is_numeric($total) && (float) $total > 0) {
            $estimate = \CaravelPay\Currency::estimateUsd((string) $total, $currency, self::rate($connection, $store, $currency), \CaravelPay\Currency::bufferBps($bufferPercent));
        }
        return \CaravelPay\Currency::buyerNotice($currency, $estimate);
    }

    /**
     * The same line for a checkout that already exists (billing platforms create it before they show the pay button),
     * with the checkout's own US dollar amount. '' for a US dollar checkout.
     *
     * @param Checkout|null $checkout The checkout the buyer is about to open.
     * @return string
     */
    public static function forCheckout($checkout)
    {
        if (!$checkout instanceof Checkout || $checkout->currency() === 'USD' || $checkout->usdAmount() === '') {
            return '';
        }
        return \CaravelPay\Currency::buyerNotice($checkout->currency(), $checkout->usdAmount());
    }

    /**
     * US dollars for one unit of the currency, or '' when none is at hand.
     *
     * @param Connection $connection Connection.
     * @param KeyValue   $store      Cache.
     * @param string     $currency   Currency code.
     * @return string
     */
    public static function rate(Connection $connection, KeyValue $store, $currency)
    {
        $now = $connection->now();
        $key = 'fx_rate_' . strtolower($currency);
        $cached = json_decode((string) $store->get($key, ''), true);
        if (is_array($cached) && isset($cached['rate'], $cached['until']) && is_string($cached['rate']) && (int) $cached['until'] > $now) {
            return $cached['rate'];
        }
        $rate = '';
        $credentials = $connection->credentials();
        if ($credentials !== null) {
            try {
                $quote = $connection->api()->fxQuote($credentials, $currency);
                if (is_array($quote) && isset($quote['fx_rate']) && is_string($quote['fx_rate']) && preg_match('/^[0-9]{1,9}\\.[0-9]{1,18}$/D', $quote['fx_rate'])) {
                    $rate = $quote['fx_rate'];
                }
            } catch (\Throwable $error) {
                $rate = ''; // No rate right now: the line is shown without a figure.
            }
        }
        $store->set($key, (string) json_encode(array('rate' => $rate, 'until' => $now + ($rate === '' ? self::RETRY_SECONDS : self::RATE_SECONDS))));
        return $rate;
    }
}
