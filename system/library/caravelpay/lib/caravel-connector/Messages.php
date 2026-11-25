<?php
namespace CaravelPay\Connector;

/**
 * Every text the connector produces, by key, in English. Platforms pass their own translated
 * templates (same keys, same %1$s placeholders) to the constructor; missing keys fall back to
 * English. Order notes are plain text (no HTML), so every platform can show them safely.
 */
final class Messages
{
    /** @var array<string,string> */
    private $templates;

    /**
     * @param array<string,string> $translated Platform templates by key.
     */
    public function __construct(array $translated = array())
    {
        $this->templates = array_merge(self::english(), array_filter($translated, 'is_string'));
    }

    /**
     * @return array<string,string>
     */
    public static function english()
    {
        return array(
            // Settings status line.
            'status.not_connected' => 'Not connected',
            'status.enter_wallet' => 'Enter your payout wallet and save to connect.',
            'status.error_detail' => '%1$s (%2$s)',
            'status.paused' => 'Paused',
            'status.paused_detail' => 'New checkouts are paused: %1$s.',
            'status.paused_no_reason' => 'New checkouts are paused: no reason was given.',
            'status.paused_hint' => 'Caravel Pay is hidden at checkout. Open checkouts and payouts still finish. Email hello@caravelpay.app with your store URL if you have questions.',
            'status.pending_review' => 'Waiting for approval',
            'status.pending_review_detail' => 'Caravel Pay is reviewing this store. It stays hidden at checkout until it is approved.',
            'status.connected' => 'Connected',
            'status.connected_detail' => 'Payouts go to %1$s, minus the %2$s Caravel fee. Merchant ID %3$s.',
            'status.connected_id' => 'Merchant ID %1$s. Caravel fee: %2$s.',
            'status.new_wallet_failed' => 'The new wallet %1$s is not connected yet: %2$s. Payouts still go to the wallet above. %3$s',
            'status.reconnect_failed' => 'The last reconnect failed: %1$s. The existing connection keeps working. %2$s',
            'status.stale' => 'The status could not be refreshed just now; this is the last known status.',
            // What to do about a connect or status error.
            'hint.invalid_url' => 'Your store address must be a public https:// address.',
            'hint.rate_limited' => 'Too many connection attempts. Wait a few minutes, then reconnect.',
            'hint.unreachable' => 'Caravel Pay could not be reached just now. Check that your server can make outgoing HTTPS requests to api.caravelpay.app, then reconnect.',
            'hint.credentials_rejected' => 'Caravel Pay did not accept the saved credentials. Reconnect to get new ones.',
            'hint.clock' => 'Your server clock is more than 5 minutes off. Sync it with NTP, then reload this page.',
            'hint.api_base' => 'The Caravel Pay API address must be an https:// address with no path.',
            'hint.wallet_limit' => 'This payout wallet can\'t be connected right now. Use another wallet, or email hello@caravelpay.app with your store URL.',
            'hint.wallet' => 'Check the payout wallet: it must be a Polygon address you control (not a token contract), then save again.',
            'hint.generic' => 'Check the payout wallet, then reconnect. If it keeps failing, email hello@caravelpay.app with your store URL.',
            // Order notes (plain text).
            'note.created' => 'Caravel Pay checkout %1$s created. The order updates when the payment is confirmed.',
            'note.confirming' => 'Caravel Pay detected a payment and is waiting for confirmation. Don\'t ship yet.',
            'note.underpaid' => 'Caravel Pay: underpaid. $%1$s of $%2$s arrived (checkout %3$s). Hold the order and contact the buyer: accept it, ask for the difference, or refund from your wallet.',
            'note.review' => 'Caravel Pay: the payment for checkout %1$s needs a manual review (for example a payment after expiry or in a token that is not credited automatically). Keep the order on hold; a follow-up webhook arrives if it is resolved.',
            'note.expired' => 'Caravel Pay checkout %1$s expired without payment.',
            'note.expired_held' => 'Caravel Pay checkout %1$s expired. The order is on hold, so it was not cancelled.',
            'note.settled_changed' => 'Caravel Pay checkout %1$s is now "%2$s". The order status was left unchanged; please review the payment.',
            'note.paid' => 'Caravel Pay payment confirmed (checkout %1$s). %2$s',
            'note.paid_late' => 'This order had been cancelled after its checkout expired; the payment arrived late and completed it.',
            'note.double_payment' => 'Caravel Pay checkout %1$s was paid, but this order was already paid. Check for a double payment. %2$s',
            'note.paid_not_completable' => 'Caravel Pay checkout %1$s was paid while the order is "%2$s". The status was left unchanged; please review. %3$s',
            'note.total_changed' => 'Caravel Pay received $%1$s, but the order total is now %2$s. The order was put on hold instead of being completed; check it before shipping. %3$s',
            'note.payout' => 'Payout: %1$s %2$s to your wallet. Transaction: %3$s',
            'note.payout_unknown' => 'Payout details are not available yet.',
            'note.fees_split' => 'Fees: the card provider\'s fee is added to the buyer\'s total.',
            'note.fees_buyer' => 'Fees: the buyer pays both fees and is charged $%1$s before the card provider\'s fee.',
            // Buyer-facing lines shown with the payment method when a fee is added at payment.
            'fees.notice_split' => 'The card provider adds its fee to your total at payment. You see it before you pay.',
            'fees.notice_buyer' => 'A processing fee and the card provider\'s fee are added to your total at payment. You see them before you pay.',
            // Settings: the "Who pays the fees" option.
            'fees.setting' => 'Who pays the fees',
            'fees.option_seller' => 'Seller pays everything (default)',
            'fees.option_split' => 'Split: buyer pays the card fee',
            'fees.option_buyer' => 'Buyer pays everything',
            'fees.help' => 'Seller pays everything: your buyer pays the order total, and the card provider\'s fee and the 2% Caravel fee come off what you receive. Split: the card provider\'s fee is added to your buyer\'s total, and you pay the 2% Caravel fee. Buyer pays everything: the 2% Caravel fee is added to your buyer\'s total too, so you receive the full order total. Card surcharges are restricted in some countries (for example on consumer cards in the EU and the UK): check your local rules before passing fees on.',
            'note.cancel_scheduled' => 'Caravel Pay checkout %1$s expired without payment. The order is cancelled after %2$s hours unless a late payment confirms first.',
            'note.paid_after_cancel' => 'Caravel Pay checkout %1$s was paid after this order was cancelled, and the order can\'t be reopened automatically. The money is in your wallet: fulfil a replacement order or refund the buyer. %2$s',
            // Buyer-facing errors when a payment can't start.
            'start.busy' => 'This order is already being prepared. Please try again in a moment.',
            'start.cannot_pay' => 'This order can no longer be paid. Please go back to checkout.',
            // The key is from when only US dollars were taken; the text is for a currency Caravel Pay does not convert.
            'start.usd_only' => 'Caravel Pay can\'t take orders in this currency. Please choose another payment method.',
            'note.currency' => 'Currency: %1$s %2$s is charged as $%3$s (1 %2$s = %4$s USD, fixed for this checkout).',
            'start.unavailable' => 'This payment method is temporarily unavailable. Please choose another one.',
            'start.changed' => 'This order changed after its payment was started. Please contact the store.',
            'start.changed_during' => 'This order changed while its payment was being prepared. Please contact the store.',
            'start.temporarily' => 'Caravel Pay is temporarily unavailable. Please try again.',
            'start.in_progress' => 'A payment for this order is already in progress. Please wait for it to finish or contact the store.',
            'start.invalid_response' => 'Caravel Pay returned an unexpected answer. Please try again.',
            'start.limit' => 'This order can\'t be paid with Caravel Pay right now. Please choose another payment method.',
            'start.amount' => 'Caravel Pay can\'t take this order amount. Please choose another payment method.',
            'start.misconfigured' => 'This payment method is not set up correctly. Please choose another one or contact the store.',
            'start.already_paid' => 'A payment for this invoice has already been received.',
            'start.too_many_invoices' => 'Please pay these invoices one at a time.',
        );
    }

    /**
     * @param string $key    Message key.
     * @param array  $params Positional values; an [key, params] pair renders a nested message.
     * @return string
     */
    public function render($key, array $params = array())
    {
        if ($key === '') {
            return '';
        }
        $template = isset($this->templates[$key]) ? $this->templates[$key] : $key;
        if (!$params) {
            return $template;
        }
        $values = array();
        foreach (array_values($params) as $param) {
            $values[] = is_array($param) ? $this->pair($param) : (string) $param;
        }
        try {
            $text = vsprintf($template, $values);
        } catch (\Throwable $error) {
            // PHP 8 throws on a template with more placeholders than values (a bad translation).
            return $template;
        }
        return is_string($text) ? trim($text) : $template;
    }

    /**
     * @param array $pair [key, params]
     * @return string
     */
    public function pair(array $pair)
    {
        return $this->render(isset($pair[0]) ? (string) $pair[0] : '', isset($pair[1]) && is_array($pair[1]) ? $pair[1] : array());
    }

    /**
     * A sentence for the note written when a checkout is created, saying who pays the fees. '' for the default.
     *
     * @param Checkout $checkout Created checkout.
     * @return string
     */
    public function feeNote(Checkout $checkout)
    {
        if ($checkout->feeMode() === 'split') {
            return $this->render('note.fees_split');
        }
        if ($checkout->feeMode() === 'buyer') {
            return $this->render('note.fees_buyer', array($checkout->buyerTotalEstimate() !== '' ? $checkout->buyerTotalEstimate() : $checkout->amount()));
        }
        return '';
    }

    /**
     * A sentence for the note written when a checkout is created in another currency. '' for US dollars.
     *
     * @param Checkout $checkout Checkout.
     * @return string
     */
    public function currencyNote(Checkout $checkout)
    {
        if ($checkout->currency() === 'USD' || $checkout->fxRate() === '') {
            return '';
        }
        return $this->render('note.currency', array($checkout->amount(), $checkout->currency(), $checkout->usdAmount(), $checkout->fxRate()));
    }

    /**
     * The line buyers see with the payment method when a fee is added at payment. '' for the default.
     *
     * @param mixed $mode Saved "Who pays the fees" setting.
     * @return string
     */
    public function feeNotice($mode)
    {
        $mode = \CaravelPay\FeeMode::normalize($mode);
        return $mode === 'seller' ? '' : $this->render('fees.notice_' . $mode);
    }

    /**
     * The three options of the "Who pays the fees" setting, value => label, the default first.
     *
     * @return array<string,string>
     */
    public function feeOptions()
    {
        $options = array();
        foreach (\CaravelPay\FeeMode::ALL as $mode) {
            $options[$mode] = $this->render('fees.option_' . $mode);
        }
        return $options;
    }

    /**
     * The payout part of a paid note, plain text, one sentence per transfer.
     *
     * @param Checkout $checkout Paid checkout.
     * @return string
     */
    public function payoutNote(Checkout $checkout)
    {
        $payouts = $checkout->payouts();
        if (!$payouts) {
            return $this->render('note.payout_unknown');
        }
        $notes = array();
        foreach ($payouts as $payout) {
            $link = Urls::explorer($payout['tx_hash']);
            $notes[] = $this->render('note.payout', array($payout['amount'], $payout['asset'], $link !== '' ? $link : $payout['tx_hash']));
        }
        return implode(' ', $notes);
    }
}
