<?php
namespace CaravelPay\Connector;

/**
 * A checkout object from the API, validated (the shape in docs/caravel/openapi.yaml).
 */
final class Checkout
{
    const ID = '/^chk_[A-Za-z0-9_-]{4,128}$/D';
    const STATUSES = array('awaiting_payment', 'confirming', 'paid', 'underpaid', 'expired', 'review');

    /** @var array */
    private $data;

    /**
     * @param array $data Validated fields.
     */
    private function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Validate a decoded API object. Null when malformed.
     *
     * @param mixed $value Decoded JSON.
     * @return Checkout|null
     */
    public static function fromArray($value)
    {
        if (!is_array($value)) {
            return null;
        }
        $id = isset($value['id']) ? $value['id'] : null;
        $status = isset($value['status']) ? $value['status'] : null;
        $amount = isset($value['amount']) ? $value['amount'] : null;
        $currency = isset($value['currency']) ? $value['currency'] : null;
        // The checkout's own currency: US dollars, or one Caravel Pay converts. The amount is in that currency's decimals.
        if (!is_string($id) || !preg_match(self::ID, $id) || !in_array($status, self::STATUSES, true)
            || !is_string($amount) || !is_string($currency) || !\CaravelPay\Currency::isSupported($currency) || Money::amount($amount, $currency) !== $amount) {
            return null;
        }
        $data = array('id' => $id, 'status' => $status, 'amount' => $amount, 'currency' => $currency);
        // Another currency: the order total in US dollars and the rate, fixed when the checkout was created. A converted
        // checkout always carries both; a US dollar checkout carries neither.
        $usd = isset($value['usd_amount']) && is_string($value['usd_amount']) && preg_match('/^[0-9]{1,13}\.[0-9]{2}$/D', $value['usd_amount']) ? $value['usd_amount'] : null;
        $rate = isset($value['fx_rate']) && is_string($value['fx_rate']) && preg_match('/^[0-9]{1,9}\.[0-9]{1,18}$/D', $value['fx_rate']) ? $value['fx_rate'] : null;
        $converted = $currency !== 'USD';
        if ($converted !== ($usd !== null) || $converted !== ($rate !== null) || (!$converted && (isset($value['usd_amount']) || isset($value['fx_rate'])))) {
            return null;
        }
        if ($converted) {
            $data['usd_amount'] = $usd;
            $data['fx_rate'] = $rate;
        }
        foreach (array('checkout_url', 'order_id') as $key) {
            if (isset($value[$key]) && is_string($value[$key]) && strlen($value[$key]) <= 2048) {
                $data[$key] = $value[$key];
            }
        }
        foreach (array('expires_at', 'created_at') as $key) {
            if (isset($value[$key]) && is_string($value[$key]) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,3})?Z$/D', $value[$key])) {
                $data[$key] = $value[$key];
            }
        }
        if (isset($value['paid_amount']) && is_string($value['paid_amount']) && preg_match('/^[0-9]{1,13}\.[0-9]{2}$/D', $value['paid_amount'])) {
            $data['paid_amount'] = $value['paid_amount'];
        }
        // Who pays the fees: present only when the buyer pays a fee (split or buyer). Absent means seller.
        if (isset($value['fee_mode']) && in_array($value['fee_mode'], array('split', 'buyer'), true)) {
            $data['fee_mode'] = $value['fee_mode'];
            if (isset($value['buyer_total_estimate']) && is_string($value['buyer_total_estimate']) && preg_match('/^[0-9]{1,13}\.[0-9]{2}$/D', $value['buyer_total_estimate'])) {
                $data['buyer_total_estimate'] = $value['buyer_total_estimate'];
            }
        }
        $payout = self::parsePayout(isset($value['payout']) ? $value['payout'] : null);
        if ($payout !== null) {
            $data['payout'] = $payout;
            $payouts = array();
            if (isset($value['payouts']) && is_array($value['payouts'])) {
                foreach (array_slice(array_values($value['payouts']), 0, 10) as $item) {
                    $item = self::parsePayout($item);
                    if ($item !== null) {
                        $payouts[] = $item;
                    }
                }
            }
            if (count($payouts) > 1) {
                $data['payouts'] = $payouts;
            }
        }
        return new self($data);
    }

    /**
     * @param mixed $payout Payout object.
     * @return array|null {amount, asset, tx_hash}
     */
    private static function parsePayout($payout)
    {
        if (is_array($payout) && is_string(isset($payout['amount']) ? $payout['amount'] : null) && preg_match('/^[0-9]{1,30}(?:\.[0-9]{1,18})?$/D', $payout['amount'])
            && is_string(isset($payout['asset']) ? $payout['asset'] : null) && preg_match('/^[A-Za-z][A-Za-z0-9.]{0,15}$/D', $payout['asset'])
            && is_string(isset($payout['tx_hash']) ? $payout['tx_hash'] : null) && preg_match('/^0x[0-9a-fA-F]{64}$/D', $payout['tx_hash'])) {
            return array('amount' => $payout['amount'], 'asset' => $payout['asset'], 'tx_hash' => strtolower($payout['tx_hash']));
        }
        return null;
    }

    /** @return string */
    public function id()
    {
        return $this->data['id'];
    }

    /** @return string */
    public function status()
    {
        return $this->data['status'];
    }

    /** @return string */
    public function amount()
    {
        return $this->data['amount'];
    }

    /** @return string */
    public function currency()
    {
        return $this->data['currency'];
    }

    /** @return string '' when absent */
    /**
     * The order total in US dollars: the amount itself, or its conversion fixed when the checkout was created.
     *
     * @return string
     */
    public function usdAmount()
    {
        return isset($this->data['usd_amount']) ? $this->data['usd_amount'] : $this->data['amount'];
    }

    /**
     * US dollars for one unit of the checkout's currency; '' for a US dollar checkout.
     *
     * @return string
     */
    public function fxRate()
    {
        return isset($this->data['fx_rate']) ? $this->data['fx_rate'] : '';
    }

    public function checkoutUrl()
    {
        return isset($this->data['checkout_url']) ? $this->data['checkout_url'] : '';
    }

    /** @return string '' when absent */
    public function orderId()
    {
        return isset($this->data['order_id']) ? $this->data['order_id'] : '';
    }

    /** @return string '' when absent */
    public function expiresAt()
    {
        return isset($this->data['expires_at']) ? $this->data['expires_at'] : '';
    }

    /** @return int|null Unix seconds */
    public function expiresAtTime()
    {
        if (!isset($this->data['expires_at'])) {
            return null;
        }
        $time = strtotime($this->data['expires_at']);
        return $time === false ? null : $time;
    }

    /** @return string Who pays the fees: "seller" (no fee_mode on the checkout), "split" or "buyer". */
    public function feeMode()
    {
        return isset($this->data['fee_mode']) ? $this->data['fee_mode'] : 'seller';
    }

    /** @return string What the buyer pays before the card provider's own fee, or '' for a seller checkout. */
    public function buyerTotalEstimate()
    {
        return isset($this->data['buyer_total_estimate']) ? $this->data['buyer_total_estimate'] : '';
    }

    /** @return string '' when absent */
    public function paidAmount()
    {
        return isset($this->data['paid_amount']) ? $this->data['paid_amount'] : '';
    }

    /** @return array|null {amount, asset, tx_hash} */
    public function payout()
    {
        return isset($this->data['payout']) ? $this->data['payout'] : null;
    }

    /** @return array Every transfer, oldest first (one item when paid in one transfer). */
    public function payouts()
    {
        if (isset($this->data['payouts'])) {
            return $this->data['payouts'];
        }
        return isset($this->data['payout']) ? array($this->data['payout']) : array();
    }

    /** @return string '' until paid */
    public function txHash()
    {
        return isset($this->data['payout']) ? $this->data['payout']['tx_hash'] : '';
    }

    /** @return array */
    public function toArray()
    {
        return $this->data;
    }

    /**
     * An absolute https URL (http only on loopback staging) whose path ends with the checkout id.
     *
     * @return bool
     */
    public function hasValidCheckoutUrl()
    {
        $url = $this->checkoutUrl();
        $parts = $url !== '' && strlen($url) <= 2048 ? parse_url($url) : false;
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $scheme = strtolower(isset($parts['scheme']) ? $parts['scheme'] : '');
        if ($scheme !== 'https' && !($scheme === 'http' && Urls::isLoopback($parts['host']))) {
            return false;
        }
        $path = isset($parts['path']) ? $parts['path'] : '';
        $suffix = '/' . $this->id();
        return strlen($path) > strlen($suffix) && substr($path, -strlen($suffix)) === $suffix;
    }
}
