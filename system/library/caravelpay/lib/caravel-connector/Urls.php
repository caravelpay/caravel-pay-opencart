<?php
namespace CaravelPay\Connector;

/**
 * URL helpers shared by every platform.
 */
final class Urls
{
    const DEFAULT_API_BASE = 'https://api.caravelpay.app';

    /**
     * @param string $host Host.
     * @return bool
     */
    public static function isLoopback($host)
    {
        return in_array(strtolower((string) $host), array('localhost', '127.0.0.1', '[::1]', '::1'), true);
    }

    /**
     * An API origin: https (http only on loopback, for local staging), no path, credentials, query or fragment.
     *
     * @param string $base Candidate.
     * @return bool
     */
    public static function validApiBase($base)
    {
        $parts = parse_url((string) $base);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            return false;
        }
        $scheme = strtolower(isset($parts['scheme']) ? $parts['scheme'] : '');
        return $scheme === 'https' || ($scheme === 'http' && self::isLoopback($parts['host']));
    }

    /**
     * The configured API origin without a trailing slash, or '' when unsafe.
     *
     * @param string $base Configured value ('' = default).
     * @return string
     */
    public static function apiBase($base)
    {
        $base = rtrim(trim((string) $base), '/');
        if ($base === '') {
            $base = self::DEFAULT_API_BASE;
        }
        return self::validApiBase($base) ? $base : '';
    }

    /**
     * The API origin from the CARAVELPAY_API_BASE environment variable or PHP constant (for
     * staging), otherwise the production default. '' when the configured value is unsafe.
     *
     * @return string
     */
    public static function configuredApiBase()
    {
        $value = getenv('CARAVELPAY_API_BASE');
        if (!is_string($value) || $value === '') {
            $value = defined('CARAVELPAY_API_BASE') ? (string) constant('CARAVELPAY_API_BASE') : '';
        }
        return self::apiBase($value);
    }

    /**
     * The path plus query string the API signs for a URL ("https://a.example/x?y=1" -> "/x?y=1").
     *
     * @param string $url Absolute URL.
     * @return string|null
     */
    public static function signedPath($url)
    {
        if (class_exists('CaravelPay\\Signature')) {
            // The SDK applies the same WHATWG normalisation as the API when it signs.
            try {
                return \CaravelPay\Signature::signedPath((string) $url);
            } catch (\InvalidArgumentException $error) {
                return null;
            }
        }
        $parts = parse_url((string) $url);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $path = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
        if (isset($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }
        return $path;
    }

    /**
     * Unique signed paths for several URLs, in order.
     *
     * @param string[] $urls URLs.
     * @return string[]
     */
    public static function signedPaths(array $urls)
    {
        $paths = array();
        foreach ($urls as $url) {
            $path = self::signedPath($url);
            if ($path !== null && !in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }
        return $paths;
    }

    /**
     * An absolute https URL (http on loopback), no credentials or fragment.
     *
     * @param string $url Candidate.
     * @return bool
     */
    public static function validCallbackUrl($url)
    {
        $parts = parse_url((string) $url);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }
        $scheme = strtolower(isset($parts['scheme']) ? $parts['scheme'] : '');
        return $scheme === 'https' || ($scheme === 'http' && self::isLoopback($parts['host']));
    }

    /**
     * The site origin sent as site_url ("https://shop.example/store/" -> "https://shop.example/store").
     *
     * @param string $url Site URL.
     * @return string
     */
    public static function siteUrl($url)
    {
        $url = trim((string) $url);
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return rtrim($url, '/');
        }
        $scheme = strtolower(isset($parts['scheme']) ? $parts['scheme'] : 'https');
        $out = $scheme . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
        $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
        return $out . $path;
    }

    /**
     * Block-explorer link for a Polygon transaction ('' when malformed).
     *
     * @param string $txHash Hash.
     * @param string $prefix Explorer prefix.
     * @return string
     */
    public static function explorer($txHash, $prefix = 'https://polygonscan.com/tx/')
    {
        return is_string($txHash) && preg_match('/^0x[0-9a-fA-F]{64}$/D', $txHash) ? $prefix . strtolower($txHash) : '';
    }

    /**
     * A Polygon/EVM address as entered, or null when malformed or zero.
     *
     * @param mixed $value Candidate.
     * @return string|null
     */
    public static function address($value)
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return preg_match('/^0x[a-fA-F0-9]{40}$/D', $value) && !preg_match('/^0x0{40}$/iD', $value) ? $value : null;
    }
}
