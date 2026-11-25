<?php
namespace CaravelPay\Connector;

/**
 * An order as the checkout starter sees it.
 */
final class StartSnapshot
{
    /** @var bool Caravel Pay order, unpaid and still waiting for payment. */
    public $payable = false;
    /** @var string|null Order total. */
    public $amount;
    /** @var string */
    public $currency = 'USD';
    /** @var string Buyer email ('' = none). */
    public $email = '';
    /** @var array|null Saved request body. */
    public $request;
    /** @var int */
    public $attempt = 1;
    /** @var string Merchant that sent the saved request. */
    public $requestMerchant = '';
    /** @var string Newest checkout id. */
    public $currentCheckoutId = '';
    /** @var string Its merchant. */
    public $currentMerchant = '';
    /** @var string Its checkout URL. */
    public $url = '';
    /** @var string Its expires_at. */
    public $expiresAt = '';

    /**
     * @param array $fields Property => value.
     * @return StartSnapshot
     */
    public static function of(array $fields)
    {
        $snapshot = new self();
        foreach ($fields as $name => $value) {
            if (!property_exists($snapshot, $name)) {
                throw new \InvalidArgumentException('Unknown start field ' . $name);
            }
            $snapshot->$name = $value;
        }
        return $snapshot;
    }
}
