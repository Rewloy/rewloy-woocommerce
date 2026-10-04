# Decisions

Decisions taken while building the plugin, when the brief left a choice or the platform
did something the brief did not expect. The owner was away, so each was decided by
default and written down here. Each entry says what, and why.

**0.2.0 (4 October 2026)** used what the platform went live with that day (Rewloy's ADR 174:
`issuePass` replays on an `Idempotency-Key` and takes `orderId` and `shopId`, the one-time connect
code, the narrow "E-ticaret" role, `GET /v1/me`, shop health). Its decisions are **D27 to D34**.
An entry of 0.1 that 0.2.0 replaced is marked **Superseded** or **Amended** where it stands and
says by which; its text is left as it was, because it is the reason the code looked the way it did.

## What the platform does that shaped the plugin

These were read from the platform's code (`src/api/v1/business.ts`, `cards.ts`,
`src/app/routes/hooks.ts`, `src/modules/ecommerce/service.ts`) and the published
OpenAPI document, not assumed.

**D1. `issuePass` neither de-duplicates nor replays on an `Idempotency-Key`.** *(Superseded in 0.2.0 by D29: the platform now replays. Points 1 to 4 below, the permanent claims and "never retried", are gone; point 5, one e-mail one card, stays.)*
In the platform, `issuePass` is not declared with `idempotency` or `replay`, so
the header is ignored, and `issuePassIn` inserts a new card on every call (no
look-up by person). The brief asks for "at most once per paid order, with its
idempotency key" and for "an existing card is not a failure". The API gives no
way to learn that a card exists. So the guarantee is the plugin's own (`Issuer`):

1. an **atomic claim** on the order before anything is sent: a per-order options
   row added with `INSERT IGNORE` (`Lock`, the way WordPress core takes its own
   upgrade lock), which affects one row for the one process that inserted it and none
   for any other. `add_option()` is not used: its `ON DUPLICATE KEY UPDATE` can report
   success to both of two racing callers when the values differ. The claim is **kept
   for good** once a card may have been opened (`issued`, `unknown`, `exists`) and let
   go only after a clear refusal (`failed`). That is what makes later writes to the
   order's meta (a stale copy of the order, an erased meta, a stray save) unable to
   open the way for a second request: the claim does not depend on the meta;
2. a second claim on the **e-mail address** (a hash of it, never the address in an
   option name), so two orders of one address paid at the same moment cannot both pass
   the "already invited?" check;
3. the order's **state meta** (`issued`, `failed`, `unknown`, `exists`; no "queued":
   `on_paid` writes nothing to the order, it only reads it afresh and enqueues, and
   whatever is enqueued twice is stopped by the claim). `unknown` (code `SENDING`) is
   written **before** the request, so a process that dies mid-way (a fatal, a time
   limit) leaves the safe state; the result then overwrites it. The card is recorded
   (`issued` and its serial) **before** the mail is sent, and the mail runs in a
   `try/catch`, so nothing after the call can lose the record;
4. the call is **never retried**, whatever the failure (see D12). When the answer is
   unclear (no answer, a 5xx, a 2xx that is not the documented JSON, anything thrown)
   the state stays `unknown`, final: a possibly-issued card is not asked for again, and
   the order note says to look for the customer in the Rewloy panel;
5. **one e-mail, one card, per shop**: an address that another order of this shop
   already invited (`issued` or `unknown`) is not invited again (`exists`, with a
   note naming that order). Only this plugin's own invitations are known to it:
   a card the customer got elsewhere is invisible to it, because the API cannot say.

The `Idempotency-Key` (`woo-<10 hex of the home URL's sha1>-<order id>`, under the
API's 64 characters) is sent anyway: it is what the brief and the API's conventions
ask, it costs nothing, and it starts to protect the moment the platform honours it.

A *clear refusal* (a 4xx: Rewloy said no, nothing was opened) is `failed`, final
too, but its claims are let go, and the order screen offers "Rewloy: try opening the
card again", which reads the order afresh, clears the state and runs once more. It is
offered for `failed` only, never for `unknown`, and only to `manage_woocommerce` (the
order screen itself needs less, so the action checks it).

**D2. The API key's permissions.** *(Superseded in 0.2.0 by D31: `shops.manage` replaces `apikeys.manage`, and the E-ticaret role is exactly enough.)* The calls need `programs.read` (the card list),
`settings.read` (the link and its orders), `apikeys.manage` (create, pause, delete
a link: "a shop link has an API key's power") and `passes.issue`. The key screen and
the 403 message say so. The standard "Yönetici" role does not include
`apikeys.manage`, so the key must be made with a role that has it.

**D3. KVKK at checkout is a notice, not a consent box.** `issuePass`'s description
says the e-mail needs `kvkkConsent: true`, "the business's declaration that it
presented its own notice to the customer ... do not ask it as a consent box". The
join form shows the controller note ("Veri sorumlusu: ...") as information. So the
checkout box is the *invitation* ("open the card for me"), unticked and never
required, and the notice sits beside it: the join form's own controller note, in the
shop's name, with the link to the notice's card-holder part
(`rewloy.com/gizlilik#kart-sahipleri`). `kvkkConsent: true` states that the notice
was shown, which it was, in the same breath as the box. The controller's name is a
setting (default: the site name); the contact e-mail is optional and never defaults
to the admin address.

**D4. The card link is e-mailed, not stored.** `issuePass` returns `cardUrl`, the
customer's private link carrying the viewing key (`?k=`); "give it to the customer,
do not write it to records". The plugin mails it to the billing address with
`wp_mail` and keeps only the serial (the card's public id) on the order and in its
note. If the mail cannot be sent the note says so; the link is gone and the card is
found in the Rewloy panel. The platform does not mail an API-issued link itself.

**D5. Only the e-mail goes to `issuePass`.** No name, phone or address, though the
API accepts them. Data minimisation: the card works with an e-mail.

**D6. The webhook body is cut down.** WooCommerce's `order.updated` payload carries
names, addresses, phone numbers and line items. `handleOrder` reads only `id`,
`status`, `currency`, `total` and `billing.email`, and it returns on an unpaid order
before it reads the e-mail. The plugin hooks `woocommerce_webhook_payload` and, for its
own webhook only (matched by id), sends exactly those fields (plus `number`), with the
billing e-mail **blank unless the status is `processing` or `completed`**. The
signature is computed over what is sent.
This is more than the brief asked for and it makes the privacy section true: "nothing
else of the order". It depends on that filter's signature (`$payload, $resource,
$resource_id, $webhook_id`), which a real WooCommerce 11.1 was seen to honour
(docs/VERIFIED.md, check 3).

The values are read **from the order itself** (`wc_get_order`), not from the
payload WooCommerce built. WooCommerce builds that payload as the webhook's user; when
that user was deleted or lost the right to read orders, the payload is a REST error
array. Trimming that would have sent Rewloy an empty order, which it answers
with 200 "ignored": no failure anywhere, the webhook "active", and no order ever
credited (seen on the real WooCommerce, fixed). Only when the order cannot be
loaded does the trim fall back to the payload.

## The plugin

**D7. Where the settings live: WooCommerce › Rewloy (a submenu), not a tab of
WooCommerce › Settings.** The screen has independent actions (save the key, connect,
pause, disconnect, forget, reactivate, save options), each with its own nonce and its
own result. A WooCommerce settings tab is one form with one Save button and would
force them into one submit. Capability `manage_woocommerce`; a "Settings" link sits on
the plugin row. Every action is a POST: a nonce in a link (a GET) never runs one.

**D8. Name, slug, text domain**: `rewloy-for-woocommerce`, as the brief says.
Namespace `Rewloy\WooCommerce`, a tiny PSR-4 loader (`src/autoload.php`); no Composer
at runtime.

**D9. The key.** *(Amended in 0.2.0 by D27 and D28: the key now usually comes from a connect code, the API-key path is the advanced one, and a test key's mask shows `rwk_test_` and its prefix.)* Its own option `rewloy_wc_api_key`, written with autoload off; the
settings (no secret) are another option. A `REWLOY_API_KEY` constant wins and then
nothing is stored. A pasted key is saved only after Rewloy accepts it (a harmless
`listPrograms` read), so a typo is caught at once and a refused key is never kept. It
must look like `rwk_...`; a staff session (`rws_`) is refused with a clear message. The
mask is `rwk_` plus the ten characters Rewloy itself lists as the key's public prefix
(API.md), then dots: it identifies the key without showing any of the secret. The key
is in no message, note, log line, exception or `__debugInfo` (a test pins that). The
key cannot be changed while connected (disconnect first), so a link is never left
pointing at a key that cannot manage it.

**D10. The rule.** As in the panel: for stamp and points cards `rule` (`order` or
`amount`), the amount threshold (typed as `99,50` or `100`, converted to kuruş, 1 to
100,000) and `step` (1 to 100); for a VIP card the rule is not sent (a visit per
order) and for a cashback card the card's own rate applies, so the form says that and
the server ignores `rule`/`step` for them. Only active stamp, points, VIP and cashback
cards are offered (the other types cannot be linked).

**D11. Connect.** *(Amended in 0.2.0: D27 adds the connect code. Every safeguard below holds for both ways in.)* `listPrograms` (to check the card and take its `joinUrl`), then
`createShop` (platform `woocommerce`), then the WooCommerce webhook with the returned
address and secret: topic `order.updated`, API version `wp_api_v3`, status active,
the connecting user as its user. Safeguards:

- the delivery address must be `https`, on the API's own host, with the path
  `/hooks/store/<this link's id>` (no user info, port, query or fragment); otherwise no
  webhook (so the secret goes nowhere) and the link is deleted again;
- if WooCommerce cannot save the webhook the link is deleted again, since the secret
  was shown once and cannot be recreated;
- the secret lives only inside the WooCommerce webhook, in no option of ours;
- ids that go into API paths must be UUIDs;
- one connect at a time: a lock (with a two-minute takeover, so a dead process cannot
  block it for good) stops a double click from making two links and two webhooks, the
  first of which would be orphaned and keep sending full orders;
- the stored webhook id is trusted only if that webhook's delivery address names our
  link (`Webhooks::owned`): health, reactivate and delete never touch another webhook,
  whatever id is stored.

**D12. The client's retry rules** *(Amended in 0.2.0 by D29: a POST that carries an `Idempotency-Key` is retried, twice. "A POST is never retried" below holds for every other POST.)* follow rewloy-php's list: network errors, 429,
502-504, 520-524 (and 409 `IDEMPOTENCY_IN_PROGRESS`), up to two retries with jittered
backoff, a `Retry-After` longer than 10 s not waited for (rewloy-php waits up to 60;
this runs inside an admin request), and **one retry, not two** (a screen makes two
reads, so two retries could hold a page for over a minute; timeout 10 s, answers read
up to 1 MB). Only requests that are safe to repeat are
retried: GET, PUT, DELETE and PATCH (here PATCH only sets `enabled`, so repeating it
changes nothing more). **A POST is never retried, even with an `Idempotency-Key`**,
unlike rewloy-php: the key does not protect `issuePass` or `createShop` on the server
today (D1). Redirects are not followed, so the key can never go to
another host. HTTPS is required (plain HTTP only for localhost); a `REWLOY_API_URL`
constant can point at a staging origin.

**D13. Pause** is `setShopEnabled` only; the WooCommerce webhook stays active, because
the platform answers a paused link with 200 on purpose (WooCommerce would otherwise
disable the webhook after repeated failures). **Disconnect** deletes the link in
Rewloy first and then the webhook and the local state. "Already gone" (404
`SHOP_NOT_FOUND`) counts as done; any other failure keeps the connection so a link is
never orphaned in Rewloy. If Rewloy cannot be reached (or the key is gone) a separate
"forget on this site only" removes the webhook and the local state without an API call
and says the link remains in Rewloy.

**D14. Health.** *(Extended in 0.2.0 by D32: the last request and the last refused request.)* The screen reads `getShop` and `listShopOrders` (the last 10) on each
load and the WooCommerce webhook's status and failure count, in the panel's words
(`Karta işlendi`, `Kartı yok`, `Eşiğin altında`, `Bağlantı kapalıyken`, `Başka para
birimi`, with the panel's explanations). A webhook WooCommerce disabled can be turned
on again with one button (failure count reset). A *deleted* webhook cannot be rebuilt:
the secret is only shown at creation, so the screen says to disconnect and connect
again. A failed API call is shown as an error without breaking the page.

**D15. Issuing runs off the payment request** *(and, since 0.2.0, an unclear answer is asked again by further scheduled actions: D29)*, through Action Scheduler (bundled with
WooCommerce) with `as_enqueue_async_action(..., unique = true)`: a slow Rewloy must not
hold a payment gateway's callback. If the function is missing the issue runs inline.
The hooks are `woocommerce_order_status_processing` and `..._completed` only (the two
paid states); nothing else triggers it. Switching the invitation off or disconnecting
before the action runs stops it.

**D16. Block checkout.** Declared compatible (`cart_checkout_blocks`). The box is
registered through WooCommerce's additional checkout fields API
(`woocommerce_register_additional_checkout_field`, WooCommerce 8.9+) as an optional
checkbox; its label carries the notice as plain text (the field API's label is plain)
plus the notice's address. Below 8.9 the block checkout has no box, and the settings
screen says so. The classic checkout's box uses `woocommerce_after_order_notes` (not
inside `#payment`, which WooCommerce re-renders and would clear the tick).

**D17. My Account.** The endpoint is `loyalty-card`, titled "My loyalty card" /
"Sadakat kartım". It shows a text, a button to `https://rewloy.com/cuzdan/` and the
programme's join link (taken from `listPrograms.joinUrl` when connecting, kept only if
it is `https` on `rewloy.com`). It reads nothing about the user, calls nothing and
shows no card data (tests assert no HTTP function is called and a logged-in user's
e-mail and name never appear). The rewrite rules are flushed once after the tab is
turned on or off.

**D18. Uninstall** removes the plugin's options, its per-order claim rows, its flash
transients, its scheduled actions and its webhook (only if the webhook's delivery
address names our link, so a reused id never deletes someone else's), per site on a
network. It never calls Rewloy. It **keeps the invitation state on orders**: it is
the shop's own record, and it is what stops a card being opened twice if the plugin is
installed again. The settings screen says that nothing in Rewloy is deleted.

**D19. Language.** English in the code, Turkish in `languages/` (`.pot`, `.po`, `.mo`
all committed, so a GitHub zip works without a build). The Turkish follows the panel's
`views/shops.ts`: its outcome labels and explanations are used word for word, its
rule wording is kept (without a Turkish suffix after an amount, which a variable
cannot take). `bin/make-pot` (xgettext) and `bin/make-mo` (msgfmt) rebuild them; CI
checks the catalogue covers exactly what the code says; tests check every message is
translated, every placeholder survives, and the compiled `.mo` says what the `.po`
says.

**D20. Tests.** PHPUnit 10.5 (it still runs on PHP 8.1) with Brain Monkey (maintained,
works with PHPUnit 10; WP_Mock is not). WordPress functions are stubbed in
`tests/TestCase.php` over an in-memory options table; the few WooCommerce classes
(`WC_Order`, `WC_Webhook`, `FeaturesUtil`) are small doubles in `tests/`. The Client
is given a scripted transport: no test touches the network. PHPStan level 8 runs over
the plugin with `szepeviktor/phpstan-wordpress` and `php-stubs/woocommerce-stubs`
(the tests and doubles are not analysed: the doubles stand in for what the stubs
describe). Static tests pin what the brief demands: every file guards against direct
access, no network function outside the Client, no `eval`/`exec`-like call, only
rewloy.com hosts in the code, no `get_post_meta`-style call (HPOS), every
translatable string uses the text domain. 0.2.0: 295 tests (199 in 0.1.0). The new ones are
in `ConnectionTest` (the code flow, its undo and its unclear outcomes, the key check),
`ClientTest` and `RetryTest` (the keyless client, keyed retries, replay), `IssuerTest` (the
schedule, the fingerprint, the window, `resend`; the Action Scheduler calls in a separate
process), `WebhooksTest` (`redeliver`), `AdminTest` (the code screen, the health rows,
escaping), `MessagesTest` and the I18n tests (the panel's own words). The time a repeat may go
is moved by editing the order's record (`itIsTime`), never by sleeping.

**D21. Packaging.** `bin/build-zip` copies an allowlist (main file, `uninstall.php`,
`readme.txt`, `LICENSE`, `src/`, `languages/`) into a folder named after the slug, so
tests, `vendor/`, docs, CI and tooling cannot leak in; it refuses to build if
`readme.txt`'s Stable tag differs from the plugin's version. A PHPUnit test builds it
and checks the contents.

**D22. Requirements as headers**: WordPress 6.4, PHP 8.1, `WC requires at least: 8.0`,
`Requires Plugins: woocommerce` (WordPress 6.5+ enforces it; older versions ignore it
and the plugin shows a notice if WooCommerce is missing). `Tested up to` (7.1, WordPress 7.1.2) and
`WC tested up to` (11.1, WooCommerce 11.1.2) are what the real run in docs/VERIFIED.md used;
the lower bounds (6.4, 8.0) are not tested.

## Known limits

- ~~**The order that earns the invitation may be recorded "Kartı yok".**~~ **Resolved in
  0.2.0 (D30).** The card is opened by one scheduled action and the order is delivered by
  the webhook's own; whichever ran first won, and the order was recorded without a card.
  Now `issuePass` names the order, Rewloy reopens it, and the plugin delivers it again.
- **One card per e-mail per shop is enforced only against this plugin's own
  invitations** (D1.5, still true: Rewloy opens a card for every call with a new key).
- **The invitation e-mails whatever address the buyer types**, and states that the
  notice was shown. A third party's address can so be given one card (once per address)
  and one e-mail from the shop. It is the same unverified-address weakness the My
  Account tab is designed around; a double opt-in (a confirmation link before the card)
  would close it and is not in 0.1.
- **Subscription renewals** may copy the tick to the renewal order; the address is
  already invited, so the guard answers `exists` and nothing more happens.
- ~~**A process that dies between the claim and the state** leaves the claim and no state:
  that order is never invited.~~ **Resolved in 0.2.0 (D29):** there is no claim kept for
  good; the locks expire after two minutes, and the state and the next attempt are written
  before the request.
- **Action Scheduler's table names are cached per process**, so on a network the
  uninstall may clear the scheduled actions of the main site only; the rest finish with
  nothing to do (the invitation is off, the options are gone).
- **A delivered order's e-mail is matched exactly as WooCommerce gives it**; nothing
  here normalises it.
- **A refunded or cancelled order** after a card was opened does not close the card;
  that is the business's call.
- **If Action Scheduler never runs the action**, the card is not opened and the order
  shows no note. WooCommerce's own scheduled actions would be stuck too.

## Verified on a real WordPress, and what is not

Run in a real WordPress 7.1.2 with WooCommerce 11.1.2 (PHP 8.3, MariaDB, HPOS on and
off) against a local fake of the Rewloy endpoints: **docs/VERIFIED.md** has each check,
what was seen and the defects it found. The fixes it led to:

**D23. The webhook body is built from the order**, not from WooCommerce's REST payload (see D6).

**D24. "Has this address been invited?" lists the address's orders and reads each state
in PHP; it does not use a `meta_query`.** WooCommerce's legacy post-based order store
(still the store of many shops, HPOS off) ignores `meta_query` and only notes it as
unsupported. Every other order of the address then counted as "invited", so a returning
customer whose first order had no invitation was told "exists" and got no card (seen on
the real WooCommerce, fixed). The search reads at most 10 pages of 50 orders of one
address, newest first; past that the permanent e-mail claim is the guard.

**D25. Connecting and disconnecting flush the rewrite rules when the My Account tab is
on.** The tab exists only while connected, so a disconnect, any later flush (saving the
permalinks) and a new connect would have left the tab in the menu and its address a 404.

**D26. The My Account tab has no heading of its own.** WooCommerce already shows the
endpoint's title ("Sadakat kartım") at the top of the page; a second heading repeated it.

## 0.2.0: what the platform now offers (Rewloy ADR 174)

**D27. The way in is a connect code, not a pasted key.** A person in the Rewloy panel
(E-ticaret › Mağaza bağla › WooCommerce › "Rewloy eklentisiyle") chooses the card and the
rule and gets `rwc_…`, shown once, good for 15 minutes. The Connect screen has one field for
it. `Connection::connect_with_code` spends it with `POST /v1/shops/connect` (no credential:
the code is one; `Client::anonymous()` is a client that has no key and refuses every other
call) and takes from the one answer the link, its secret and an API key bound to that link
(role E-ticaret, that link only). Then, in this order: the webhook is made with the secret;
the key goes into its own option (not autoloaded, as D9); the link, the card and the rule
the code chose go into the settings; the join link for the My Account tab is asked of
Rewloy with the new key (best effort: without it the tab only omits the link). The secret is
kept nowhere but in the webhook, and the key is never shown back, only masked.

- **A code is spent by the first answer**, and the answer is shown once. So `connectShop` is
  never retried (the second try gets `404 CONNECT_TOKEN_INVALID`). With no clear answer the
  message says the code may or may not be spent, to try it once more, and, if Rewloy then
  calls it invalid, to delete this shop's link in the panel and make a new code: that is what
  Rewloy's API.md tells an integration to do.
- **An answer that cannot be used** (a delivery address that is not this link's on the API's
  own host, no secret, a key that is not a key) or **a webhook WooCommerce cannot save**: the
  plugin takes the link back with the key that came back (a bound key may delete its own link,
  and Rewloy revokes the key with it), keeps nothing, and says the code is spent. If even that
  fails, or there is no usable key to try it with, the message says where to delete the link.
  The same truthfulness now applies to the advanced path ("removed again" is said only when
  Rewloy confirmed it).
- **`via`** in the settings records how the shop was connected (`code` or `key`). Disconnecting
  a `code` connection asks Rewloy with the plugin's key, which Rewloy revokes with the link, and
  then drops it; forgetting the connection on this site only drops it too. A key made by hand
  stays, as before.
- A code is refused, without being sent, when `REWLOY_API_KEY` is defined (that key would win
  over the one the code makes) or an API key is saved (it would be overwritten).
- The site's title goes with the code (`shopName`, at most 40 characters, control characters
  stripped), so the key reads "WooCommerce · <shop>" in the panel's key list. It is the only new
  datum that goes to Rewloy; the privacy text on the screen and in readme.txt names it.
- **The Connect form is a password field**, like the key's: a code is a credential for fifteen
  minutes. It is never echoed, not in a message, a flash or an exception (tests pin that).

**D28. The API-key path stays, as an advanced option.** It is inside a `<details>` under the
code field, and the screen says why it exists: *use it when nobody can make a code*. It is
sensible because (1) a code is single-use, lasts 15 minutes and needs a person signed in to
the panel with `apikeys.manage`, `team.manage` and the account password, so a site set up
from a script (WP-CLI, deployment tooling) or a staging copy cannot use one; (2) a key
defined as `REWLOY_API_KEY` in wp-config.php is how such sites already keep their secrets,
and 0.1 promised it; (3) a business may want one key for several environments. What it costs
is what the code removes: the key is the person's own and may be broad. So it is narrowed
where it can be:

- the key is checked with `GET /v1/me`, which says what it may do, instead of a `listPrograms`
  read; a missing permission (`programs.read`, `shops.read` or `settings.read`, `shops.manage`
  or `apikeys.manage`, `passes.issue`) is named and the key is not saved; a key that holds more
  is saved with a sentence that the E-ticaret role is enough and a code needs no key at all;
- a key bound to a shop link of its own (`key.shopId`) is refused: it cannot make another;
- the screen and the 403 message ask for the E-ticaret role, not for a key that manages keys.

A constant key is not checked when it is defined (nothing runs then), so its shortfall shows
at `createShop` as a 403 in the same words.

**D29. Once per order rests on the platform's idempotency.** (Supersedes D1's points 1 to 4
and D12's POST rule; point 5 stays.) Every `issuePass` carries the order's key
(`woo-<site>-<order id>`, as before), its `orderId` (the same `id` the webhook sends) and the
link's `shopId`. Rewloy replays the first answer for the same key and body for seven days,
namespaced per credential, and answers the same key with another body `422
IDEMPOTENCY_KEY_REUSED`. So:

- **A repeat is safe, and is made.** `Retry::retries_for`: a POST with a key gets two retries
  (a background action is not an admin page; D12's one retry was for pages); any other POST
  none. The failures retried are the same as before (D12), `IDEMPOTENCY_IN_PROGRESS` among them;
  after the retries `RewloyException::outcome_unknown()` now counts that one as unclear (the
  first request may still open a card), where 0.1 would have called any other 4xx a refusal.
- **An unclear answer is asked again later, with the same key.** The state stays `unknown`
  (no longer final) and the first request's record is written to the order before the request:
  when (`at`), how many times (`n`), when the next may go (`next`) and a fingerprint (`sig`) of
  the body and of the key's own hash (`wp_hash`, never the key). The next attempt is scheduled
  before the request, so a process that dies mid-request (an Action Scheduler time limit) leaves
  its own repeat on the way; success or a refusal cancels it. Delays 5 minutes, an hour, six
  hours (four attempts); then the note says to use the order action, which a person may press
  at any time inside six days. When the repeat arrives, the card is mailed then: in 0.1 the
  link of an unclear answer was lost for good.
- **A repeat that is not the same request is not sent.** The e-mail was edited, the shop was
  connected again (another link, so another body, and another key, so another namespace), the
  card was changed, the key was replaced: the fingerprint differs, and the plugin writes a note
  and stops (code `CHANGED`) rather than let Rewloy refuse it or, under another key, open a
  second card. A `422 IDEMPOTENCY_KEY_REUSED` is treated the same way (a card may be open under
  that key), never as "failed", so the retry action cannot loop on it. After six days (Rewloy
  keeps a key seven) a repeat is refused too, and an `unknown` order with no record (an erased
  meta) is never repeated. The note says to look for the customer in the Rewloy panel.
- **What is left of the claim and lock machinery**, and why each piece is: (a) a lock per order
  and per address, from the same `INSERT IGNORE` row, that **expires after two minutes** (the
  longest run is three attempts of ten seconds), is taken before the work and let go in a
  `finally`; two processes must not both mail the link, and Rewloy cannot see our mail. The
  address is locked *before* it is searched (0.1 searched first: with a lock that is let go, an
  order that finished between the two would have been missed); (b) the state on the order, the
  first thing every run reads; (c) the card's serial, kept beside the state, so that an erased
  state does not make a settled order look new; (d) the rule of one card per e-mail per shop,
  from the orders' states (D24), because Rewloy opens a card for every call with a new key.
  Gone: the per-order and per-address claims **kept for good**, `unknown` as a final state, and
  "never retried".
- **What this costs, plainly.** With the state and the serial both erased, a run goes out with
  the same key: inside seven days Rewloy answers with the first card and the link is mailed a
  second time (same card); after seven days it would open a second card. 0.1's permanent claim
  would have stopped that. The order's own meta is the shop's record (D18), and an erase of it is
  not something the plugin defends against any more. The sibling case (two orders of one
  address in the same moment, one turned away by the address lock) still ends `exists`, as in
  0.1, even if the first then fails; it is a window of seconds.

A reply is also kept honest in the order note: if Rewloy says `Idempotent-Replayed: true` the
note says the card was the one an earlier request of the order had opened.

*Found by the run against the real Rewloy (VERIFIED.md, 0.2.0), and corrected:* the repeat was
first scheduled with Action Scheduler's `unique` flag. That flag counts an action that is *running*
as the same action, and the first attempt always runs inside the async action of the same hook
and arguments, so the repeat was silently never scheduled while the note said it would be. It is
now a plain single action, skipped only when a pending one already waits for about that time or
later (`as_get_scheduled_actions`), and the note promises the repeat only when Action Scheduler
returned an action id.

**D30. `order.result: "resend"` delivers the order again through WooCommerce's webhook.**
(Closes the known limit "Kartı yok".) The answer's `order` says what became of the order:
`waiting` (the webhook has not come; it will find this card), `recorded` (settled; nothing
changes), `resend` (the order's webhook reached Rewloy first and was recorded without a card;
the card has reopened it, and a signed delivery within seven days is credited from its own
body). On `resend` the plugin calls `Webhooks::redeliver`, which hands the order to
`WC_Webhook::process( $order_id )` of the webhook that is ours (its address names our link)
and active.

- **Why `process`.** It is the entry point a real order update uses: it queues the delivery in
  WooCommerce's own queue, signs it with the same secret, and builds the body through the same
  `woocommerce_webhook_payload` filter, so the delivery is the cut-down body of D6 and nothing
  more. `deliver()` would run in our request and hold the queue worker for the webhook's
  timeout; building the body and the signature ourselves would repeat what WooCommerce does.
- **It reports "handed over", not "will arrive":** `process` returns its argument and
  WooCommerce may silently decline an order it finds invalid. The note says "queued to be sent
  again; it counts toward the card once that delivery arrives". If the webhook is missing, off
  or throws, the card is still recorded and mailed, and the note says the order could not be
  sent again and what to do inside the seven days: turn the webhook on and save the order
  again (which is itself an `order.updated`).
- Only a `resend` from Rewloy triggers it. The plugin does not re-deliver on a guess.
- A replayed answer carries the first call's `order.result`; if the first process died after
  the card but before the delivery, the repeat delivers it.

**D31. Permissions: `shops.manage`, and the E-ticaret role.** (Supersedes D2.) Rewloy added
`shops.manage` ("Mağaza bağlantısı yönetimi") for creating, pausing and deleting links and
`shops.read` for reading them, and a preset role "E-ticaret" of exactly `programs.read`,
`shops.read`, `shops.manage` and `passes.issue`. The plugin needs those four, so the key no
longer has to manage API keys, and the standard "Yönetici" role (which has neither) is no
longer the obstacle D2 described. The old names (`apikeys.manage`, `settings.read`) are still
accepted by Rewloy and by the plugin's check. The connect code's bound key has this role for
the link's card only.

**D32. Health, in the panel's words.** `getShop` now carries `lastDelivery` (`{at, result}`:
when the last *signed* request came and what became of it), `lastRefusal` (`{at, reason:
bad_signature}`: the last request whose signature did not match, at most one a minute) and
`pluginKey` (`{id, prefix, name}`). The connected screen shows them, read from
`src/views/shops.ts` word for word, in the Turkish:

- *Mağazadan son istek*: the time and the result: *Karta işlendi*, *Kartı yok*, *Eşiğin
  altında*, *Bağlantı kapalıyken*, *Başka para birimi*, and the four that are not outcomes of an
  order, *Kayıtlı siparişin tekrarı*, *Ödenmemiş sipariş (kaydedilmedi)*, *Sipariş numarası
  yok*, *Okunamadı*. With none yet: *Mağazadan henüz imzalı bir istek gelmedi.* A result Rewloy
  adds later is shown as it is (escaped), not hidden;
- *Reddedilen son istek*: the time and *İmza tutmadı*, with the panel's hint (an unsigned request
  came, was refused, and no order was affected; if it keeps appearing, look at the webhook in
  the shop);
- *Rewloy'daki anahtar*: the key's name and its ten public characters, so the person can find it
  in the panel's list of keys.

The panel's other health message, "no request for 24 hours; the connect answer may have been
lost", is **not** copied: in the plugin that case cannot be a connected shop. A lost connect
answer leaves the plugin *not* connected, with an error that says what to do (D27). A quiet
shop with no paid order for a day is not a fault, and the screen does not say it is.

**D33. A test environment's key is said to be one.** Rewloy's test keys start `rwk_test_`; a
connect code made in a test environment returns one. The screen then says so: cards and orders
are not real and nothing reaches customers *from Rewloy*. It also says what Rewloy cannot
control: the plugin still e-mails the card link to the address typed at checkout (WordPress
sends that mail, not Rewloy), so test orders only. The mode is read from the key's prefix,
not stored: nothing to go stale, and Rewloy refuses a key whose prefix was edited
(`KEY_MODE_MISMATCH`).

**D34. Upgrading from 0.1.0 needs no migration.** 0.1.0 was never published. A 0.1 connection
(a key, `via` unset) reads as a key connection: disconnecting keeps the key, as 0.1 did. The
permanent claim rows 0.1 left are plain locks now and are taken over once older than two
minutes (uninstall still removes every row with the prefix). An order 0.1 left `unknown` has no
record of its request, so 0.2 never repeats it, which is 0.1's own promise kept.

**D35. A connect-code key Rewloy no longer accepts means the link is gone.** (Found by the run
against the real Rewloy, VERIFIED.md.) Rewloy revokes the key a code made together with its link,
so when the link is deleted in the panel the key stops working and the plugin cannot even delete
the link it no longer has. The screen used to say "check that the key is complete", and so did
the removal; the one button that works (forget the connection on this site only) stayed folded.
For a connection made by a code (`via = code`) a refused key (`INVALID_API_KEY`) now says the
link was probably deleted in the panel and names that button, in the health message and in the
failed removal, and the fold that holds the button is open. A key made by hand keeps the plain
message: it has no tie to the link, and a refusal there may be anything. Nothing is forgotten
automatically: the same refusal also fits a key revoked on its own while the link stays, and then
the person must delete the link in the panel.

**What the platform could still add** (for the next brief): `listShopOrders` filterable by
`orderId` (or an `order` in `getPass`), so the plugin could check an `unknown` order against
Rewloy before repeating it and not only trust the key; a way to read a connect answer again
for a few minutes with the same code and the same `shopName` (a replay, as `issuePass` has), so
a lost answer would not cost a link and a new code; and replay for longer than seven days, or a
way to ask whether a key is still remembered.

## Still not verified

- The real Rewloy was run locally for 0.2.0 (docs/VERIFIED.md): spending a code, the key, replay,
  `order.result`, resend, health, the test environment. What stays unverified there: Rewloy's
  production host (`https://app.rewloy.com`, a run cannot reach it without a real code), the real
  card links (the local Rewloy was told its public origin is `https://rewloy.com` so that the plugin's
  link allowlist accepted them), and Rewloy's behaviour after seven days (the replay window).
- WordPress below 7.1 and WooCommerce below 11.1 (the declared minimums are 6.4 and 8.0);
  PHP 8.1 at run time (the code is only syntax-checked there); multisite.
- A real mail transport (the runs caught `wp_mail` calls). Action Scheduler ran from WP-Cron (about
  a minute after an order) and from WP-CLI; its admin-ajax async runner did not fire for the
  front-end checkout requests and was not exercised.
- Browsers other than the embedded Chromium; locales other than `tr_TR` and `en_US`.
