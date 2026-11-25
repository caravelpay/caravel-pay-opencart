<?php
namespace CaravelPay\Connector;

/**
 * One merchant's API credentials. The secret never appears in var_dump/print_r/json output.
 */
final class Credentials
{
    const MERCHANT_ID = '/^mch_[A-Za-z0-9]{4,64}$/D';
    /** Accepted secrets: printable ASCII, 32 to 256 characters (the API issues 64 hex). */
    const SECRET = '/^[\x21-\x7e]{32,256}$/D';

    /** @var string */
    private $merchantId;
    /** @var string */
    private $secret;

    /**
     * @param string $merchantId Merchant id (mch_…).
     * @param string $secret     Signing secret.
     */
    public function __construct($merchantId, $secret)
    {
        if (!self::validMerchantId($merchantId) || !self::validSecret($secret)) {
            throw new \InvalidArgumentException('Invalid Caravel Pay credentials.');
        }
        $this->merchantId = $merchantId;
        $this->secret = $secret;
    }

    /**
     * Credentials when both values are valid, otherwise null.
     *
     * @param mixed $merchantId Merchant id.
     * @param mixed $secret     Secret.
     * @return Credentials|null
     */
    public static function tryCreate($merchantId, $secret)
    {
        return self::validMerchantId($merchantId) && self::validSecret($secret) ? new self($merchantId, $secret) : null;
    }

    /**
     * @param mixed $value Candidate.
     * @return bool
     */
    public static function validMerchantId($value)
    {
        return is_string($value) && preg_match(self::MERCHANT_ID, $value) === 1;
    }

    /**
     * @param mixed $value Candidate.
     * @return bool
     */
    public static function validSecret($value)
    {
        return is_string($value) && preg_match(self::SECRET, $value) === 1;
    }

    /** @return string */
    public function merchantId()
    {
        return $this->merchantId;
    }

    /** @return string */
    public function secret()
    {
        return $this->secret;
    }

    /** @return array */
    public function __debugInfo()
    {
        return array('merchantId' => $this->merchantId, 'secret' => '[redacted]');
    }
}
