# Verified on a real WordPress

Two runs are written here. **0.2.0 against the real Rewloy application** (running locally) comes
first; it is the one that matters for what 0.2.0 added. After it, unchanged, the **0.1.0 run against
a fake Rewloy**, which is what checked activation, the checkout boxes, My Account, uninstall and Turkish.

# 0.3.0: the Rewloy menu

Run on 4 October 2026. Three parts, because the local WordPress is connected to the **production**
Rewloy (where no write may be made from a check) and production did not yet have ADR 178.

| What | How | Result |
|---|---|---|
| The till and the reads against the real Rewloy | Rewloy's `wp-panel` branch (ADR 178) run from its worktree on `localhost:3722` with a throwaway test database; a business with two branches, a code with "Görüntüleme" and "Kasa" at one branch, spent by `connectShop`. The plugin's own `Panel` and `Till` classes driven from PHP with `wp_remote_request` over curl (WordPress functions shimmed) | `me` gave `view`, `till` and the branch; the 30-day numbers; a sale wrote a stamp with the press's UUID as the key and the receipt in `reference`; the same press again answered "already recorded"; seven sales then `redeem-stamps` refused as "not ready" (7/8); `load` never sent; activity masked to `••••-••••-26UG`, "Integration (API key)"; the customer's e-mail and name and the owner's e-mail in nothing the plugin received or sent; no `?k=` anywhere |
| `assets/till.js` | the real `Screens::till()` markup with WordPress's admin styles and a mocked admin-ajax, in Chromium at desktop and 375 px | a scanned `https://rewloy.com/p/…?k=…` left only the number in the field and sent only the number; a sale with no answer offered "Tekrar dene", which resent the same key and body; the next press had a new key; no horizontal scroll at 375 px |
| The screens in the real WordPress | the 0.3.0 zip installed in the local WordPress 7.1.2 / WooCommerce 11.1.2 (Turkish), connected to production Rewloy (read-only calls only: `me`, `getShop`, `listShopOrders`) | Özet, Kartlar, Kasa and Ayarlar render at desktop and 375 px with no horizontal scroll; the menu and WooCommerce › Rewloy (now a link to Ayarlar) both work; production's `me` names no abilities, so Kartlar and Kasa say they are off and link to the shop link's page, as a shop connected before 0.3.0 should |

Not run: the Kartlar refresh and the till in the real WordPress against a Rewloy that grants the
abilities (they need production on ADR 178, or a second WordPress pointed at a local Rewloy with
`REWLOY_API_URL` on a https or localhost origin the container can reach).

# 0.2.0 against the real Rewloy

Run on 4 October 2026, plugin at `e6faab4` plus the two fixes below. The 0.2.0 code had run only
under PHPUnit before; the list of what it had never met (spending a real connect code, replay on the
key, `order.result` and resend, `WC_Webhook::process` for an already-paid order, Action Scheduler's
calls, the new screens) is what this run was for. Every item of that list ran.

## Environment

| | |
|---|---|
| Rewloy | the real application, `main` at `96ae347` (ADR 174/175), run from source in a temporary git worktree: `node --experimental-strip-types src/app/main.ts`, `PORT=3719`, a throwaway Postgres 17 database made by `scripts/testdb.ts`, no mail provider. Nothing in the Rewloy repository was changed |
| Business | a live business "E2E Kafe" (plan Business: `ecommerce` and `api`), a points programme (rule: per 100 TRY, 2 points) and a stamp programme, a customer with a card in both; its **test environment** (`" · Test"`, ADR 173) opened by `openTestBusiness`, with a points programme and one customer with a card |
| Codes | made the way the panel's route does: `POST /v1/shops/connect-tokens` with the owner's staff session and password (a code per scenario: 15 minutes each) |
| WordPress | 7.1.2 (`wordpress:latest`, Apache), WooCommerce 11.1.2, PHP 8.3.35, MariaDB 11, Twenty Twenty-Five, `WP_DEBUG` and `WP_DEBUG_LOG` on |
| Orders storage | HPOS on with compatibility mode |
| Plugin | the zip of `bin/build-zip` installed with `wp plugin install` |
| Browser | the embedded Chromium: the admin screen (every connect, pause, resume, disconnect and the options), and the real block checkout with the box ticked |
| Locale | `en_US`, then `tr_TR` (WordPress and WooCommerce language packs) |

How the plugin was pointed at the local Rewloy, and what that cost:

- `define( 'REWLOY_API_URL', 'http://localhost' )` (the plugin's one sanctioned override). The plugin
  refuses a delivery address with a port (`Connection::is_delivery_url`), and Rewloy builds that address
  from `APP_ORIGIN`, so `APP_ORIGIN=http://localhost` and Apache inside the WordPress container answers
  on port 80 and proxies `/v1` and `/hooks` to the host's `host.docker.internal:3719` (that name works
  under Colima). Apache also listens on 8089 inside the container so that WordPress can loop back to
  its own public address.
- The plugin only mails a card link on `https://*.rewloy.com` (`Settings::is_rewloy_url`), and Rewloy
  builds `cardUrl` from `SITE_ORIGIN`, so the local Rewloy was started with `SITE_ORIGIN=https://rewloy.com`
  (a string; nothing was ever sent to it). The real production host was therefore not exercised.
- WooCommerce delivers webhooks with `wp_safe_remote_request`, which refuses local addresses: the same
  must-use plugin as in the 0.1.0 run (`http_request_host_is_external` for `localhost`), and `pre_wp_mail`
  to catch mail.
- **A mistake of the harness, worth knowing:** WP-CLI in a separate container did not have the
  `WORDPRESS_CONFIG_EXTRA` that defines `REWLOY_API_URL`, so for a short time `wp action-scheduler run`
  sent an `issuePass` (and one debug call) to the plugin's default host, `https://app.rewloy.com`, with a
  throwaway local key. It answered 401 `INVALID_API_KEY` and nothing was created; the CLI was given the
  same environment after that. It is also how the first of the checks below noticed that the plugin
  honours the constant only when it is defined.

## Results

### 1. Connect with a real code: passed

Pasted into the screen's field and Connect pressed: one `POST /v1/shops/connect` (body: the code and the
site title as `shopName`), then `GET /v1/programs`, `GET /v1/shops/<id>`, `GET /v1/shops/<id>/orders`.

- Rewloy: the code is spent (`used_at`, linked to the link and the key); a link (`woocommerce`, rule
  `amount`, 100.00, step 2); a key `WooCommerce · E2E Shop`, role E-ticaret, `plugin = true`, bound to
  the link (`store_link_id`).
- WordPress: a webhook `Rewloy`, `active`, topic `order.updated`, `api_version` 3, delivery URL
  `http://localhost/hooks/store/<link id>`, user the connecting administrator, a secret; options
  `rewloy_wc_api_key` and `rewloy_wc_settings`, both `autoload=off`; no secret in either.
- The screen: the masked key, "Puan (Points card)", the rule in words, link On, "webhook active, failed
  deliveries: 0", "Key in Rewloy: WooCommerce · E2E Shop (5b3e6cdf74…)".
- Not seen: WooCommerce's form-encoded ping. `WC_Webhook::save()` of a new active webhook sent none (in
  the WooCommerce source the ping is in the data store's `update()`); the 0.1.0 text below says otherwise. Rewloy answers it
  `ping` when it comes (`test/ecommerce.test.ts`).
- Also: a malformed code is refused without a call; a well-formed unknown one gives "did not accept this
  code…" with the request id; a code pasted while connected is refused ("already connected") and left unspent.

### 2. A paid order credits the card: passed

An order of 240.00 TRY (`processing`) for `alici@e2e.test`, who has a points card. WooCommerce queued the
delivery; it ran from Action Scheduler; Rewloy answered `{"data":{"outcome":"credited"}}`. In Rewloy's
database: `store_order` (13, credited), and the card's ledger holds one `earn` of **4** (2 units of 100
times step 2), key `store:<link>:13`. Balance 0 to 4. The signature (base64 HMAC-SHA256 of the **trimmed
body**) and the body were accepted by the real `handleOrder`, which was the open question of 0.1.0. A
later order of 120 earned 2, and a re-saved paid order is `duplicate`.

### 3. The checkout invitation: passed, one defect found and fixed (see "Defects")

A real checkout in the browser (block checkout, additional order information): the box is unticked,
carries the controller's name, the notice and the privacy URL; ticked, the order has
`_wc_other/rewloy-for-woocommerce/invite = 1`; Cash on delivery makes the order `processing`. For
`newbie@e2e.test`, who had no card:

- **One card.** `POST /v1/passes` with `Idempotency-Key: woo-d6bc14ce29-15`, `orderId` 15 and `shopId`:
  one card at Rewloy for that address.
- **The order that earned it is credited.** The order's own delivery had reached Rewloy first
  (`unmatched`, "Kartı yok"); the card's answer was `order: {result: "resend", outcome: "unmatched"}`; the
  plugin handed the order to `WC_Webhook::process( 15 )` of the (already `processing`) order; WooCommerce
  queued, signed and delivered it; Rewloy credited it (this order's first attempt was the harness mistake above, so it was the order action that opened its card; the card's answer and what followed are the real ones): one ledger entry for order 15, balance 2. The order
  note says the card was opened, the link e-mailed (it was, to `newbie@e2e.test`, with a
  `https://rewloy.com/p/<serial>?k=…` link) and the order was queued to be sent again.
- **The other order of arrival** (`order: {result: "waiting"}`): the card was opened first, then the order's
  delivery found it and credited it once. No `resend` note.
- **Replay.** The same request (same key, same body) sent by hand with the plugin's key: `201`,
  `Idempotent-Replayed: true`, the same serial and private link, still one card for the address. The
  replayed answer carries the first answer's `order` as it was (`resend`, `unmatched`) although the order is
  `credited` by then. Through the plugin (the order's state erased, then run): "card opened … Rewloy
  answered with the card an earlier request of this order had opened"; no second card, no second credit
  (the second delivery is `duplicate`).
- A second ticked order of an address already invited: state `exists`, the note names order 15, and **no
  request** reached Rewloy.

### 4. An unclear answer: passed (after the fix)

Two ways, both with a real Rewloy:

- **Rewloy stopped** (SIGTERM, then started again): the issue action found no one; the order went to
  `unknown`, the first request's record was written, the note says the same request is repeated
  automatically. **Before the fix no repeat was scheduled** (defect 1). After it, a pending single action
  for +5 minutes existed; with Rewloy back and the time passed, Action Scheduler ran it: one card, the link
  mailed, the next scheduled attempt cancelled, the order credited. `as_unschedule_action` cancelled the
  pending repeat on success (`canceled` in the table).
- **The answer lost after the work was done:** a one-file proxy in front of `/v1/passes` forwarded each request to
  Rewloy and then dropped the connection. The first request opened the card at Rewloy; the client's two
  retries with the same key were each answered `Idempotent-Replayed: true` and dropped; the plugin ended
  `unknown`. With the proxy removed and the repeat due: "card opened … Rewloy answered with the card an
  earlier request of this order had opened"; **still one card for the address**, one mail, the order credited.
- By hand: the order action "try opening the card again" opened the card of an `unknown` order once.

### 5. Pause, resume, disconnect, uninstall: passed

- **Pause** (the screen's button): `PATCH /v1/shops/<id> {"enabled":false}`; "Link: Off"; an order paid
  meanwhile was recorded `paused` and the card did not change; the webhook stayed `active`. **Resume**:
  `enabled` true; the next order credited; the paused order stayed `paused` after being saved again ("now
  or later" is true).
- **Disconnect** (the screen's button): `DELETE /v1/shops/<id>` answered 204; the link is gone, **the key is
  `revoked`, and the same key sent to `GET /v1/me` afterwards answers `401 INVALID_API_KEY`**; the
  WooCommerce webhook is gone, the key option is gone, the choices (controller, tab) stay.
- **Uninstall** (`wp plugin uninstall`, while connected): the options, the webhook and the
  pending actions are gone, the folder is deleted, **no request reached Rewloy** (the link and its key stay
  there, as the screen says), the order meta stays.
- **A link deleted in the Rewloy panel** (not in the list; found by the run): the key went with it, orders were
  answered 404 and counted as failures by WooCommerce, and the screen said "check that the key is complete",
  as did the removal. Defect 2.

### 6. Health: passed

After a credited order the screen shows "Last request from the shop: <time> · Added to the card", "Last order
seen", the outcomes (6 added, 1 no card at the time) and the last orders. One request with a wrong signature
(`401`) to the link's address made Rewloy record it and the screen show "Last refused request: <time> ·
Signature did not match" with the explanation; the order it carried was not recorded, and `lastDelivery`
stayed as it was. In `tr_TR` the same rows read "Mağazadan son istek … · Kayıtlı siparişin tekrarı" and the
buttons "Bağlantıyı kaldır", "Bağlantıyı yalnız bu sitede unut".

### 7. The test environment: passed

A code made in the test business (`rewloy-merchant: <test business>`): the screen said "Connected to your
Rewloy test environment…", the key reads `rwk_test_43c455f268••••••••` with the test-environment paragraph, and
the connection used the test programme (per 50.00 TRY, 1 point). A 240 TRY order for the test customer
credited **4** in the test business; a ticked order opened a card there and credited it (2); the real
business's customer's balance did not move. Rewloy sent nothing of its own: its test outbox holds only the
mail about the new key, held back instead of sent. The plugin itself still mailed the card link to the
typed address, as the screen warns. Disconnect: the link deleted, the test key revoked.

## Defects found and fixed

1. **The repeat of an unclear answer was never scheduled** (`73f8b04`). `Issuer` scheduled it with
   `as_schedule_single_action( …, unique = true )`. Action Scheduler's unique check counts a **running**
   action with the same hook, arguments and group as a duplicate, and the first attempt always runs inside
   that action (the async one the paid order queued). Nothing was scheduled, the closure still answered true,
   and the order note promised "repeated automatically": an unclear order waited for a person to press the
   order action. Seen in the table (only the finished async action existed) and in the unit tests' blind spot
   (the stubs do not know "running"). Now a plain single action, skipped only when a pending one already waits
   for that time or later, and the note promises it only when an action id comes back. Tests added.
2. **A connect-code connection whose link was deleted in the panel was a dead end** (`9079488`). The key is
   revoked with the link, so the screen and the removal said "check that the key is complete", the removal
   could never work, and the one button that does ("Forget the connection on this site only") was folded
   away. Now both say the link was probably deleted and name that button, and the fold is open. Turkish added.
   Tests added.

## Seen and left alone

- The invitation reaches the customer about a minute after the order, not in the request: Action Scheduler
  ran from WP-Cron (once a minute). That is by design (D29: a slow Rewloy must not hold the checkout); on a
  shop with no traffic and no real cron it can take longer.
- The card e-mail prints "Data controller: E2E Kafe Ltd.. Details:" when the controller's name ends in a full stop.
- The note after a replayed `resend` says the order "was queued to be sent again" although it had been credited
  already; the replay carries the first answer as designed (D30), and the second delivery is `duplicate`.
- "The link in Rewloy is still there" is printed after "forget on this site only" even when it is not.
- Every save of an order triggers another `order.updated` delivery (WooCommerce does that), including the
  plugin's own note and meta writes; Rewloy counts an order once (`duplicate`).
- `WC_Webhook::save()` sends no ping (see check 1); the 0.1.0 text below, written on a fake, says it did.

## Platform findings (Rewloy)

None that is a bug. Papercuts for the platform's notes: the replayed `issuePass` answer keeps the first answer's
`order.result` (`resend`) after the order has been credited, so a client that acts on it delivers once more
(harmless: `duplicate`); `DELETE` with `Content-Type: application/json` and no body is `400 INVALID_JSON` (the plugin
sends no content type with a body-less call, so it never meets it).

## Not verified

The production host and its https (the local Rewloy was told its card origin is `rewloy.com`); behaviour after
Rewloy's seven-day replay window; Action Scheduler's admin-ajax async runner (the queue ran from WP-Cron and
WP-CLI); a real mail transport; WooCommerce below 11.1, WordPress below 7.1, PHP 8.1 at run time, multisite;
HPOS off with the real Rewloy (the 0.1.0 run covers the legacy store with the fake); the classic checkout with a
real Rewloy (0.1.0: with the fake); the My Account tab against the real Rewloy (it makes no call).

---

# 0.1.0 against a fake Rewloy

> This section is the run of 0.1.0, left as it was. What it could not check (the real Rewloy) is the 0.2.0 run above.

Run on 4 October 2026. Until then the plugin had only run under PHPUnit with stubs.
This file says what was run, what was seen, what was found wrong and what is still
unchecked. Defects found are fixed in the same change (see DECISIONS.md D23 to D26).

## Environment

| | |
|---|---|
| WordPress | 7.1.2 (the official `wordpress:latest` image, Apache) |
| WooCommerce | 11.1.2 (latest from wordpress.org, installed with WP-CLI) |
| PHP | 8.3.35 at run time; the code was also syntax-checked on 8.1.34 |
| Database | MariaDB 11 |
| Theme | Twenty Twenty-Five (block theme) |
| Plugin | the zip from `bin/build-zip`, installed with `wp plugin install` |
| Debug | `WP_DEBUG` on, `WP_DEBUG_LOG` on, display off |
| Orders storage | HPOS on with compatibility mode (most checks); HPOS off for checks 5 and the legacy-store defect |
| Locale | `en_US`, then `tr_TR` (WordPress and WooCommerce language packs installed) |
| Browser | the embedded Chromium of the dev tool, for the admin screen, My Account and both checkouts |

**The Rewloy API was not called.** No real key was used and nothing left the machine
except the WordPress.org downloads. A small PHP fake (kept outside the repo) answers
`listPrograms`, `createShop`, `getShop`, `listShopOrders`, `setShopEnabled`, `deleteShop` and
`issuePass` in the shapes of `src/api/v1/business.ts` and `cards.ts`, and receives the store hook
at `/hooks/store/:id`, where it checks `X-WC-Webhook-Signature` the way `handleOrder`
does (base64 HMAC-SHA256 of the raw body with the secret) and answers the form-encoded
ping with 200. It records every request, including headers and body.

How the plugin was pointed at it, and what that cost:

- `define( 'REWLOY_API_URL', 'http://localhost' )` in `wp-config.php`. The Client allows plain
  HTTP only for `localhost`, and `Connection::is_delivery_url()` refuses a delivery address
  with a port, so the fake had to answer on port 80: Apache `ProxyPass` of `/v1` and `/hooks`
  to the fake inside the same container.
- WooCommerce delivers webhooks with `wp_safe_remote_request`, which refuses `localhost`. A
  must-use plugin (test environment only) lets it through (`http_request_host_is_external`,
  `http_allowed_safe_ports`).
- The container has no `sendmail`. The same must-use plugin catches `wp_mail` (`pre_wp_mail`),
  logs it and says it was sent (or says it failed, for check 5's failure branch).
- The queue was run with `wp action-scheduler run`: the container cannot loop back to its own
  public address, so the async runner could not be exercised.

## Results

### 1. Activation: passed

- Activated with WP-CLI and from Plugins in the browser (deactivate, then activate): no
  "unexpected output" notice and nothing in `wp-content/debug.log`. In the whole run the plugin's code raised
  one notice, the legacy-store one of check 5 (fixed); the screens, both checkouts and My Account raised none. (The log
  also showed a WooCommerce notice about its own text domain while WooCommerce itself was being installed, before
  this plugin existed.)
- HPOS: with `custom_order_tables` on, `FeaturesController::get_compatible_plugins_for_feature`
  lists `rewloy-for-woocommerce/rewloy-for-woocommerce.php` as **compatible** for both
  `custom_order_tables` and `cart_checkout_blocks`; the incompatible and uncertain lists are empty.
- With WooCommerce deactivated and the plugin active, the plugin only shows its notice
  ("needs WooCommerce to be installed and active") and nothing breaks.
- The Plugins row has the "Settings" link to WooCommerce › Rewloy.

### 2. Connect: passed

Saving a key makes one `listPrograms` call; Connect makes `listPrograms`, then `createShop`
(`{"platform":"woocommerce","programId":…,"rule":"amount","step":2,"perAmountMinor":9950}` for a points card,
`rule: order` with no amount for a stamp card), then creates the webhook. What WooCommerce stored:

| | |
|---|---|
| name | `Rewloy` |
| status | `active` |
| topic / resource / event | `order.updated` / `order` / `updated` |
| delivery URL | the `webhookUrl` the fake returned, `…/hooks/store/<link id>` |
| secret | the secret the fake returned (48 hex), in the webhook only; in none of the plugin's options |
| user | the connecting administrator |
| API version | `set_api_version( 'wp_api_v3' )` is **accepted**; WooCommerce **stores the integer `3`** (`wc_webhooks.api_version = 3`, `get_api_version()` answers `wp_api_v3`) |

WooCommerce sends the form-encoded ping (`webhook_id=N`, no signature) to the delivery URL once;
the fake answers it like Rewloy does.

Also seen: a delivery address with a port is refused ("not what a shop link looks like"), the link
is deleted again and nothing is connected; the webhook that WooCommerce disables after repeated
failed deliveries shows on the screen as "disabled, failed deliveries: 6" with "Turn the webhook on
again", and the button makes it `active` with 0 failures.

### 3. The `woocommerce_webhook_payload` filter trims the body: passed, with one defect found and fixed

The filter receives `($payload, 'order', $order_id, $webhook_id)` and replaces the body. What
the fake received, for an order with a name, address, phone number and a line item:

```
{"id":13,"number":"13","status":"pending","currency":"TRY","total":"240.00","billing":{"email":""}}
{"id":13,"number":"13","status":"processing","currency":"TRY","total":"240.00","billing":{"email":"ayse@example.test"}}
{"id":13,"number":"13","status":"completed","currency":"TRY","total":"240.00","billing":{"email":"ayse@example.test"}}
{"id":18,"number":"18","status":"on-hold", … "billing":{"email":""}}
```

- Name, address, phone, items, IP: none of it is in the body. Number, status, currency, total
  are; the billing e-mail only while `processing` or `completed`.
- `X-WC-Webhook-Signature` is the base64 HMAC-SHA256 of **the trimmed body** with the stored
  secret: the fake recomputed it from the raw bytes and the secret it had issued, and every
  delivery matched (`sig_ok=true`). So Rewloy can verify it. The headers also carry
  `X-WC-Webhook-Topic: order.updated`, `-Resource`, `-Event`, `-ID` and `-Delivery-ID`.
- A second webhook (order.updated, someone else's) received the full order payload: only
  the plugin's own is touched.

**Defect found (fixed):** the filter trimmed *WooCommerce's REST payload*. WooCommerce builds that
payload as the webhook's user. With the webhook's user set to a subscriber (as when the
administrator who connected is later deleted or demoted) the payload is a REST error array and the
body became `{"id":18,"number":"","status":"","currency":"","total":"","billing":{"email":""}}`:
Rewloy answers that 200 "ignored", so there is no failure anywhere, the webhook stays "active" and
no order is ever credited. Now the body is built from the order (`wc_get_order`), so it does not
depend on that user. Checked again with the same subscriber: the full, correct body arrives.

### 4. Checkout invitation: passed

- **Classic checkout** (`[woocommerce_checkout]`, `woocommerce_after_order_notes`): an unticked
  checkbox `rewloy_invite` with the notice beside it and a "Privacy notice" link. Ticked, the order
  gets meta `_rewloy_invite = yes`.
- **Block checkout** (WooCommerce 11.1 additional checkout fields, location "Additional order
  information"): an unticked checkbox, id `order-rewloy-for-woocommerce-invite`, the label is the
  box's sentence plus the notice and the privacy URL as plain text, and WooCommerce adds
  "(optional)". Ticked, the order gets meta **`_wc_other/rewloy-for-woocommerce/invite = "1"`**,
  which is the key `Issuer::META_INVITE_BLOCKS` reads. Left unticked, **no meta is stored at all**.
- The box and its text do not show on the order screen, the order-received page or the customer
  e-mail.
- The data controller's name and e-mail from the settings appear in the notice.

### 5. A paid order with the box ticked: passed (HPOS on and off)

One `issuePass` per order, however many times the status changes (processing, completed, on-hold,
processing again): the fake received exactly one `POST /v1/passes` for the order.

```
POST /v1/passes   Authorization: Bearer rwk_…   Content-Type: application/json
Idempotency-Key: woo-d6bc14ce29-31          (woo-<10 hex of sha1(home url)>-<order id>)
{"programId":"22222222-2222-4222-8222-222222222222","email":"final1@example.test","kvkkConsent":true}
```

- The card link (`https://rewloy.com/p/<serial>?k=…`) is e-mailed to the billing address ("Your … loyalty card")
  and kept nowhere: order meta has only `_rewloy_card_state = issued` and `_rewloy_card_serial`.
- Order note: "Rewloy: card opened (<serial>). Its link was e-mailed to the customer."
- Other branches, run with the fake set to misbehave: a 422 `PASS_REFUSED` gives state `failed`, the note
  "Rewloy refused to open the card. (request: …)" and the order action "Rewloy: try opening the card again", which
  (clicked in the browser) opens the card once; a 500 gives `unknown`, the "no clear answer came" note, no retry
  offered and no second request; a failing `wp_mail` gives `issued` with the "e-mail … could not be sent" note.
- A second ticked order with an address that was already invited gets `exists`, a note naming the first order, and no request.
- No box ticked, or the order unpaid: no request.

**Defect found (fixed), legacy order storage only.** With HPOS **off**, "has this address been invited?"
asked `wc_get_orders` with a `meta_query`, which the post-based order store ignores (WooCommerce logs
"Order query argument (meta_query) is not supported on the current order datastore"). Every other order
of the address then counted as invited: a customer whose first order never invited anyone, ticking the box on the second, was told
`exists` and got no card (seen: orders 25 and 26). Now the orders of the address are listed and each one's state read
in PHP; the notice is gone and the second order opens its card (orders 29 and 30).

### 6. My Account › "Sadakat kartım": passed, one cosmetic defect fixed

- Turning the option on in the settings flushes the rewrite rules once (the flag is consumed on the next
  request): `loyalty-card(/(.*))?/?$` is in the rules, "My loyalty card" is in the menu before "Log out", and
  `/my-account/loyalty-card/` renders. No other flush was needed.
- **No API call:** the fake's request log did not grow by one line while the tab and the My Account page were loaded.
- **No card data:** the page is the text, "Open Rewloy Cüzdan" and "Get it here"; neither the
  customer's name nor e-mail nor a serial appears.
- Cosmetic defect (fixed): the page title (WooCommerce replaces it with the endpoint's title) and a
  heading of the plugin's own both said "My loyalty card"; the second is gone.
- Defect found (fixed): the tab exists only while connected, but nothing flushed the rules on connect or disconnect. After a
  disconnect, any flush (saving the permalinks) and a new connect, the rule was gone and the tab was in the menu
  with a dead address. Connecting and disconnecting now ask for a flush when the tab is on; the
  sequence was run and the rule is back.

### 7. Pause, resume, disconnect, uninstall: passed

- **Pause** sends `PATCH /v1/shops/<id> {"enabled":false}`; the screen says "Link: Off" and "The webhook stays"; the WooCommerce webhook
  stays `active`. **Resume** sends `{"enabled":true}`.
- **Disconnect** sends `DELETE /v1/shops/<id>`, deletes the WooCommerce webhook (none left), clears the connection and keeps the
  key. A link that Rewloy no longer has (404 `SHOP_NOT_FOUND`) is treated as done. "Forget on this site only" is a button on the
  same screen and makes no request (covered by the unit tests; not clicked in the browser).
- **Uninstall** (`wp plugin uninstall`, WooCommerce active): the options (key, settings, per-order and per-address claims, flash
  transients), the webhook and the scheduled actions of the plugin are gone and the plugin's folder is deleted; **no request reached the fake** (the request log
  did not grow); the link stays in the fake; the order meta (`_rewloy_*`) stays. Run again with
  **WooCommerce deactivated** (the SQL fallback that deletes the webhook row): the webhook row and options are gone too.
  Settings screen and README say what happens, and it is what happened.

### 8. Turkish (`tr_TR`): passed

With the site language Turkish, the settings screen (every section, including the privacy text and the outcome
labels "Karta işlendi", "Kartı yok", …), the classic and block checkout box, the My Account menu item and page, the order notes
("Rewloy: kart açıldı (…)", "kart açılmadı", "net bir yanıt gelmedi", "e-posta gönderilemedi") and the card e-mail
("Merhaba, #18 numaralı siparişinizle …") showed the Turkish strings from `languages/…-tr_TR.mo`.
The block checkout label is built at `woocommerce_init`, before the plugin loads its text domain, and was still Turkish.

## Tested up to

`readme.txt` now says `Tested up to: 7.1` (WordPress 7.1.2) and the plugin header and `readme.txt` say
`WC tested up to: 11.1` (WooCommerce 11.1.2). The plugin header has no WordPress "Tested up to" line:
WordPress.org reads it from `readme.txt` only.

## Seen and left alone

- Every save of an order (including the plugin's own note and meta writes after `issuePass`) triggers another
  `order.updated` delivery. WooCommerce does that for any webhook; Rewloy counts an order once.
- The block checkout label carries the privacy URL as plain text, not a link (the field API's label is plain text). It is a
  decision (D16), not a defect.
- The order that earns the invitation can be recorded "Kartı yok": the known limit in DECISIONS.md.

## Not verified

See "Still not verified" at the end of DECISIONS.md: the real Rewloy (host, `Idempotency-Key`, acceptance of the body), WordPress
below 7.1 and WooCommerce below 11.1, PHP 8.1 at run time, multisite, a real mail transport, Action Scheduler's own
async runner, other browsers and locales.
