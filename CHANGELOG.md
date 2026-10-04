# Değişiklik günlüğü / Changelog

Bu eklentinin sürümleri. API'nin kendi değişiklikleri:
https://rewloy.com/gelistiriciler/degisiklikler

This plugin's releases. The API's own changes are listed at the link above.
Every decision and its reason: [docs/DECISIONS.md](docs/DECISIONS.md).

## 0.3.0 (4 Oct 2026)

WordPress'te küçük bir Rewloy paneli: özet, kartlardaki işlemler ve kasa. Neler yapabileceğini Rewloy
panelinde seçersiniz; kasa siz açana kadar kapalıdır.

A small Rewloy panel inside WordPress: overview, activity on the cards and a till. What it may do is
chosen in the Rewloy panel; the till is off until it is turned on there. Needs Rewloy with ADR 178.

- A top-level **Rewloy** menu with four tabs (D37). *Özet*: the connected card and business, the
  link's health, the last orders, the card's numbers for 30 days each named by what it counts, and
  "done in the Rewloy panel" with the exact page for each thing the plugin does not do (D44).
  *Kartlar*: the latest activity on customers' cards, a card by its last four characters, refreshed
  every 30 seconds while the tab is visible; a card lookup by number. *Kasa*: read a card typed or
  scanned, Rewloy's notices for the branch, record a sale with a receipt number, use the card's own
  operations. *Ayarlar*: the 0.2 screen, unchanged; WooCommerce › Rewloy still opens it.
- Abilities from `GET /v1/me` on every load (D38): "Görüntüleme" opens Özet's numbers and Kartlar,
  "Kasa" opens the till at its one branch; off, each tab says what it is, why, and links to where it
  is turned on.
- No customer's name, e-mail or phone in WordPress (D39); a scanned card link's private key is
  dropped at once (D40); the API key never reaches the browser: the scripts talk to admin-ajax with a
  nonce (D41).
- One Idempotency-Key (a UUID) per till button press, the same on "Tekrar dene"; the receipt number
  goes in `reference`. `IDEMPOTENCY_KEY_REUSED`, `LOCATION_NOT_FOUND` and the other till refusals in
  the panel's words (D42). Spending asks to confirm; a gift card top-up is never offered (D43).
- New `Client` calls: `get_program`, `get_pass`, `get_pass_till`, `record_sale`, `pass_action`,
  `list_activity`, `analytics`. Two small scripts and a stylesheet under `assets/` (in the zip).
- Turkish for every new string; `.pot`, `.po` and `.mo` rebuilt. README, readme.txt (privacy section
  included) and docs/DECISIONS.md (D37–D46).
- **The security review, fixed before release:** a till press with no clear answer stays pending
  (in the tab, across a reload) with the sale and the card's buttons locked until a clear answer; the same
  payload reuses its key; a confirmed write whose card could not be read again is a success, never a retry
  (D46). The till has its own capability, `rewloy_wc_till`: administrators, and shop managers only when an
  administrator ticks "Mağaza yöneticileri de kasayı kullanabilir" in Ayarlar (off by default); filterable (D45).
- 382 PHPUnit tests (78 new); PHPStan level 8 and `bin/lint` clean.

## 0.2.2 (4 Oct 2026)

Panelin mağaza formuyla aynı: bağlantı ekranı her kartı damga kartı saymaz.

As in the panel's shop form: the connect screen no longer treats every card as a stamp card.

- The card list names each card's type and the screen says what an order does for it:
  stamp and points cards follow the rule; a VIP card counts one visit per paid order; a
  cashback card applies its own rate to the order total (`config.cashbackRate` of
  `GET /v1/programs`; with an example on a 400 order, and without a number when Rewloy
  does not list the rate). No rule is needed for VIP or cashback.
- The rule fields (per order or per amount, amount, how many) apply to stamp and points
  cards only: they are labelled so, and are not shown at all when no stamp or points card
  can be chosen. No script is involved; what was already sent for VIP and cashback cards
  is unchanged (the plugin sends no rule for them).
- For a cashback card the outcome "Below the threshold" says the cashback came to nothing
  (a cashback card has no threshold). `readme.txt` lists VIP and cashback beside stamps and
  points and says gift cards, coupons and discount cards cannot be linked to a shop.
- Turkish for every new string; `.pot`, `.po` and `.mo` rebuilt.
- No change to how orders are credited or to anything sent to Rewloy.
- 304 PHPUnit tests (9 new); PHPStan level 8 and `bin/lint` clean.

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
