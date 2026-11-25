<?php
/**
 * Caravel Pay: get API credentials from the command line (POST /v1/connect), for platforms whose
 * settings can't connect by themselves. Run it on the server, never through the web:
 *
 *   php connect-cli.php --wallet=0xYourPolygonWallet --site=https://billing.example [--email=you@billing.example] [--platform=hostbill]
 *
 * It prints the merchant id and secret once; paste them into the gateway settings. Every run creates
 * a NEW merchant: when you replace credentials, keep the old pair as "previous credentials" until the
 * checkouts created with it have finished (their webhooks stay signed with the old secret).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/autoload.php';

$options = getopt('', array('wallet:', 'site:', 'email::', 'platform::', 'version::'));
$wallet = isset($options['wallet']) ? trim((string) $options['wallet']) : '';
$site = isset($options['site']) ? trim((string) $options['site']) : '';
if (CaravelPay\Connector\Urls::address($wallet) === null || stripos($site, 'https://') !== 0) {
    fwrite(STDERR, "Usage: php connect-cli.php --wallet=0x… --site=https://your-site.example [--email=…] [--platform=…]\n");
    exit(2);
}
$input = new CaravelPay\Connector\ConnectInput($site, isset($options['email']) ? (string) $options['email'] : '', isset($options['platform']) ? (string) $options['platform'] : 'api', isset($options['version']) ? (string) $options['version'] : CARAVEL_CONNECTOR_VERSION);
$api = new CaravelPay\Connector\SdkApi(array('api_base' => CaravelPay\Connector\Urls::configuredApiBase(), 'user_agent' => 'CaravelPay-Connect/' . CARAVEL_CONNECTOR_VERSION));
try {
    $result = $api->connect($input->payload($wallet));
} catch (CaravelPay\Connector\ApiException $error) {
    fwrite(STDERR, 'Connecting failed: ' . $error->getMessage() . ' (' . $error->errorCode() . ")\n");
    exit(1);
}
echo 'Merchant ID: ', $result['merchant_id'], "\n";
echo 'Secret:      ', $result['secret'], "\n";
echo 'Status:      ', $result['status'], ', fee ', $result['fee_rate'], "\n";
echo "Paste both into the gateway settings now: the secret is not shown again.\n";
