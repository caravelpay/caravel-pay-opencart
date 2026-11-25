<?php
namespace CaravelPay\Connector;

/**
 * EventLog for platforms without a table of their own: the newest event ids in one KeyValue
 * entry. Concurrent webhooks can lose an id; that only means a retry is processed again, which
 * the order rules make harmless (they compare against the remembered status).
 */
final class KeyValueEventLog implements EventLog
{
    const KEY = 'events';
    const KEEP = 300;

    /** @var KeyValue */
    private $kv;

    /**
     * @param KeyValue $kv Storage.
     */
    public function __construct(KeyValue $kv)
    {
        $this->kv = $kv;
    }

    public function seen($eventId)
    {
        return in_array((string) $eventId, $this->ids(), true);
    }

    public function remember($eventId)
    {
        $ids = $this->ids();
        if (!in_array((string) $eventId, $ids, true)) {
            $ids[] = (string) $eventId;
        }
        $this->kv->set(self::KEY, (string) json_encode(array_slice($ids, -self::KEEP)));
    }

    /** @return string[] */
    private function ids()
    {
        $ids = json_decode($this->kv->get(self::KEY, '[]'), true);
        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : array();
    }
}
