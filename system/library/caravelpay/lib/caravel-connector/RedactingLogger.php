<?php
namespace CaravelPay\Connector;

/**
 * Wraps a platform logger: removes every known secret and any 64-hex run that looks like one
 * before a line is written. Transaction hashes (0x + 64 hex) are kept.
 */
final class RedactingLogger implements Logger
{
    /** @var Logger */
    private $inner;
    /** @var callable */
    private $secrets;

    /**
     * @param Logger   $inner   Platform logger.
     * @param callable $secrets Returns the secrets currently stored (string[]).
     */
    public function __construct(Logger $inner, $secrets)
    {
        $this->inner = $inner;
        $this->secrets = $secrets;
    }

    public function info($message)
    {
        $this->inner->info($this->redact($message));
    }

    public function warning($message)
    {
        $this->inner->warning($this->redact($message));
    }

    public function error($message)
    {
        $this->inner->error($this->redact($message));
    }

    /**
     * @param string $message Line.
     * @return string
     */
    public function redact($message)
    {
        $message = (string) $message;
        $secrets = call_user_func($this->secrets);
        foreach (is_array($secrets) ? $secrets : array() as $secret) {
            if (is_string($secret) && strlen($secret) >= 16) {
                $message = str_replace($secret, '[redacted]', $message);
            }
        }
        return (string) preg_replace('/(?<![0-9a-fA-Fx])[0-9a-f]{64}(?![0-9a-fA-F])/', '[redacted]', $message);
    }
}
