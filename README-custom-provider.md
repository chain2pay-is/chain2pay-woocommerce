# Chain2Pay — Custom Provider (WooCommerce)

A single, configurable payment tile that can route checkouts to **any**
Chain2Pay provider. Instead of installing a dedicated tile per provider, you
type the provider's slug and the plugin calls the Chain2Pay API with it.

## When to use it

- A provider you want isn't shipped as its own tile (e.g. `finchpay`,
  `kryptonim`, `transfi`).
- You want to switch the active provider without changing plugins.

If a provider already has its own dedicated tile (Swapped, Simplex, MoonPay,
…), you can keep using that — the Custom Provider tile is just a flexible
alternative that supports all of them.

## Setup

1. In WordPress, go to **WooCommerce → Settings → Payments**.
2. Enable **Chain2Pay (Custom Provider)** and click **Manage**.
3. Fill in the fields:
   - **Provider slug** — the provider to use, all lowercase. Examples:
     `swapped`, `simplex`, `transfi`, `finchpay`, `kryptonim`, `moonpay`,
     `banxa`, `guardarian`, `coinbase`, `revolut`, `binance`, `stripe`,
     `paypal`, `robinhood`, `transak`, `blockchain`, `cryptocom`, `ramp`,
     `topper`, `alchemy`, `mercuryo`, `paybis`, `multihosted`.
   - **Title** / **Description** — what the buyer sees at checkout.
   - **Icon URL** — optional logo.
4. The **API key** is the same one used by every Chain2Pay gateway
   (`c2p_live_...` or `c2p_sandbox_...`). It is read from your existing
   Chain2Pay configuration — no separate key needed.
5. Save.

## Notes

- The slug is always sent lowercase. An unknown slug returns a clean error
  from the API; a valid one starts the checkout immediately.
- Minimum amount, currency handling, callbacks and the 60% under-payment
  safeguard work exactly like the other Chain2Pay tiles.
- Transient connection blips are retried automatically before any error is
  shown to the buyer.
