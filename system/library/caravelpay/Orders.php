<?php
namespace CaravelPay\OpenCart;

use CaravelPay\Connector\Checkout;
use CaravelPay\Connector\CheckoutOrders;
use CaravelPay\Connector\Decision;
use CaravelPay\Connector\Money;
use CaravelPay\Connector\OrderAdapter;
use CaravelPay\Connector\OrderSnapshot;
use CaravelPay\Connector\StartSnapshot;

/**
 * OpenCart orders for the connector. Status changes go through model_checkout_order->addHistory(),
 * so stock, notifications and events behave as for any other payment extension.
 *
 * | Checkout status              | OpenCart order status (configurable)                         |
 * |------------------------------|--------------------------------------------------------------|
 * | awaiting_payment, confirming | Pending (unchanged; history comment when a payment is seen)  |
 * | paid                         | Processing, history comment with the payout tx hash          |
 * | underpaid, review            | On Hold (created on install), history comment                |
 * | expired                      | Canceled (a late paid still moves it to Processing)          |
 */
class Orders implements OrderAdapter, CheckoutOrders
{
    /** @var Store */
    private $store;
    /** @var object model_checkout_order (getOrder, addHistory) */
    private $model;
    /** @var array status ids: pending, paid, hold, expired, paid_statuses[] */
    private $statuses;
    /** @var callable array order -> amount in the order currency, or null */
    private $amountOf;

    /**
     * @param Store    $store    Tables.
     * @param object   $model    model_checkout_order.
     * @param array    $statuses {pending, paid, hold, expired, paid_statuses: int[]}
     * @param callable $amountOf Order total in the order currency.
     */
    public function __construct(Store $store, $model, array $statuses, $amountOf)
    {
        $this->store = $store;
        $this->model = $model;
        $this->statuses = $statuses;
        $this->amountOf = $amountOf;
    }

    /**
     * @param int $orderId Order id.
     * @return array|null
     */
    private function order($orderId)
    {
        $order = $this->model->getOrder((int) $orderId);
        return is_array($order) && !empty($order['order_id']) ? $order : null;
    }

    /**
     * @param array $order Order.
     * @return string OrderSnapshot state.
     */
    public function state(array $order)
    {
        $status = (int) $order['order_status_id'];
        if ($status === (int) $this->statuses['pending'] || $status === 0) {
            return OrderSnapshot::OPEN;
        }
        if ($status === (int) $this->statuses['hold']) {
            return OrderSnapshot::HELD;
        }
        if ($status === (int) $this->statuses['expired']) {
            return OrderSnapshot::CANCELLED;
        }
        if ($status === (int) $this->statuses['paid'] || in_array($status, array_map('intval', $this->statuses['paid_statuses']), true)) {
            return OrderSnapshot::PAID;
        }
        return OrderSnapshot::OTHER;
    }

    // ---------------------------------------------------------------- webhooks

    public function locate($checkoutId, $fetch)
    {
        $found = $this->store->findCheckout($checkoutId);
        return $found ? $found['order_id'] : null;
    }

    public function checkoutMerchant($ref, $checkoutId, $signer)
    {
        $found = $this->store->findCheckout($checkoutId);
        return $found && $found['order_id'] === (int) $ref ? $found['merchant_id'] : '';
    }

    public function lock($ref)
    {
        return $this->store->lock((int) $ref) ? array('order_id' => (int) $ref) : null;
    }

    public function unlock($lease)
    {
        if (is_array($lease)) {
            $this->store->unlock($lease['order_id']);
        }
    }

    public function owns($lease)
    {
        return is_array($lease);
    }

    public function snapshot($ref, Checkout $checkout)
    {
        $order = $this->order($ref);
        if ($order === null) {
            return null;
        }
        $record = $this->store->record((int) $ref);
        $checkouts = $this->store->checkouts((int) $ref);
        return OrderSnapshot::of(array(
            'ours' => isset($order['payment_method']['code']) ? strpos((string) $order['payment_method']['code'], 'caravelpay') === 0 && isset($checkouts[$checkout->id()]) : (isset($order['payment_code']) && strpos((string) $order['payment_code'], 'caravelpay') === 0 && isset($checkouts[$checkout->id()])),
            'expectedAmount' => is_array($record['request']) && isset($record['request']['amount']) ? (string) $record['request']['amount'] : null,
            // The currency of that request (US dollars for requests saved before 1.2.0).
            'expectedCurrency' => is_array($record['request']) && isset($record['request']['currency']) ? (string) $record['request']['currency'] : 'USD',
            'currentCheckoutId' => $record['checkout_id'],
            'marker' => $record['marker'],
            'state' => $this->state($order),
            'stateLabel' => isset($order['order_status']) ? (string) $order['order_status'] : '',
            'total' => call_user_func($this->amountOf, $order),
            'currency' => (string) $order['currency_code'],
            'paidAmount' => $record['paid_amount'],
            'transactionId' => $record['tx_hash'],
        ));
    }

    public function apply($ref, Decision $decision, Checkout $checkout)
    {
        $order = $this->order($ref);
        if ($order === null) {
            return false;
        }
        $fields = array();
        if ($decision->marker !== null) {
            $fields['marker'] = $decision->marker;
        }
        if ($decision->paidAmount !== null) {
            $fields['paid_amount'] = $decision->paidAmount;
        }
        switch ($decision->action) {
            case Decision::COMPLETE:
                $fields['tx_hash'] = $decision->txHash;
                $this->store->save((int) $ref, $fields);
                $comment = $decision->note . ($decision->followUpNote !== '' ? "\n" . $decision->followUpNote : '');
                $this->model->addHistory((int) $ref, (int) $this->statuses['paid'], $comment, true);
                $order = $this->order($ref);
                return $order !== null && $this->state($order) === OrderSnapshot::PAID;
            case Decision::HOLD:
                $this->store->save((int) $ref, $fields);
                $this->model->addHistory((int) $ref, (int) $this->statuses['hold'], $decision->note, false);
                return true;
            case Decision::CANCEL:
                $this->store->save((int) $ref, $fields);
                $this->model->addHistory((int) $ref, (int) $this->statuses['expired'], $decision->note, false);
                return true;
            case Decision::NOTE:
                $this->store->save((int) $ref, $fields);
                // Same status again: OpenCart records the comment in the order history.
                $this->model->addHistory((int) $ref, (int) $order['order_status_id'], $decision->note, false);
                return true;
            default:
                if ($fields) {
                    $this->store->save((int) $ref, $fields);
                }
                return true;
        }
    }

    public function recordEvent($ref, $eventId, $type)
    {
        $events = $this->store->record((int) $ref)['events'];
        $events[$eventId] = array('type' => $type, 'at' => gmdate('Y-m-d\TH:i:s\Z'));
        $this->store->save((int) $ref, array('events' => array_slice($events, -20, null, true)));
    }

    // ---------------------------------------------------------------- starting a payment

    public function startSnapshot($ref)
    {
        $order = $this->order($ref);
        if ($order === null) {
            return null;
        }
        $record = $this->store->record((int) $ref);
        $checkouts = $this->store->checkouts((int) $ref);
        return StartSnapshot::of(array(
            'payable' => $this->state($order) === OrderSnapshot::OPEN,
            'amount' => call_user_func($this->amountOf, $order),
            'currency' => (string) $order['currency_code'],
            'email' => isset($order['email']) ? (string) $order['email'] : '',
            'request' => $record['request'],
            'attempt' => $record['attempt'],
            'requestMerchant' => $record['request_merchant'],
            'currentCheckoutId' => $record['checkout_id'],
            'currentMerchant' => isset($checkouts[$record['checkout_id']]) ? $checkouts[$record['checkout_id']] : '',
            'url' => $record['checkout_url'],
            'expiresAt' => $record['expires_at'],
        ));
    }

    public function saveRequest($ref, array $body, $attempt, $merchantId)
    {
        $this->store->save((int) $ref, array('request' => $body, 'attempt' => (int) $attempt, 'request_merchant' => $merchantId));
    }

    public function dropRequest($ref, array $body)
    {
        $record = $this->store->record((int) $ref);
        if ($record['checkout_id'] === '' && $record['request'] === $body) {
            $this->store->save((int) $ref, array('request' => null));
        }
    }

    public function linkCheckout($ref, Checkout $checkout, $merchantId, $isNew, $note)
    {
        $this->store->link($checkout->id(), (int) $ref, $merchantId);
        $fields = array('checkout_id' => $checkout->id(), 'checkout_url' => $checkout->checkoutUrl(), 'expires_at' => $checkout->expiresAt());
        if ($isNew) {
            $fields['marker'] = '';
        }
        $this->store->save((int) $ref, $fields);
    }

    /**
     * "<order id>-<random nonce>": OpenCart order ids have no random part, so the extension keeps one
     * per order. A restored database or a reinstalled shop can then never collide with an older order.
     */
    public function reference($ref, $attempt)
    {
        $record = $this->store->record((int) $ref);
        $nonce = $record['nonce'];
        if (!preg_match('/^[a-z0-9]{6}$/D', $nonce)) {
            $nonce = substr(strtolower(base_convert(bin2hex(random_bytes(6)), 16, 36)), 0, 6);
            $nonce = str_pad($nonce, 6, '0');
            $this->store->save((int) $ref, array('nonce' => $nonce));
        }
        $reference = (int) $ref . '-' . $nonce;
        return $attempt > 1 ? $reference . '-' . (int) $attempt : $reference;
    }
}
