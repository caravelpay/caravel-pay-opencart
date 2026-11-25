<?php
namespace CaravelPay\Connector;

/**
 * Where to send the buyer, or a buyer-safe message key (Messages) explaining why not.
 */
final class StartResult
{
    /** @var string */
    public $redirect = '';
    /** @var string */
    public $error = '';
    /** @var Checkout|null */
    public $checkout;

    /**
     * @param string        $url      Checkout URL.
     * @param Checkout|null $checkout Checkout.
     * @return StartResult
     */
    public static function redirect($url, $checkout = null)
    {
        $result = new self();
        $result->redirect = (string) $url;
        $result->checkout = $checkout;
        return $result;
    }

    /**
     * @param string $key Message key.
     * @return StartResult
     */
    public static function error($key)
    {
        $result = new self();
        $result->error = (string) $key;
        return $result;
    }

    /** @return bool */
    public function ok()
    {
        return $this->redirect !== '';
    }
}
