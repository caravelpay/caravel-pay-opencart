# Caravel Pay for OpenCart 4

- Docs: <https://caravelpay.app/docs/integrations#opencart>
- Download and checksums: <https://caravelpay.app/plugins/#opencart>
- Status: beta. It passes our tests against a stand-in of the platform and has not yet run on a production install. Tell us if anything looks off.
- Questions: [hello@caravelpay.app](mailto:hello@caravelpay.app)

Accept card, Apple Pay and Google Pay in an OpenCart store and get paid in USDC on Polygon (or in the
token the buyer paid with), minus the 2% Caravel fee. No account, dashboard or API key: paste your payout
wallet, save, and the extension connects the store.

| | |
|---|---|
| OpenCart | 4.0.2.0 or later (OpenCart 3 is not supported: its extension format and payment API differ) |
| PHP | 8.0 or later (OpenCart 4's own minimum), with `curl` and `json` |
| Store currency | USD, and EUR, GBP, CAD, AUD, NZD, SEK, NOK, DKK, CHF, PLN, CZK, HUF, RON, HKD, SGD, MXN, BRL, ZAR, ILS, INR, THB, MYR, PHP, TRY, JPY and KRW, converted to US dollars at checkout (see *Other currencies*). Caravel Pay doesn't show for any other customer currency. |
| Payout | Your own Polygon wallet. USDC for card payments; USDC.e or POL when the buyer paid in those. Never swapped. |

## Install

1. Download `caravelpay.ocmod.zip`. **Keep the file name**: OpenCart 4 names the extension after it.
2. In the admin go to **Extensions › Installer**, click **Upload** and choose the zip, then click the
   green **Install** button next to Caravel Pay in the list below.
3. Go to **Extensions › Extensions**, choose **Payments**, find Caravel Pay and click **Install** (+),
   then **Edit** (pencil).
4. Paste your **Payout wallet** (a Polygon address you control, starting with `0x`), switch **Status**
   on and click **Save**. The Connection panel shows **Connected** with your merchant id.
5. Place a test order with a cheap product and pay it on the hosted checkout.

## Settings

| Setting | What it does |
|---|---|
| Payout wallet | Every payout goes here. Saving a new wallet connects again: new orders pay out to it, orders already started finish on the old one (the old credentials stay valid 7 days for their webhooks). **Save and reconnect** gets new credentials for the same wallet. |
| Title | The payment method name at checkout. Default: *Card, Apple Pay or Google Pay*. |
| Who pays the fees | *Seller pays everything* (default): your buyer pays the order total and both fees come off what you receive. *Split: buyer pays the card fee*: the card provider adds its fee to your buyer's total and you pay the 2% Caravel fee. *Buyer pays everything*: the 2% Caravel fee is added to your buyer's total as well, so you receive the full order total. Buyers see a line about it on the confirm step and the full amount on the checkout before they pay. Card surcharges are restricted in some countries (for example on consumer cards in the EU and the UK): check your local rules first. |
| Awaiting payment status | Set when the buyer is sent to the checkout. Default *Pending*. |
| Paid status | Set on a confirmed payment. Default *Processing*. |
| Underpaid / review status | Default *On Hold* (created when the extension is installed). |
| Expired status | Default *Canceled*. |
| Geo zone, Status, Sort order | As for any OpenCart payment method. |

Staging only: set `CARAVELPAY_API_BASE` (an `https://` origin, or `http://127.0.0.1:…`) as an
environment variable or PHP constant to talk to another API host.

## How orders update

On the confirm step the buyer clicks **Pay now**: the order is added with the awaiting status and the
buyer goes to the hosted checkout. The extension then waits for a signed webhook, verifies it,
re-reads the checkout with the signed API (`GET /v1/checkouts/{id}`) and only then adds an order
history entry:

| Checkout status | OpenCart order |
|---|---|
| `awaiting_payment`, `confirming` | Pending (unchanged; a history comment when a payment is detected) |
| `paid` | **Processing**, customer notified, the comment shows the payout amount and transaction link |
| `underpaid` | **On Hold**, comment with how much arrived |
| `review` | **On Hold**, comment |
| `expired` | **Canceled** if still pending. An order on hold stays on hold. |

A payment sent just before expiry can confirm after it; the canceled order then moves to Processing
with a comment. Orders that moved on (shipped, complete, refunded…) are never changed, only commented.

Each checkout's `order_id` is the OpenCart order id plus a random suffix the extension keeps per
order, for example `1042-k7pq2x`; a retry after expiry ends in `-2`.

The webhook URL is `https://<your store>/index.php?route=extension/caravelpay/payment/caravelpay.callback`.
It must accept POST requests from outside (no maintenance mode, firewall or CDN block).

## Other currencies

Orders in a supported currency other than US dollars go to Caravel Pay as they are. The total is
converted to US dollars once, when the checkout is created, and that amount is fixed for the checkout:
your buyer is charged in US dollars and you are paid in USDC. Buyers read it before they leave your
store ("You'll be charged in US dollars: about $48.60"), and the checkout shows both prices before
they pay.

- The estimate uses today's rate from Caravel Pay, kept for ten minutes.
- The order is marked paid from the checkout's status, for its own total in its own currency; the note on
  the order names both figures. Amounts in different currencies are never compared.
- **Exchange-rate buffer (%)** (0 to 5, default 0) adds a margin to the converted amount, to cover
  exchange-rate movement until you convert your USDC. Leave it at 0 to charge the plain converted amount.
- When no confirmed exchange rate is available, the checkout isn't created and the buyer is asked to try
  again shortly or choose another payment method.

## Switching from PayGate

- Install Caravel Pay next to the PayGate extension (`paygatedotto`); both can be enabled while you
  switch, and PayGate orders keep being updated by PayGate.
- Copy your payout wallet into **Payout wallet** (a Polygon address you control).
- PayGate's *paid-order status* maps to **Paid status**. PayGate's *Fees Tolerance* has no equivalent:
  the amount is fixed on Caravel Pay's side and a short payment becomes **underpaid** (On Hold) instead
  of Failed. PayGate's *API Domain* has no equivalent.
- When you're happy, switch the PayGate payment method off.

## Troubleshooting

- **Not connected**: the panel says why (wallet refused, server can't reach `api.caravelpay.app`,
  too many attempts). Fix it and click **Save and reconnect**.
- **An order stays Pending after paying**: the webhook isn't reaching the store; see
  `storage/logs/caravelpay.log` (**System › Maintenance › Error logs** shows the main log only).
  Webhooks are retried for 24 hours.
- **Caravel Pay doesn't show at checkout**: Status on, Connected, a supported customer currency, geo zone.

Support: hello@caravelpay.app with your store URL and the order id.

## Download

- [Latest release](https://github.com/caravelpay/caravel-pay-opencart/releases/latest): `caravelpay.ocmod.zip`, with `SHA256SUMS`.
- The same file is at <https://caravelpay.app/plugins/#opencart>, and its checksum is in <https://caravelpay.app/download/SHA256SUMS>.

## Docs and support

- Guide: <https://caravelpay.app/docs/integrations#opencart>
- API reference: <https://caravelpay.app/docs/>
- Questions and bug reports: [hello@caravelpay.app](mailto:hello@caravelpay.app), or open an issue here.

## Licence

MIT. See `LICENSE`.
