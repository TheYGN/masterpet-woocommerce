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
| A | Connection: one-time code, per-site secret, signed requests, status screen | done (0.1.x) |
| B | Products from MasterPet: first link by SKU, signed doorbell, pull, locked fields | in progress (0.2.0) |
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

## How products sync (0.2.0)

1. Right after connecting, the plugin sends its catalog (simple products, and
   variable products with their variations) to MasterPet. MasterPet links each
   item by its SKU only (never the barcode). Items without a matching SKU stay
   as they are and show in MasterPet's "not linked" list. Nothing is deleted.
2. When something changes in MasterPet (name, description, image, SKU, regular
   price, stock of the connected branch, status), MasterPet's clock rings
   `masterpet/v1/doorbell` on the site. The plugin then pulls the changes in
   batches of 50 through Action Scheduler and writes them with the WooCommerce
   CRUD classes, then confirms each one.
3. Products that exist only in MasterPet (0.3.0): nothing is created until the
   owner presses "upload products to the site" in MasterPet. From then on every
   active MasterPet product the owner did not turn off is created here (as a
   draft skeleton with its variations and SKUs), linked, and filled by the
   regular pull. A product turned off in MasterPet becomes a draft here.
   Categories, tags and the sale price (fixed or scheduled) come from MasterPet
   too (0.4.0); a product with one variant is created as a simple product.
   Products created before 0.4.0 with a single variation are turned into
   simple products in place, once, and linked again by SKU (0.5.0).
   A variant's own image in MasterPet goes to its variation here; without
   one the variation shows the product image (0.6.0).
   A variant added in MasterPet to a product already on the site becomes a
   new variation here, linked by SKU (0.8.0).
   A simple product created here whose MasterPet product gets a second
   variant becomes a variable product in place (0.9.0).
   Site images (0.10.0): when MasterPet has no image for a linked product and
   the site has its own, the plugin sends the image file to MasterPet once.
   MasterPet turns it into WebP and from then on its copy is the one shown here.
   MasterPet never fetches a URL from the site. The link report also carries a
   simple product's regular price and stock, so MasterPet can add a product that exists
   only here ("הוסף למערכת").
4. On linked products those fields are locked: greyed in the editor and put
   back on save (admin form, REST API, any other save). Sale price, gallery,
   short description and SEO stay open. Disconnecting releases the lock.
5. No new products here (0.11.0): while connected, the "Add new" menu item
   and button are gone, the add-new screen points to MasterPet, and the REST
   API, "Duplicate" and the CSV importer refuse to create a product. Products
   are added in MasterPet and come here by themselves. Disconnecting releases it.
6. Nightly check (0.12.0): once a day (03:00 site time) the plugin sends
   MasterPet the SKU and regular price of every linked item (stock from stage C on); MasterPet
   sends again every product that differs. Nothing is written here by the check.
   "בדוק התאמה עכשיו" on the MasterPet screen (0.12.1) runs it at once.

Before MasterPet rings a site for the first time it sends a signed ping and
expects an answer signed with the site's secret, so it only ever rings the real
site. It refuses private and internal addresses and never follows redirects.

## The MasterPet screen (0.7.1)

The plugin's screen in WordPress uses MasterPet's own look (the masterpet repo,
`designs/DESIGN-SYSTEM.md`): the brand green, Heebo, rounded cards, pill
buttons. The styles live in `assets/admin.css`, scoped under `.mp` and loaded
on this screen only, with Heebo from Google Fonts. Icons are inline Material
Symbols, so nothing else loads.

## Updates (0.7.0)

The plugin updates itself from this repository's GitHub releases, like any
plugin from wordpress.org: "update available" on the plugins screen, the update
button, and automatic updates (on by default; the owner can turn them off).

1. Merging a change that raises the version (the `Version:` header and
   `MASTERPET_WC_VERSION`, kept equal by the checks) publishes it as a GitHub
   **pre-release** with `masterpet-woocommerce.zip` (`.github/workflows/release.yml`).
2. Only sites marked **test site** (MasterPet screen → Updates) get
   pre-releases.
3. After a few days on the test site, run **promote** from the Actions tab
   (`.github/workflows/promote.yml`). The release becomes a regular one and
   every site gets it.

Besides WordPress's own automatic updates, the plugin installs a newer
release by itself twice a day (0.8.1), the same way WordPress does in the
background. Some hosts (Hostinger, for one) hide WordPress's switch and run
updates their own way; this does not depend on them. Where the switch is shown
and the owner turned it off for this plugin, the plugin leaves it.

A site asks GitHub at most every 6 hours, without a token. Packages are taken
only from this repository's release downloads.

## Security

- Every request between the site and MasterPet is signed:
  `HMAC-SHA256(secret, METHOD \n route \n site_id \n ts \n nonce \n sha256(body))`,
  with a 300-second window and a single-use nonce, in both directions. The
  site's answers to the doorbell and the ping are signed too.
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
