<?php
namespace CaravelPay\Connector;

/**
 * Exact USD amounts as two-decimal strings, without floating point.
 */
final class Money
{
    /**
     * Exact cents as a digit string ("49.9" -> "4990"), or null when malformed.
     *
     * @param mixed $amount Decimal amount (string, int or a float with at most 2 decimals).
     * @return string|null
     */
    public static function cents($amount)
    {
        if (is_int($amount)) {
            $amount = (string) $amount;
        } elseif (is_float($amount)) {
            if (!is_finite($amount) || $amount < 0) {
                return null;
            }
            // Platforms keep totals as floats or DECIMAL strings; round to the cent the platform shows.
            $amount = number_format(round($amount, 2), 2, '.', '');
        }
        if (!is_string($amount)) {
            return null;
        }
        $amount = trim($amount);
        if (!preg_match('/^(0|[1-9][0-9]{0,12})(?:\.([0-9]{1,6}))?$/D', $amount, $m)) {
            return null;
        }
        $fraction = isset($m[2]) ? $m[2] : '';
        if (strlen($fraction) > 2) {
            // Only trailing zeros may follow the cents ("10.5000" from a DECIMAL(15,4) column).
            if (trim(substr($fraction, 2), '0') !== '') {
                return null;
            }
            $fraction = substr($fraction, 0, 2);
        }
        $cents = ltrim($m[1] . str_pad($fraction, 2, '0'), '0');
        return $cents === '' ? '0' : $cents;
    }

    /**
     * A platform total as the API's two-decimal string ("49.9" -> "49.90"), or null.
     *
     * @param mixed $amount Amount.
     * @return string|null
     */
    public static function usd($amount)
    {
        $cents = self::cents($amount);
        if ($cents === null) {
            return null;
        }
        $cents = str_pad($cents, 3, '0', STR_PAD_LEFT);
        return substr($cents, 0, -2) . '.' . substr($cents, -2);
    }

    /**
     * A platform total as the API's amount in the currency's own decimals: "49.9" -> "49.90" for most currencies,
     * "5000" or "5000.00" -> "5000" for one without decimals (JPY, KRW). Null when it can't be written exactly.
     *
     * @param mixed  $amount   Amount.
     * @param string $currency Currency code.
     * @return string|null
     */
    public static function amount($amount, $currency)
    {
        if (\CaravelPay\Currency::decimals((string) $currency) !== 0) {
            return self::usd($amount);
        }
        $cents = self::cents($amount);
        if ($cents === null || substr(str_pad($cents, 3, '0', STR_PAD_LEFT), -2) !== '00') {
            return null;
        }
        $whole = substr(str_pad($cents, 3, '0', STR_PAD_LEFT), 0, -2);
        return ltrim($whole, '0') === '' ? '0' : ltrim($whole, '0');
    }

    /**
     * Cents digit string back to "12.34".
     *
     * @param string $cents Digits.
     * @return string|null
     */
    public static function fromCents($cents)
    {
        if (!is_string($cents) || !preg_match('/^[0-9]{1,15}$/D', $cents)) {
            return null;
        }
        $cents = str_pad(ltrim($cents, '0'), 3, '0', STR_PAD_LEFT);
        return substr($cents, 0, -2) . '.' . substr($cents, -2);
    }

    /**
     * Sum of two-decimal amounts, exactly.
     *
     * @param string[] $amounts Amounts.
     * @return string|null
     */
    public static function sum(array $amounts)
    {
        $total = '0';
        foreach ($amounts as $amount) {
            $cents = self::cents($amount);
            if ($cents === null) {
                return null;
            }
            $total = self::addDigits($total, $cents);
        }
        return self::fromCents($total);
    }

    /**
     * @param string $a Digits.
     * @param string $b Digits.
     * @return string
     */
    private static function addDigits($a, $b)
    {
        $a = strrev($a);
        $b = strrev($b);
        $out = '';
        $carry = 0;
        $len = max(strlen($a), strlen($b));
        for ($i = 0; $i < $len; $i++) {
            $digit = (isset($a[$i]) ? (int) $a[$i] : 0) + (isset($b[$i]) ? (int) $b[$i] : 0) + $carry;
            $out .= (string) ($digit % 10);
            $carry = intdiv($digit, 10);
        }
        if ($carry) {
            $out .= (string) $carry;
        }
        return strrev($out);
    }

    /**
     * True for "0.00" or anything that is zero cents.
     *
     * @param string $amount Amount.
     * @return bool
     */
    public static function isZero($amount)
    {
        return self::cents($amount) === '0';
    }

    /**
     * The API accepts 0.01 to 1000000.00.
     *
     * @param string $usd Two-decimal amount.
     * @return bool
     */
    public static function inApiRange($usd)
    {
        $cents = self::cents($usd);
        return $cents !== null && $cents !== '0' && (strlen($cents) < 9 || (strlen($cents) === 9 && strcmp($cents, '100000000') <= 0));
    }
}
