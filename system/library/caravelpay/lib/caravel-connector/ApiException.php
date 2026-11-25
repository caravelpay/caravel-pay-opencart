<?php
namespace CaravelPay\Connector;

/**
 * A failed Caravel Pay API call: the `{error: {code, message, param}}` envelope, or a transport
 * failure (status 0, code "network"). Messages are the API's own text, bounded and single-line.
 */
class ApiException extends \RuntimeException
{
    /** @var int */
    private $httpStatus;
    /** @var string */
    private $errorCode;
    /** @var string */
    private $param;
    /** @var int|null */
    private $retryAfter;

    /**
     * @param int         $httpStatus HTTP status, 0 for transport failures.
     * @param string      $errorCode  API error code, or network / invalid_response / not_connected / encoding.
     * @param string      $message    Human text.
     * @param string      $param      Field the error is about.
     * @param int|null    $retryAfter Retry-After seconds.
     */
    public function __construct($httpStatus, $errorCode, $message = '', $param = '', $retryAfter = null)
    {
        parent::__construct(self::clean($message !== '' ? $message : 'Caravel Pay returned an error (' . $errorCode . ').'));
        $this->httpStatus = (int) $httpStatus;
        $this->errorCode = preg_match('/^[a-z0-9_]{1,64}$/D', (string) $errorCode) ? (string) $errorCode : 'unknown';
        $this->param = self::clean((string) $param);
        $this->retryAfter = $retryAfter === null ? null : max(0, (int) $retryAfter);
    }

    /** @return int */
    public function httpStatus()
    {
        return $this->httpStatus;
    }

    /** @return string */
    public function errorCode()
    {
        return $this->errorCode;
    }

    /** @return string */
    public function param()
    {
        return $this->param;
    }

    /** @return int|null */
    public function retryAfter()
    {
        return $this->retryAfter;
    }

    /**
     * True when the API proves nothing was created (validation or authorization failures).
     *
     * @return bool
     */
    public function createdNothing()
    {
        return in_array($this->httpStatus, array(400, 401, 403), true);
    }

    /**
     * @param string $text Text.
     * @return string
     */
    private static function clean($text)
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $text)));
        return function_exists('mb_substr') ? mb_substr($text, 0, 300) : substr($text, 0, 300);
    }
}
