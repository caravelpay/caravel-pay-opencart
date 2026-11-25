<?php
namespace CaravelPay\Connector;

/**
 * The non-wallet half of a POST /v1/connect body.
 */
final class ConnectInput
{
    /** @var string */
    private $siteUrl;
    /** @var string */
    private $email;
    /** @var string */
    private $pluginVersion;

    /**
     * @param string $siteUrl       The store's public HTTPS address.
     * @param string $email         Contact for operational notices ('' = none).
     * @param string $platform      Platform slug, e.g. "prestashop".
     * @param string $version       Module version.
     */
    public function __construct($siteUrl, $email, $platform, $version)
    {
        $this->siteUrl = Urls::siteUrl($siteUrl);
        $this->email = is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false && strlen($email) <= 254 ? $email : '';
        // The API's platform field is "woocommerce" | "api"; the platform travels in plugin_version
        // as "<platform>-<version>" (the same convention as the other Caravel Pay modules).
        $token = preg_replace('/[^a-z0-9._]/', '', strtolower((string) $platform)) . '-' . preg_replace('/[^A-Za-z0-9._-]/', '', (string) $version);
        $this->pluginVersion = substr($token, 0, 32);
    }

    /** @return string */
    public function siteUrl()
    {
        return $this->siteUrl;
    }

    /**
     * @param string $wallet Payout wallet.
     * @return array
     */
    public function payload($wallet)
    {
        $payload = array(
            'site_url' => $this->siteUrl,
            'payout_address' => $wallet,
            'platform' => 'api',
            'plugin_version' => $this->pluginVersion,
        );
        if ($this->email !== '') {
            $payload['email'] = $this->email;
        }
        return $payload;
    }
}
