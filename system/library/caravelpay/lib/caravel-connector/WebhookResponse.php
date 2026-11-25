<?php
namespace CaravelPay\Connector;

/**
 * The answer to a webhook: any 2xx marks it delivered; anything else is retried for 24 hours.
 */
final class WebhookResponse
{
    /** @var int */
    public $status;
    /** @var array */
    public $body;

    /**
     * @param int   $status HTTP status.
     * @param array $body   JSON body.
     */
    public function __construct($status, array $body)
    {
        $this->status = (int) $status;
        $this->body = $body;
    }

    /** @return string */
    public function json()
    {
        return (string) json_encode($this->body, JSON_UNESCAPED_SLASHES);
    }

    /** @return array Headers every platform should send. */
    public static function headers()
    {
        return array('Content-Type' => 'application/json', 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0');
    }

    /** @return string HTTP reason phrase. */
    public function reason()
    {
        $reasons = array(200 => 'OK', 400 => 'Bad Request', 401 => 'Unauthorized', 404 => 'Not Found', 405 => 'Method Not Allowed', 409 => 'Conflict', 503 => 'Service Unavailable');
        return isset($reasons[$this->status]) ? $reasons[$this->status] : 'Error';
    }
}
