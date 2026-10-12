# MasterPet for WooCommerce

Connects a WooCommerce store to [MasterPet](https://www.masterpet.app), the
management system for pet food businesses in Israel. Once connected, MasterPet
is the source of the store's products, and the store's orders are managed in
MasterPet.

תוסף וורדפרס שמחבר חנות WooCommerce ל-MasterPet. אחרי החיבור, המוצרים מגיעים
מ-MasterPet והזמנות האתר מנוהלות שם.

## Status

| Phase | What | State |
|---|---|---|
| A | Connection: one-time code, per-site secret, signed requests, status screen | in progress |
| B | Products from MasterPet | planned |
| C | Orders and card payment through MasterPet | planned |
| D | Status and credits back to the store | planned |

The full specification lives in the MasterPet repository (PRD #13.1).

## How the connection works

1. In MasterPet: Settings → Integrations → WooCommerce site → pick a branch →
   "Create connection code". The code looks like `T-7KQ2-RX9D-MVPA` and is valid
   for 15 minutes, once.
2. In WordPress: MasterPet → paste the code → Connect.
3. The plugin sends the code to MasterPet and receives the site's own secret.
   No MasterPet password is ever stored on the site.

The first letter of the code picks the server: `T` = test, `P` = production.

## Security

- Every request between the site and MasterPet is signed:
  `HMAC-SHA256(secret, METHOD \n route \n ts \n nonce \n sha256(body))`, with a
  300-second window and a single-use nonce.
- The secret is stored encrypted in the site's options (key derived from the
  site's WordPress salts) when OpenSSL is available.
- This repository holds no secret of any kind.
- `tests/signature-vector.php` checks the signature against the same literal
  vector MasterPet's own test uses.

Report a security issue privately to the MasterPet team, not in a public issue.

## Development

```
php -l masterpet-woocommerce.php
php tests/signature-vector.php
```

Requires WordPress 6.4+, WooCommerce 8.5+, PHP 7.4+. Declares compatibility with
WooCommerce High-Performance Order Storage and the block checkout.

## License

GPL-2.0-or-later. See `LICENSE`.
