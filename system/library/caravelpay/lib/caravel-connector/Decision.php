<?php
namespace CaravelPay\Connector;

/**
 * What to do with an order after a verified checkout status. The platform adapter applies it.
 */
final class Decision
{
    const NONE = 'none';
    const NOTE = 'note';
    const HOLD = 'hold';
    const CANCEL = 'cancel';
    const COMPLETE = 'complete';

    /** @var string mismatch | ignored | unchanged | applied | completed | held */
    public $result;
    /** @var int HTTP status for the webhook response (2xx = done, 409 = refuse, 503 = retry). */
    public $http;
    /** @var string One of the action constants. */
    public $action = self::NONE;
    /** @var string Plain-text note for the order ('' = none). */
    public $note = '';
    /** @var string Second note after completing (late payment of a cancelled order). */
    public $followUpNote = '';
    /** @var string|null Status marker to remember (null = leave). */
    public $marker;
    /** @var string|null Underpaid amount to remember (null = leave). */
    public $paidAmount;
    /** @var string Payout transaction hash ('' until paid). */
    public $txHash = '';
    /** @var string Checkout status the decision was made for. */
    public $status = '';
    /** @var string Short log text. */
    public $log = '';
    /** @var bool True when completing an order that was cancelled (expired -> paid). */
    public $fromCancelled = false;

    /**
     * @param string $result Result.
     * @param int    $http   HTTP status.
     * @param string $log    Log text.
     * @return Decision
     */
    public static function make($result, $http, $log)
    {
        $decision = new self();
        $decision->result = $result;
        $decision->http = $http;
        $decision->log = $log;
        return $decision;
    }

    /** @return bool */
    public function succeeded()
    {
        return $this->http >= 200 && $this->http < 300;
    }
}
