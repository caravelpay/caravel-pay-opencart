<?php
namespace CaravelPay\Connector;

/**
 * Store connection: connect with POST /v1/connect, keep the credentials it returns, and track
 * the merchant status. The same rules as the WooCommerce plugin:
 *
 * - Every connect creates a NEW merchant, so connect only when there are no credentials, when
 *   the payout wallet changed, or when the merchant explicitly asks to reconnect.
 * - Replaced credentials are kept for 7 days, so webhooks and status checks for checkouts
 *   created under them still verify.
 * - Secrets are stored through KeyValue::set(..., true) and never logged or shown.
 */
final class Connection
{
    const KEY_MERCHANT = 'merchant_id';
    const KEY_SECRET = 'secret';
    const KEY_RETIRED = 'retired_credentials';
    const KEY_CONNECTION = 'connection';
    const KEY_STATE = 'merchant_state';
    const KEY_ERROR = 'connect_error';

    /** How long replaced credentials still verify webhooks and re-fetch their checkouts, seconds. */
    const RETIRED_TTL = 604800;
    /** Merchant status freshness for checkout availability, seconds. */
    const STATE_TTL = 600;
    /** Back-off after a failed status refresh, seconds. */
    const STATE_RETRY = 120;

    const STATUSES = array('active', 'pending_review', 'paused');

    /** @var KeyValue */
    private $kv;
    /** @var Api */
    private $api;
    /** @var Logger */
    private $log;
    /** @var callable|null */
    private $clock;

    /**
     * @param KeyValue      $kv    Storage.
     * @param Api           $api   API.
     * @param Logger|null   $log   Logger.
     * @param callable|null $clock Returns unix seconds (tests).
     */
    public function __construct(KeyValue $kv, Api $api, ?Logger $log = null, $clock = null)
    {
        $this->kv = $kv;
        $this->api = $api;
        $self = $this;
        $this->log = new RedactingLogger($log ?: new NullLogger(), function () use ($self) {
            return $self->knownSecrets();
        });
        $this->clock = $clock;
    }

    /** @return int */
    public function now()
    {
        return $this->clock ? (int) call_user_func($this->clock) : time();
    }

    /** @return Logger */
    public function logger()
    {
        return $this->log;
    }

    /** @return Api */
    public function api()
    {
        return $this->api;
    }

    /** @return string '' when not connected */
    public function merchantId()
    {
        $value = $this->kv->get(self::KEY_MERCHANT, '');
        return Credentials::validMerchantId($value) ? $value : '';
    }

    /** @return string '' when not connected */
    private function secret()
    {
        $value = $this->kv->get(self::KEY_SECRET, '');
        return Credentials::validSecret($value) ? $value : '';
    }

    /** @return bool */
    public function isConnected()
    {
        return $this->merchantId() !== '' && $this->secret() !== '';
    }

    /** @return Credentials|null The current credentials. */
    public function credentials()
    {
        return Credentials::tryCreate($this->merchantId(), $this->secret());
    }

    /**
     * Credentials for a merchant id: the current ones, or replaced ones within their 7 days.
     *
     * @param string $merchantId Merchant id.
     * @return Credentials|null
     */
    public function credentialsFor($merchantId)
    {
        if (!Credentials::validMerchantId($merchantId)) {
            return null;
        }
        $current = $this->merchantId();
        if ($current !== '' && hash_equals($current, $merchantId)) {
            return $this->credentials();
        }
        $retired = $this->retired();
        return isset($retired[$merchantId]) ? Credentials::tryCreate($merchantId, $retired[$merchantId]['secret']) : null;
    }

    /**
     * Replaced credentials still within their 7 days: merchant id => {secret, until}.
     *
     * @return array
     */
    private function retired()
    {
        $retired = $this->json(self::KEY_RETIRED);
        $valid = array();
        foreach ($retired as $merchantId => $entry) {
            if (Credentials::validMerchantId($merchantId) && is_array($entry) && isset($entry['secret'], $entry['until'])
                && Credentials::validSecret($entry['secret']) && (int) $entry['until'] > $this->now()) {
                $valid[$merchantId] = array('secret' => $entry['secret'], 'until' => (int) $entry['until']);
            }
        }
        return $valid;
    }

    /**
     * Every stored secret (for log redaction).
     *
     * @return string[]
     */
    public function knownSecrets()
    {
        $secrets = array();
        $current = $this->kv->get(self::KEY_SECRET, '');
        if ($current !== '') {
            $secrets[] = $current;
        }
        foreach ($this->json(self::KEY_RETIRED) as $entry) {
            if (is_array($entry) && isset($entry['secret']) && is_string($entry['secret']) && $entry['secret'] !== '') {
                $secrets[] = $entry['secret'];
            }
        }
        return $secrets;
    }

    /** @return array {merchant_id, payout_address, fee_rate, site_url, connected_at} */
    public function connection()
    {
        return $this->json(self::KEY_CONNECTION);
    }

    /** @return string The payout wallet the current credentials were issued for. */
    public function connectedWallet()
    {
        $connection = $this->connection();
        return isset($connection['payout_address']) && is_string($connection['payout_address']) ? $connection['payout_address'] : '';
    }

    /** @return array Last known merchant status. */
    public function state()
    {
        return $this->json(self::KEY_STATE);
    }

    /** @return array|null Last connect failure {code, message, param, payout_address, at}. */
    public function lastError()
    {
        $error = $this->json(self::KEY_ERROR);
        return !empty($error['code']) ? $error : null;
    }

    /**
     * After the settings are saved: connect when there are no credentials, the payout wallet
     * changed, or a reconnect was requested. Never otherwise: each connect creates a new merchant.
     *
     * @param string       $wallet  Saved payout wallet.
     * @param bool         $force   Reconnect requested.
     * @param ConnectInput $input   Site and platform details.
     * @return array{result:string,changed?:bool,first?:bool,status?:string,error?:ApiException}
     */
    public function afterSave($wallet, $force, ConnectInput $input)
    {
        $wallet = Urls::address($wallet);
        if ($wallet === null) {
            return array('result' => 'skipped');
        }
        $wasConnected = $this->isConnected();
        $changed = $wasConnected && strcasecmp($this->connectedWallet(), $wallet) !== 0;
        if ($wasConnected && !$changed && !$force) {
            return array('result' => 'unchanged');
        }
        try {
            $result = $this->connect($wallet, $input);
        } catch (ApiException $error) {
            return array('result' => 'failed', 'error' => $error, 'changed' => $changed, 'first' => !$wasConnected);
        }
        return array('result' => 'connected', 'changed' => $changed, 'first' => !$wasConnected, 'status' => $result['status']);
    }

    /**
     * POST /v1/connect. On success the new credentials become current and the replaced ones are kept 7 days.
     *
     * @param string       $wallet Payout wallet.
     * @param ConnectInput $input  Site and platform details.
     * @return array {merchant_id, status, fee_rate}
     * @throws ApiException
     */
    public function connect($wallet, ConnectInput $input)
    {
        $wallet = Urls::address($wallet);
        if ($wallet === null) {
            throw $this->fail(new ApiException(0, 'invalid_payout_address', 'Enter a Polygon address that starts with 0x.', 'payout_address'), (string) $wallet);
        }
        $payload = $input->payload($wallet);
        $this->log->info('Connecting to Caravel Pay (' . $payload['site_url'] . ', payout wallet ' . $wallet . ').');
        try {
            $response = $this->api->connect($payload);
        } catch (ApiException $error) {
            throw $this->fail($error, $wallet);
        }
        $merchantId = isset($response['merchant_id']) && Credentials::validMerchantId($response['merchant_id']) ? $response['merchant_id'] : null;
        $secret = isset($response['secret']) && Credentials::validSecret($response['secret']) ? $response['secret'] : null;
        $status = isset($response['status']) && in_array($response['status'], self::STATUSES, true) ? $response['status'] : null;
        $feeRate = isset($response['fee_rate']) && is_string($response['fee_rate']) && preg_match('/^[0-9]{1,3}(?:\.[0-9]{1,4})?%$/D', $response['fee_rate']) ? $response['fee_rate'] : '2%';
        if ($merchantId === null || $secret === null || $status === null) {
            throw $this->fail(new ApiException(0, 'invalid_response', 'Caravel Pay returned an unexpected answer. Please try again.'), $wallet);
        }
        // Keep the replaced credentials: checkouts created under them still send webhooks signed with them.
        $oldMerchant = $this->merchantId();
        $oldSecret = $this->secret();
        $retired = $this->retired();
        if ($oldMerchant !== '' && $oldSecret !== '' && $oldMerchant !== $merchantId) {
            $retired[$oldMerchant] = array('secret' => $oldSecret, 'until' => $this->now() + self::RETIRED_TTL);
        }
        unset($retired[$merchantId]);
        $this->kv->set(self::KEY_RETIRED, $this->encode($retired), true);
        $this->kv->set(self::KEY_SECRET, $secret, true);
        $this->kv->set(self::KEY_MERCHANT, $merchantId);
        $this->kv->set(self::KEY_CONNECTION, $this->encode(array(
            'merchant_id' => $merchantId,
            'payout_address' => $wallet,
            'fee_rate' => $feeRate,
            'site_url' => $payload['site_url'],
            'connected_at' => $this->now(),
        )));
        $this->kv->set(self::KEY_STATE, $this->encode(array(
            'status' => $status,
            'payout_address' => $wallet,
            'paused_reason' => '',
            'checked_at' => $this->now(),
            'error' => null,
        )));
        $this->kv->delete(self::KEY_ERROR);
        $this->log->info('Connected to Caravel Pay as ' . $merchantId . ' (status ' . $status . ')'
            . ($oldMerchant !== '' && $oldMerchant !== $merchantId ? '; ' . $oldMerchant . ' stays valid for open checkouts for 7 days.' : '.'));
        return array('merchant_id' => $merchantId, 'status' => $status, 'fee_rate' => $feeRate);
    }

    /**
     * Record a failed connect for the status line.
     *
     * @param ApiException $error  Error.
     * @param string       $wallet Wallet being connected.
     * @return ApiException
     */
    private function fail(ApiException $error, $wallet)
    {
        $this->kv->set(self::KEY_ERROR, $this->encode(array(
            'code' => $error->errorCode(),
            'message' => $error->getMessage(),
            'param' => $error->param(),
            'payout_address' => $wallet,
            'at' => $this->now(),
        )));
        $this->log->warning('Connecting to Caravel Pay failed: ' . $error->errorCode() . ' (HTTP ' . $error->httpStatus() . ').');
        return $error;
    }

    /**
     * Use credentials the merchant pasted (platforms without a save hook). Replaced credentials
     * are kept 7 days like a reconnect. Returns false when the values are malformed.
     *
     * @param string $merchantId Merchant id.
     * @param string $secret     Secret.
     * @return bool
     */
    public function useCredentials($merchantId, $secret)
    {
        $credentials = Credentials::tryCreate(trim((string) $merchantId), trim((string) $secret));
        if ($credentials === null) {
            return false;
        }
        $oldMerchant = $this->merchantId();
        $oldSecret = $this->secret();
        if ($oldMerchant === $credentials->merchantId() && $oldSecret === $credentials->secret()) {
            return true;
        }
        $retired = $this->retired();
        if ($oldMerchant !== '' && $oldSecret !== '' && $oldMerchant !== $credentials->merchantId()) {
            $retired[$oldMerchant] = array('secret' => $oldSecret, 'until' => $this->now() + self::RETIRED_TTL);
        }
        unset($retired[$credentials->merchantId()]);
        $this->kv->set(self::KEY_RETIRED, $this->encode($retired), true);
        $this->kv->set(self::KEY_SECRET, $credentials->secret(), true);
        $this->kv->set(self::KEY_MERCHANT, $credentials->merchantId());
        $this->kv->set(self::KEY_STATE, $this->encode(array('status' => '', 'checked_at' => 0, 'error' => null)));
        return true;
    }

    /**
     * Signed GET /v1/merchant for the current merchant; updates the stored status.
     *
     * @return array State.
     */
    public function refreshState()
    {
        $state = $this->state();
        $credentials = $this->credentials();
        if ($credentials === null) {
            return $state;
        }
        $state['checked_at'] = $this->now();
        try {
            $response = $this->api->getMerchant($credentials);
        } catch (ApiException $error) {
            $state['error'] = array('code' => $error->errorCode(), 'message' => $error->getMessage());
            if (in_array($error->errorCode(), array('unknown_merchant', 'invalid_signature'), true)) {
                // The credentials were rejected: no checkouts until the store reconnects.
                $state['status'] = 'not_connected';
            }
            $this->kv->set(self::KEY_STATE, $this->encode($state));
            return $state;
        }
        $status = isset($response['status']) && in_array($response['status'], self::STATUSES, true) ? $response['status'] : null;
        if ($status === null || !isset($response['id']) || $response['id'] !== $credentials->merchantId()) {
            $state['error'] = array('code' => 'invalid_response', 'message' => 'Caravel Pay returned an unexpected answer.');
            $this->kv->set(self::KEY_STATE, $this->encode($state));
            return $state;
        }
        $reason = '';
        foreach (array('paused_reason', 'reason') as $key) {
            if (isset($response[$key]) && is_string($response[$key]) && trim($response[$key]) !== '') {
                $reason = trim((string) preg_replace('/\s+/', ' ', strip_tags($response[$key])));
                break;
            }
        }
        $state = array(
            'status' => $status,
            'payout_address' => Urls::address(isset($response['payout_address']) ? $response['payout_address'] : null) !== null ? $response['payout_address'] : '',
            'paused_reason' => substr($reason, 0, 300),
            'fee_rate' => isset($response['fee_rate']) && is_string($response['fee_rate']) ? substr($response['fee_rate'], 0, 10) : '',
            'checked_at' => $this->now(),
            'error' => null,
        );
        $this->kv->set(self::KEY_STATE, $this->encode($state));
        return $state;
    }

    /**
     * A refusal on checkout creation tells us the current merchant's status changed.
     *
     * @param string $code API error code.
     */
    public function noteRefusal($code)
    {
        $map = array('merchant_paused' => 'paused', 'merchant_pending_review' => 'pending_review', 'unknown_merchant' => 'not_connected', 'invalid_signature' => 'not_connected');
        if (!isset($map[$code])) {
            return;
        }
        $state = $this->state();
        $state['status'] = $map[$code];
        $state['checked_at'] = $this->now();
        $this->kv->set(self::KEY_STATE, $this->encode($state));
    }

    /**
     * True when checkouts can be created: connected and the merchant is active. Refreshes a stale
     * status at most every few minutes (STATE_TTL, STATE_RETRY after a failure).
     *
     * @return bool
     */
    public function checkoutReady()
    {
        if (!$this->isConnected()) {
            return false;
        }
        $state = $this->state();
        $checked = isset($state['checked_at']) ? (int) $state['checked_at'] : 0;
        $failed = !empty($state['error']);
        if ($this->now() - $checked > ($failed ? self::STATE_RETRY : self::STATE_TTL)) {
            // Mark as checked first so concurrent requests don't all refresh.
            $state['checked_at'] = $this->now();
            $this->kv->set(self::KEY_STATE, $this->encode($state));
            $state = $this->refreshState();
        }
        return isset($state['status']) && $state['status'] === 'active';
    }

    /**
     * The settings-page status line as message keys (Messages) and their parameters.
     *
     * @return array{state:string,label:string,detail:array,hint:array} detail/hint = [key, params]
     */
    public function statusView()
    {
        $error = $this->lastError();
        if (!$this->isConnected()) {
            if ($error === null) {
                return array('state' => 'not_connected', 'label' => 'status.not_connected', 'detail' => array('status.enter_wallet', array()), 'hint' => array('', array()));
            }
            return array('state' => 'not_connected', 'label' => 'status.not_connected', 'detail' => array('status.error_detail', array($this->errorText($error), $error['code'])), 'hint' => array(self::hintKey($error['code'], isset($error['param']) ? $error['param'] : ''), array()));
        }
        $state = $this->state();
        $status = isset($state['status']) ? $state['status'] : '';
        $wallet = isset($state['payout_address']) && $state['payout_address'] !== '' ? $state['payout_address'] : $this->connectedWallet();
        $connection = $this->connection();
        $fee = isset($connection['fee_rate']) ? $connection['fee_rate'] : '2%';
        if ($status === 'not_connected') {
            $code = isset($state['error']['code']) ? $state['error']['code'] : 'unknown_merchant';
            $message = isset($state['error']['message']) ? (string) $state['error']['message'] : '';
            return array('state' => 'not_connected', 'label' => 'status.not_connected', 'detail' => array('status.error_detail', array($message, $code)), 'hint' => array(self::hintKey($code), array()));
        }
        if ($status === 'paused') {
            $reason = isset($state['paused_reason']) ? (string) $state['paused_reason'] : '';
            return array('state' => 'paused', 'label' => 'status.paused', 'detail' => $reason !== '' ? array('status.paused_detail', array($reason)) : array('status.paused_no_reason', array()), 'hint' => array('status.paused_hint', array()));
        }
        if ($status === 'pending_review') {
            return array('state' => 'pending_review', 'label' => 'status.pending_review', 'detail' => array('status.pending_review_detail', array()), 'hint' => array('', array()));
        }
        $detail = $wallet !== '' ? array('status.connected_detail', array($wallet, $fee, $this->merchantId())) : array('status.connected_id', array($this->merchantId(), $fee));
        $hint = array('', array());
        $connectedAt = isset($connection['connected_at']) ? (int) $connection['connected_at'] : 0;
        if ($error !== null && isset($error['payout_address']) && $this->connectedWallet() !== '' && strcasecmp((string) $error['payout_address'], $this->connectedWallet()) !== 0) {
            $hint = array('status.new_wallet_failed', array($error['payout_address'], $this->errorText($error) . ' (' . $error['code'] . ')', self::hintKey($error['code'], isset($error['param']) ? $error['param'] : '')));
        } elseif ($error !== null && isset($error['at']) && (int) $error['at'] >= $connectedAt) {
            $hint = array('status.reconnect_failed', array($this->errorText($error) . ' (' . $error['code'] . ')', self::hintKey($error['code'], isset($error['param']) ? $error['param'] : '')));
        } elseif (!empty($state['error']['code'])) {
            $hint = array('status.stale', array());
        }
        return array('state' => 'connected', 'label' => 'status.connected', 'detail' => $detail, 'hint' => $hint);
    }

    /**
     * @param array $error Stored error.
     * @return string
     */
    private function errorText(array $error)
    {
        return isset($error['message']) ? rtrim((string) $error['message'], '. ') : '';
    }

    /**
     * Message key for what the merchant can do about an error.
     *
     * @param string $code  API error code.
     * @param string $param Field the error is about.
     * @return string
     */
    public static function hintKey($code, $param = '')
    {
        switch ($code) {
            case 'invalid_url':
                return 'hint.invalid_url';
            case 'rate_limited':
                return 'hint.rate_limited';
            case 'network':
            case 'server_error':
            case 'service_unavailable':
                return 'hint.unreachable';
            case 'unknown_merchant':
            case 'invalid_signature':
                return 'hint.credentials_rejected';
            case 'timestamp_expired':
                return 'hint.clock';
            case 'invalid_api_base':
                return 'hint.api_base';
            case 'limit_exceeded':
                return 'hint.wallet_limit';
            default:
                return ($param === 'payout_address' || $code === 'invalid_payout_address') ? 'hint.wallet' : 'hint.generic';
        }
    }

    /**
     * Remove every stored credential and status (uninstall).
     */
    public function forget()
    {
        foreach (array(self::KEY_MERCHANT, self::KEY_SECRET, self::KEY_RETIRED, self::KEY_CONNECTION, self::KEY_STATE, self::KEY_ERROR) as $key) {
            $this->kv->delete($key);
        }
    }

    /**
     * @param string $key Key.
     * @return array
     */
    private function json($key)
    {
        $value = json_decode($this->kv->get($key, ''), true);
        return is_array($value) ? $value : array();
    }

    /**
     * @param array $value Value.
     * @return string
     */
    private function encode(array $value)
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
