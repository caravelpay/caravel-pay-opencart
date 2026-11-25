<?php
/**
 * Caravel Pay connector core: autoloader. No Composer needed.
 *
 * Loads the bundled Caravel Pay PHP SDK first (vendored next to this folder as ../caravel-php/
 * by integrations/_tools/vendor-sdk.sh), then registers the CaravelPay\Connector\ classes here.
 * Safe to include more than once and next to another copy: the first copy to load a class wins.
 */

if (!defined('CARAVEL_CONNECTOR_VERSION')) {
    define('CARAVEL_CONNECTOR_VERSION', '1.2.1');
}

$caravelSdkAutoload = dirname(__DIR__) . '/caravel-php/autoload.php';
if (is_file($caravelSdkAutoload)) {
    require_once $caravelSdkAutoload;
}
unset($caravelSdkAutoload);

spl_autoload_register(static function ($class) {
    $prefix = 'CaravelPay\\Connector\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if (!preg_match('/^[A-Za-z0-9]+$/D', $relative)) {
        return;
    }
    $file = __DIR__ . '/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
