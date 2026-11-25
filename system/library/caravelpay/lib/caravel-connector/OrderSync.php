<?php
namespace CaravelPay\Connector;

/**
 * The checkout-status -> order rules, shared by every platform (the WooCommerce plugin's rules):
 *
 * | Checkout status              | Order                                                       |
 * |------------------------------|-------------------------------------------------------------|
 * | awaiting_payment, confirming | unchanged (confirming adds a "don't ship yet" note)         |
 * | paid                         | complete with the payout tx hash (also from held/cancelled) |
 * | underpaid                    | hold, note with paid_amount                                 |
 * | review                       | hold, note                                                  |
 * | expired                      | cancel when still open (held orders are not cancelled)      |
 *
 * The checkout is always one re-fetched with signed GET /v1/checkouts/{id}, never a webhook body.
 */
final class OrderSync
{
    /** @var Messages */
    private $messages;

    /**
     * @param Messages $messages Texts.
     */
    public function __construct(Messages $messages)
    {
        $this->messages = $messages;
    }

    /**
     * @param OrderSnapshot $order    Fresh order state.
     * @param Checkout      $checkout Verified checkout.
     * @return Decision
     */
    public function decide(OrderSnapshot $order, Checkout $checkout)
    {
        $checkoutId = $checkout->id();
        if (!$order->ours) {
            return Decision::make('mismatch', 409, 'The checkout does not belong to this order.');
        }
        // What this store asked to be paid, in the order's own currency. The same currency on both sides: amounts in
        // different currencies are never compared.
        $asked = \CaravelPay\Currency::code($order->expectedCurrency);
        $expected = $order->expectedAmount !== null && \CaravelPay\Currency::isSupported($asked) ? Money::amount($order->expectedAmount, $asked) : null;
        if ($expected === null || $checkout->amount() !== $expected || $checkout->currency() !== $asked) {
            return Decision::make('mismatch', 409, 'The checkout amount does not match this order.');
        }
        $status = $checkout->status();
        $isCurrent = $order->currentCheckoutId === $checkoutId;
        $marker = $status . ($isCurrent ? '' : '@' . $checkoutId);
        $changed = $order->marker !== $marker;

        if ($status === 'paid') {
            return $this->paid($order, $checkout, $expected);
        }
        if (!$isCurrent && in_array($status, array('awaiting_payment', 'confirming', 'expired'), true)) {
            // An older attempt replaced by a newer checkout: nothing to change.
            return Decision::make('ignored', 200, 'Older checkout; the order follows its newest checkout.');
        }
        if (!in_array($order->state, array(OrderSnapshot::OPEN, OrderSnapshot::HELD, OrderSnapshot::CANCELLED), true)) {
            $decision = Decision::make('unchanged', 200, 'Order already settled.');
            if ($changed && in_array($status, array('underpaid', 'review'), true)) {
                $decision->action = Decision::NOTE;
                $decision->note = $this->messages->render('note.settled_changed', array($checkoutId, $status));
                $decision->marker = $marker;
            }
            return $this->tag($decision, $status);
        }
        $decision = Decision::make('applied', 200, $status);
        $decision->marker = $marker;
        switch ($status) {
            case 'underpaid':
                $paid = $checkout->paidAmount();
                $decision->paidAmount = $paid;
                if ($changed || $paid !== $order->paidAmount || $order->state !== OrderSnapshot::HELD) {
                    $decision->action = Decision::HOLD;
                    // Both figures in US dollars: what arrived, and the order total as it was charged (its conversion, for an
                    // order in another currency).
                    $decision->note = $this->messages->render('note.underpaid', array($paid !== '' ? $paid : '0.00', $asked === 'USD' ? $expected : $checkout->usdAmount(), $checkoutId));
                }
                break;
            case 'review':
                if ($changed || $order->state !== OrderSnapshot::HELD) {
                    $decision->action = Decision::HOLD;
                    $decision->note = $this->messages->render('note.review', array($checkoutId));
                }
                break;
            case 'expired':
                if ($order->state === OrderSnapshot::OPEN) {
                    $decision->action = Decision::CANCEL;
                    $decision->note = $this->messages->render('note.expired', array($checkoutId));
                } elseif ($changed && $order->state !== OrderSnapshot::CANCELLED) {
                    $decision->action = Decision::NOTE;
                    $decision->note = $this->messages->render('note.expired_held', array($checkoutId));
                }
                break;
            case 'confirming':
                if ($changed) {
                    $decision->action = Decision::NOTE;
                    $decision->note = $this->messages->render('note.confirming');
                }
                break;
            default:
                break;
        }
        return $this->tag($decision, $status);
    }

    /**
     * paid: complete the order once, from open, held or cancelled (expired -> paid).
     *
     * @param OrderSnapshot $order    Order.
     * @param Checkout      $checkout Checkout.
     * @param string        $expected Checkout amount.
     * @return Decision
     */
    private function paid(OrderSnapshot $order, Checkout $checkout, $expected)
    {
        $checkoutId = $checkout->id();
        $txHash = $checkout->txHash();
        $payoutNote = $this->messages->payoutNote($checkout);
        if ($order->state === OrderSnapshot::PAID) {
            $decision = Decision::make('unchanged', 200, 'Order already paid.');
            if ($order->marker !== 'paid' && $order->marker !== 'paid@' . $checkoutId && $order->transactionId !== $txHash) {
                $decision->action = Decision::NOTE;
                $decision->note = $this->messages->render('note.double_payment', array($checkoutId, $payoutNote));
                $decision->marker = 'paid';
            }
            return $this->tag($decision, 'paid', $txHash);
        }
        if (!in_array($order->state, array(OrderSnapshot::OPEN, OrderSnapshot::HELD, OrderSnapshot::CANCELLED), true)) {
            $decision = Decision::make('unchanged', 200, 'Order not completable.');
            $decision->action = Decision::NOTE;
            $decision->note = $this->messages->render('note.paid_not_completable', array($checkoutId, $order->stateLabel !== '' ? $order->stateLabel : $order->state, $payoutNote));
            $decision->marker = 'paid';
            return $this->tag($decision, 'paid', $txHash);
        }
        $asked = $checkout->currency();
        $total = $order->total !== null ? Money::amount($order->total, $asked) : null;
        if ($total !== $expected || strtoupper((string) $order->currency) !== $asked) {
            $decision = Decision::make('held', 200, 'Order total changed.');
            $decision->action = Decision::HOLD;
            $decision->note = $this->messages->render('note.total_changed', array($asked === 'USD' ? $expected : $checkout->usdAmount() . ' (' . $expected . ' ' . $asked . ')', (string) $order->total . ' ' . $order->currency, $payoutNote));
            $decision->marker = 'paid';
            return $this->tag($decision, 'paid', $txHash);
        }
        $decision = Decision::make('completed', 200, 'paid');
        $decision->action = Decision::COMPLETE;
        $decision->note = $this->messages->render('note.paid', array($checkoutId, $payoutNote));
        $decision->marker = 'paid';
        $decision->fromCancelled = $order->state === OrderSnapshot::CANCELLED;
        if ($decision->fromCancelled) {
            $decision->followUpNote = $this->messages->render('note.paid_late');
        }
        return $this->tag($decision, 'paid', $txHash);
    }

    /**
     * @param Decision $decision Decision.
     * @param string   $status   Checkout status.
     * @param string   $txHash   Payout hash.
     * @return Decision
     */
    private function tag(Decision $decision, $status, $txHash = '')
    {
        $decision->status = $status;
        $decision->txHash = $txHash;
        return $decision;
    }
}
