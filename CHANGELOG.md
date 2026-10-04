# Değişiklik günlüğü / Changelog

Bu eklentinin sürümleri. API'nin kendi değişiklikleri:
https://rewloy.com/gelistiriciler/degisiklikler

This plugin's releases. The API's own changes are listed at the link above.
Every decision and its reason: [docs/DECISIONS.md](docs/DECISIONS.md).

## 0.2.1 (4 Oct 2026)

- WordPress Plugin Check: request input is unslashed and sanitised in one step, an exception's text is marked as escaped where it is shown (Admin and the order notes escape it on output), the short description fits 150 characters, and the earlier-invitation lookup skips the order itself in its loop instead of with `exclude`, and the bundled Turkish translation keeps its `load_plugin_textdomain` with the reason. No behaviour changes.

## 0.2.0 (4 Oct 2026)

Rewloy'un o gün yayına aldığı platform özelliklerini kullanır (Rewloy ADR 174).

Uses what the Rewloy platform went live with that day (Rewloy ADR 174).

- **Connect with a code (D27).** The way in is a one-time code made in the Rewloy panel
  (E-ticaret › Mağaza bağla › WooCommerce › "Rewloy eklentisiyle"). The plugin spends it
  with `POST /v1/shops/connect`, makes the WooCommerce webhook with the returned secret,
  and keeps the returned API key, which is bound to that one link: no API key of your
  own, no strong key on the shop. The key is stored masked, not autoloaded, never shown
  back; the code is kept nowhere. A refused or unusable answer, or a webhook that cannot be
  made, takes the link back with the new key; with no clear answer the code is never sent
  twice. Disconnecting drops the key (Rewloy revokes it with the link).
- **The API-key path is the advanced option (D28)**, inside the screen, with its reason
  (nobody can make a code: scripts, staging, wp-config.php). It is checked with
  `GET /v1/me`, needs the E-ticaret permissions only (no `apikeys.manage`), names a missing
  permission, refuses a key bound to another link, and says when a key can do more than the
  plugin needs.
- **Once per order on the platform's idempotency (D29).** Every `issuePass` carries the
  order's `Idempotency-Key`, `orderId` and `shopId`. An unclear answer is asked again with
  the same key: the client retries a keyed POST twice, a scheduled action repeats it after
  5 minutes, an hour and 6 hours, and the order action does it by hand. The card link is
  mailed when the answer comes; in 0.1 it was lost. A repeat that would not be the same
  request, or that comes after six days, is not sent.
- **Simpler locks (D29).** The permanent per-order and per-address claims are gone; what is
  left is a lock per order and per address that expires after two minutes, the state on the
  order, the card's serial, and the one-card-per-e-mail rule (Rewloy does not enforce it).
- **No more "Kartı yok" race (D30).** When Rewloy answers `order.result: "resend"` the order
  is delivered again through its WooCommerce webhook (`WC_Webhook::process`), within seven
  days, and counts toward the card.
- **Health on the screen (D32).** The last request from the shop and its result
  (`lastDelivery`), the last request refused for its signature (`lastRefusal`) and the key
  Rewloy lists for the link (`pluginKey`), in the panel's own words.
- **Test environments (D33).** A `rwk_test_` key is said to be one; its mask shows
  `rwk_test_` and its prefix.
- **Checked against the real Rewloy (docs/VERIFIED.md), two fixes.** The repeat of an unclear
  answer was scheduled with Action Scheduler's `unique` flag, which counts the action that is
  running as the same action, so it was never scheduled while the note said it was (D29). And when
  the link is deleted in the Rewloy panel, the key a code made is revoked with it: the screen and the
  removal now say so and open "forget the connection on this site only" (D35).
- Turkish for every new string; `.pot`, `.po` and `.mo` complete.
- Tests for every change: 295 PHPUnit tests; PHPStan level 8 and `bin/lint` clean;
  `bin/build-zip` builds `rewloy-for-woocommerce-0.2.0.zip`.

## 0.1.0 (yayımlanmadı / unreleased)

İlk önizleme. First preview:

- Connect with an API key: the plugin makes the Rewloy link and the WooCommerce webhook
  (`order.updated`, `wp_api_v3`), shows the webhook's health and the last orders with their
  outcomes in the panel's words, pauses, resumes and disconnects.
- Rules as in the panel; the webhook's body cut down to the order's number, status,
  currency, total and (when paid) billing e-mail.
- Optional invitation at checkout (classic and block checkout), at most one card per order
  and per e-mail, its private link e-mailed to the buyer and not stored.
- Optional My Account tab with no card data and no call to Rewloy.
- HPOS compatible; Turkish translation; `bin/build-zip`; CI.
- Run on a real WordPress 7.1.2 with WooCommerce 11.1.2 against a local fake of the Rewloy
  API (docs/VERIFIED.md), which found four defects, all fixed.
