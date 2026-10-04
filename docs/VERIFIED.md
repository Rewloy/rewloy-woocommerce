# Verified on a real WordPress

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
