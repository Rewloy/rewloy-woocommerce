=== Rewloy for WooCommerce ===
Contributors: rewloy
Tags: loyalty, rewards, woocommerce, cashback, wallet
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.4.0
License: MIT
License URI: https://opensource.org/licenses/MIT
WC requires at least: 8.0
WC tested up to: 11.1

Paid orders fill Rewloy loyalty cards; customers pay with a Rewloy card at checkout; a small Rewloy panel shows the card, its activity and a till.

== Description ==

**English**

[Rewloy](https://rewloy.com) puts a business's digital loyalty cards on the customer's phone: stamps, points, VIP and cashback cards, in Apple Wallet, Google Wallet and Rewloy Cüzdan. At the till a QR code is scanned. This plugin does the same for your online shop: a paid order fills the card.

* **A "Rewloy" menu in WordPress (0.3.0).** *Overview*: the connected card, the business, the link's health, the last orders and the card's numbers for the last 30 days (open cards, cards given, visits, rewards used). *Cards*: the latest activity on customers' cards (what, when, where; a card by its last four characters), refreshed every 30 seconds, and a card lookup by number. *Till*: read a card by its number or a USB/Bluetooth scanner, record a sale, use the card's rewards and balance, at one branch. *Settings*: the connection and options, as before (WooCommerce › Rewloy still opens it). What the plugin may do is chosen in the Rewloy panel ("Görüntüleme" on by default, the till off by default) and changed there at any time; creating and designing cards, campaigns, the team and billing stay in the Rewloy panel, one link away. No customer's name, e-mail or phone is shown in WordPress.
* **Connect in one step, with a code.** In the Rewloy panel you choose the card and the rule and get a one-time code (good for 15 minutes); paste it under Rewloy › Settings. The plugin makes the link in Rewloy and the WooCommerce webhook itself, and keeps an API key that can work with this shop's link only. You need no API key of your own. (An API key can still be used, as an advanced option, for sites set up from a script.)
* **Health on the screen.** The last request from the shop and what became of it, the last request Rewloy refused for its signature, the webhook's status and failures, and the last orders with their outcomes, in the Rewloy panel's own words.
* **Rules.** For stamp and points cards: per order, or per amount of the order total. A VIP card counts one visit per paid order; a cashback card uses its own rate (the screen shows the rate when Rewloy lists it). The rule fields apply to stamp and points cards only; the screen says what a VIP or cashback card does instead. Gift cards, coupons and discount cards are given by code and cannot be linked to a shop.
* **Only paid orders** (*processing* or *completed*), each once, on the card the buyer already has (matched by the order's e-mail). A card is never opened by an order alone.
* **Rewloy cards at the checkout (0.4.0).** A customer makes a one-time code on their card (`RW-XXXX-XXXX`, on the card's page or in Rewloy Cüzdan) and types it into the coupon field of the classic or the block checkout. A cashback card takes its value off the order, a gift card pays part of it, a discount card gives its percent, a coupon its online value, and a stamp, points or VIP card ties the order to that card so it earns there. The value is held when the order is placed (no payment is taken without the hold), taken when the order is paid, given back when it is cancelled or fails, and put back on a full refund; every step is in the order notes. Each choice is a setting with a default under Rewloy › Settings (administrators only): which of the business's other cards the shop takes, the tax treatment per kind (a gift card as a payment after tax by default, cashback and coupons as a discount before tax: ask your accountant), what a refunded order takes back, and how long an unpaid order may hold a card's value (7 days by default).
* **Pause and resume**, or remove the connection, from the same screen.
* **Invitation at checkout (optional, off by default).** An unticked box, with the privacy notice beside it. When the order is paid and the box was ticked, one card is opened for the billing e-mail and its private link is e-mailed to the buyer. The order that earned the card counts toward it, whichever of the two reaches Rewloy first.
* **My Account › "Sadakat kartım" (optional, off by default).** A short text, a button to Rewloy Cüzdan and your join link. It never shows card data and makes no call to Rewloy: WooCommerce does not verify that an account's e-mail belongs to the person, so a card must not be shown by e-mail.
* Works with High-Performance Order Storage (HPOS) and with the block checkout (the invitation box needs WooCommerce 8.9 or newer there).
* Turkish and English. MIT licensed, no tracking, no remote code.

You need a Rewloy account with the e-commerce feature. The plugin does not create one.

**Türkçe**

[Rewloy](https://rewloy.com), işletmelerin dijital sadakat kartlarını müşterinin telefonuna koyar: damga, puan, VIP ve cashback kartları; Apple Cüzdan, Google Cüzdan ve Rewloy Cüzdan'da. Kasada QR okutulur. Bu eklenti aynısını online mağazanız için yapar: ödenmiş sipariş kartı doldurur.

* **Ödeme adımında Rewloy kartları (0.4.0).** Müşteri kartında tek kullanımlık bir kod oluşturur (`RW-XXXX-XXXX`, kartın sayfasında ya da Rewloy Cüzdan'da) ve klasik ya da blok ödeme sayfasının kupon alanına yazar. Cashback kartı değerini siparişten düşer, hediye kartı bir kısmını öder, indirim kartı yüzdesini, kupon online değerini verir; damga, puan ve VIP kartı siparişi o karta bağlar. Tutar sipariş verilince ayrılır (ayırma olmadan ödeme alınmaz), ödenince düşülür, iptal ya da başarısızlıkta karta döner, tam iadede karta geri yüklenir; her adım sipariş notunda yazar. Her seçim Rewloy › Ayarlar'da varsayılanı olan bir ayardır (yalnız yöneticiler): işletmenin diğer kartlarından hangileri kabul edilir, türe göre vergi (hediye kartı varsayılan olarak vergiden sonra ödeme, cashback ve kupon vergiden önce indirim: muhasebecinize danışın), iade edilen siparişin geri alacağı, ödenmeyen siparişin kart değerini ne kadar tutacağı (varsayılan 7 gün).
* **WordPress'te bir "Rewloy" menüsü (0.3.0).** *Özet*: bağlı kart, işletme, bağlantının sağlığı, son siparişler ve kartın son 30 günlük sayıları (açık kartlar, verilen kartlar, ziyaretler, kullanılan ödüller). *Kartlar*: müşterilerin kartlarındaki son işlemler (ne, ne zaman, nerede; kart son 4 karakteriyle), 30 saniyede bir yenilenir; numarayla kart sorgulama. *Kasa*: kartı numarasıyla ya da USB/Bluetooth okuyucuyla okutma, satış yazma, kartın ödülünü ve bakiyesini kullanma, tek bir şubede. *Ayarlar*: bağlantı ve seçenekler, eskisi gibi (WooCommerce › Rewloy de oraya açılır). Eklentinin yapabilecekleri Rewloy panelinde seçilir ("Görüntüleme" varsayılan açık, Kasa varsayılan kapalı) ve orada her an değişir; kart oluşturmak ve tasarlamak, kampanyalar, ekip ve fatura Rewloy panelinde kalır, bir bağlantı uzakta. WordPress'te hiçbir müşterinin adı, e-postası ya da telefonu gösterilmez.
* **Kodla tek adımda bağlanma.** Rewloy panelinde kartı ve kuralı seçip tek kullanımlık bir kod alırsınız (15 dakika geçerli); Rewloy › Ayarlar ekranına yapıştırırsınız. Rewloy'daki bağlantıyı ve WooCommerce webhook'unu eklenti kurar ve yalnız bu mağazanın bağlantısıyla çalışabilen bir API anahtarı saklar. Kendi API anahtarınıza gerek yok. (API anahtarı, betikle kurulan siteler için gelişmiş bir seçenek olarak durur.)
* **Sağlık ekranda.** Mağazadan gelen son istek ve sonucu, imzası tutmadığı için reddedilen son istek, webhook'un durumu ve başarısız teslimleri, son siparişler ve sonuçları; Rewloy panelinin kendi sözleriyle.
* **Kurallar.** Damga ve puan kartlarında sipariş başına ya da sipariş tutarına göre. VIP kartında her ödenmiş sipariş bir ziyarettir; cashback kartında kartın kendi oranı uygulanır (Rewloy oranı verdiğinde ekran gösterir). Kural alanları yalnız damga ve puan kartları içindir; VIP ya da cashback kartının ne yaptığını ekran söyler. Hediye kartı, kupon ve indirim kartı kodla verilir, mağazaya bağlanamaz.
* **Yalnız ödenmiş sipariş** (*işleniyor* ya da *tamamlandı*), her biri bir kez, alıcının zaten sahip olduğu karta (siparişin e-postasıyla eşleşir). Yalnız siparişle kart açılmaz.
* Aynı ekrandan **duraklatma ve sürdürme** ya da bağlantıyı kaldırma.
* **Ödeme sayfasında davet (isteğe bağlı, varsayılan kapalı).** İşaretsiz bir kutu ve yanında aydınlatma metni. Sipariş ödendiğinde ve kutu işaretlenmişse fatura e-postası için bir kart açılır; özel bağlantısı alıcıya e-postayla gönderilir. Kartı kazandıran sipariş de karta sayılır; Rewloy'a hangisi önce ulaşırsa ulaşsın.
* **Hesabım › Sadakat kartım (isteğe bağlı, varsayılan kapalı).** Kısa bir metin, Rewloy Cüzdan düğmesi ve katılım bağlantınız. Kart verisi göstermez, Rewloy'a çağrı yapmaz: WooCommerce bir hesabın e-postasının kişiye ait olduğunu doğrulamaz; bu yüzden kart e-postayla gösterilmemelidir.
* Yüksek Performanslı Sipariş Depolaması (HPOS) ve blok ödeme sayfasıyla çalışır (davet kutusu orada WooCommerce 8.9 ve üstünü ister).
* Türkçe ve İngilizce. MIT lisanslı, izleme yok, uzaktan kod yok.

Eklentiyi kullanmak için e-ticaret özelliği olan bir Rewloy hesabı gerekir; eklenti hesap açmaz.

== Installation ==

1. Upload the zip under Plugins › Add New › Upload Plugin, or unzip it into `wp-content/plugins/`. Activate it. WooCommerce must be active.
2. In the Rewloy panel go to E-ticaret › Mağaza bağla › WooCommerce › "Rewloy eklentisiyle". Choose the card and the rule. Rewloy gives a one-time code.
3. Open Rewloy › Settings (or WooCommerce › Rewloy), paste the code and press Connect.
4. Optional: turn on the checkout invitation and the My Account tab, and name the data controller (your business) for the notice.
5. Optional: to use the till, turn it on in the Rewloy panel on the shop link's page under "WordPress yetkileri" and choose its branch.

Advanced, for a site set up from a script, a staging copy or a key kept in wp-config.php: under "Advanced" on the same screen, paste an API key made with the E-ticaret role (or define `REWLOY_API_KEY` in wp-config.php), choose the card and the rule, press Connect.

Kurulum: eklentiyi Eklentiler › Yeni ekle › Eklenti yükle ile yükleyip etkinleştirin (WooCommerce etkin olmalı). Rewloy panelinde E-ticaret › Mağaza bağla › WooCommerce › "Rewloy eklentisiyle" yolundan kartı ve kuralı seçip kodu alın; Rewloy › Ayarlar ekranına yapıştırıp Bağla'ya basın. Kasayı kullanmak için Rewloy panelinde bağlantının sayfasındaki "WordPress yetkileri"nden açıp şubesini seçin. Gelişmiş: betikle kurulan siteler için aynı ekranda "Gelişmiş" altından E-ticaret rolüyle oluşturulmuş bir API anahtarı kullanılabilir.

== Frequently Asked Questions ==

= Do I need an API key? =
No. Connect with the one-time code from the Rewloy panel. Rewloy then gives the plugin a key of its own that can see and manage this shop's link only and issue cards on its card; Rewloy revokes it when the link is deleted. An API key made by hand is an advanced option, for sites that are set up from a script and cannot use a code.

= Is the key shown back to me? =
No. The screen shows only `rwk_` and the ten public characters Rewloy itself lists, then dots. The key is stored in its own option, not loaded on every request. The connect code is never shown or stored. If you define `REWLOY_API_KEY` in wp-config.php, nothing is stored.

= What happens to an order that is not paid, or whose buyer has no card? =
Nothing is added. Rewloy records the order's number and outcome ("No card", "Below the threshold", and so on) and you see it on the screen. A card is opened only by the optional checkout invitation, and only when the buyer ticked the box.

= Can an order open two cards? =
It is built not to. Every request for an order carries the order's own key, and Rewloy answers a repeat of it with the card it already opened instead of opening another. So when an answer is unclear (a timeout, a server error) the plugin asks again, with the same key: automatically a few times over the next hours, or from the order's action list; the card link is e-mailed when the answer comes. A repeat that would not be the same request (the e-mail was edited, the shop was connected again) or that comes after six days is not sent, and the order note says to look in the Rewloy panel. Rewloy does not keep one e-mail to one card, so the plugin does: one e-mail address is invited once from this shop. A card the customer got somewhere else is not known to the plugin.

= An order was recorded "No card" because it reached Rewloy before the card existed. =
Not any more: Rewloy reopens the order when the card is opened and the plugin delivers the order through its webhook again, within seven days, so it counts toward the card. If the webhook is missing or off, the order note says so and what to do.

= Why is the till off, and how do I turn it on? =
With the till on, anyone who can manage WooCommerce on your site could use customers' rewards and balances at the till's branch. So Rewloy leaves it off until someone with the right to manage the shop link turns it on, with one branch, on the link's page in the Rewloy panel ("WordPress yetkileri"), with a reason and their password. It applies at once; the plugin needs no reconnect. The same place turns "Görüntüleme" (the Overview numbers and the Cards tab) on or off. A shop connected before 0.3.0 has both off until then.

= Does the plugin show my customers' details? =
No. Rewloy sends this plugin's key no customer's name, e-mail or phone, and no teammate's e-mail. A card shows by its last four characters, except on the till right after it is read. "Open the customer in Rewloy" opens the Rewloy panel, where you sign in as usual.

= Who may use the till? =
Administrators. The till spends customers' rewards and balances, so shop managers may use it only when an administrator ticks "Shop managers may use the till too" under Rewloy › Settings. The capability is `rewloy_wc_till`; a role editor can give it to any role, and the `rewloy_wc_till` filter can decide it for a user.

= What if the till gets no answer from Rewloy? =
The press stays pending: the sale and the card's buttons are locked, and "Try again" sends that same press with the same key, so Rewloy never writes it twice, even after a page reload.

= Which scanners work on the till? =
Any USB or Bluetooth scanner that types what it reads and ends with Enter. The card's QR code is a link to the card; the till keeps only the card number from it and drops the rest, the card's private key included. If the scanner and the computer use different keyboard layouts, the till still finds the number in what was typed.

= Why does the My Account tab not show my customer's card? =
WooCommerce does not check that an account's e-mail address belongs to the person who registered it. Showing a card by e-mail would let anyone who signs up with someone else's address see that person's card. The tab sends the customer to Rewloy Cüzdan, where they prove the address with a code.

= What does uninstalling do? =
It removes the plugin's options and its webhook from your site. It does not call Rewloy and does not delete anything there: the link and the cards stay until you delete them in the Rewloy panel. Order notes and the invitation state on orders stay.

= The webhook shows failed deliveries. =
WooCommerce turns a webhook off after repeated failures. Open WooCommerce › Rewloy and press "Turn the webhook on again", then check the webhook's delivery log (the screen links to it).

= Gift cards, coupons, discount cards? =
They are given by code and are not filled by orders, so they cannot be the shop's own card. Since 0.4.0 a customer can use them at the checkout with a one-time code, once the shop takes them (Rewloy › Settings › "Cards this shop takes").

= How does a customer pay with a Rewloy card? =
On the card's page or in Rewloy Cüzdan they tap "Online alışverişte kullan", choose how much to use (a cashback or gift card) and copy the code, `RW-XXXX-XXXX`. They paste it into the coupon field at checkout, within 15 minutes. The line reads "Rewloy: <card>". The code works once, in your shop only.

= What if Rewloy cannot be reached at checkout? =
The code is not applied ("Rewloy cannot be reached right now…"), or, if it was applied, the order is refused before any payment and a release of any hold is asked for. Nothing is ever taken off an order without Rewloy holding it on the card. The customer can remove the code and finish the order without it.

= What happens if the order is never paid? =
A cancelled or failed order gives the value back at once. An order left unpaid gives it back after the hold length (7 days by default, Rewloy › Settings). If such an order is paid later, Rewloy takes the value then if the card still has it; if not, the order note and the Rewloy panel say so.

= Why does a gift card not cover the tax? =
By default a gift card is a payment after tax (the KDV of the goods stays as it is), as a negative line of its own. WooCommerce lets such a line take off at most the order's total before tax, so the tax part is paid another way and the rest of the card's value stays on the card. If your accountant prefers it, switch the gift card to "As a discount" (Rewloy › Settings › Tax).

= Why does a card show as "Card programme …1a2b3c" in the settings? =
Rewloy does not tell the shop's key the names of the business's other cards. A card shows its name once a code of it has been used on an order; the shop's page in the Rewloy panel names them all.

== Privacy ==

The plugin sends data to **Rewloy** (https://rewloy.com), the service this plugin connects your shop to. It does nothing else over the network: no tracking, no analytics, no updates from elsewhere, no remote code.

What is sent, and when:

1. **Connecting** (Rewloy › Settings, administrators only): the one-time code you pasted, and this site's title, which names the key in Rewloy's list of keys. Rewloy answers with the shop link, its secret and an API key that can work with that link only; the plugin keeps the key (not the code) and puts the secret in the webhook. With an API key of your own instead (the advanced way): the key, as the Bearer credential, and the card and rule you chose.
   **Managing:** the key, as the Bearer credential of every call to `https://app.rewloy.com/v1`. Rewloy answers with the link's state, when it last heard from the shop and what became of it, and the last orders' number, outcome and time (no personal data).
2. **Paid orders, by the WooCommerce webhook** this plugin creates: for every order update, the order's number, status, currency and total, and the **billing e-mail** once the order is processing or completed, signed with a secret only your shop and Rewloy hold. Nothing else of the order (no names, addresses, phone numbers or items). Rewloy reads the e-mail only to find the buyer's existing card and the total only to calculate; it stores the order number, its outcome and the time. The privacy notice says the same: https://rewloy.com/gizlilik
3. **Checkout invitation, only if you turn it on and only when the buyer ticks the box:** the order's billing e-mail is sent to Rewloy to open a card (with the statement that the privacy notice was shown), together with the order's number and the shop link's id so that the order counts toward the card. If Rewloy's answer is unclear the same request is sent again, a few times, with the same key. Rewloy's answer carries the card's private link; the plugin e-mails it to the buyer and does not store it. If the order reached Rewloy before the card existed, the plugin has WooCommerce deliver the order through the webhook again (item 2).
4. **The Rewloy menu's Cards and Till tabs (administrators with manage_woocommerce):** the card number typed or scanned (only the number: the rest of a scanned card link, its private key included, is dropped at once), and on the till the paid total and the receipt number typed there, with the till's branch. Rewloy answers with the card's state and the latest activity on customers' cards without anyone's name, e-mail or phone. The browser never talks to Rewloy and never holds the key: WordPress makes these calls.
5. **A Rewloy code at checkout (0.4.0):** the code the customer typed and the basket's currency, to ask Rewloy what it gives, with an opaque hash of the shopper (HMAC-SHA256 of the WooCommerce customer or session id under this site's secret, never the id) so that one shopper's tries do not use up the shop's budget; then the order's number, what the order took from the code and the order's total before discounts, to hold, take, release or refund it. Rewloy answers with the card's programme, kind and last four characters: no name, e-mail or card number. The plugin keeps the answer in the order's meta and notes, never the code (WooCommerce itself stores the code on the order's coupon line).
6. **My Account tab:** nothing is sent.

You, as the shop, are responsible for informing your customers that order e-mails and totals are passed to Rewloy for matching. The box at checkout shows the notice of the join form, in your business's name; you can set the name and a contact e-mail under Rewloy › Settings.

Rewloy's terms: https://rewloy.com/kosullar

Türkçe özet: Eklenti yalnız Rewloy'a veri gönderir (bağlanırken bağlantı kodu ve sitenin başlığı, sonra anahtar; siparişlerin numarası, durumu, para birimi ve tutarı, fatura e-postası ise yalnız sipariş işleniyor ya da tamamlandı olduğunda; davet açıksa ve alıcı kutuyu işaretlediyse fatura e-postası, sipariş numarası ve bağlantı kimliğiyle birlikte). Siparişin ad, adres, telefon ve ürünleri gitmez. Rewloy menüsünün Kartlar ve Kasa sekmeleri yazılan ya da okutulan kart numarasını (okutulan bağlantının geri kalanı, özel anahtarı dahil, hemen atılır), kasada ödenen toplamı ve fiş numarasını gönderir; Rewloy bu eklentiye hiçbir müşterinin adını, e-postasını ya da telefonunu göndermez. Ödeme adımında yazılan bir Rewloy kodu için kod ve sepetin para birimi, alışverişçinin kişisel veri taşımayan bir özeti, sonra sipariş numarası, siparişin koddan aldığı tutar ve indirimden önceki toplamı gider; Rewloy kartın programını, türünü ve son dört karakterini döndürür. Hesabım sekmesi hiçbir şey göndermez. İzleme yoktur. Müşterilerinizi, sipariş e-postası ve tutarının kart eşleştirmesi için Rewloy'a iletildiği konusunda aydınlatmak sizin yükümlülüğünüzdür.

== Changelog ==

= 0.4.0 =
* Rewloy cards at the checkout: a one-time code from the customer's card, typed into the coupon field of the classic or block checkout. Cashback, gift card, discount card and coupon values; a stamp, points or VIP card ties the order to the card. Held when the order is placed, taken when it is paid (before the order is marked paid), released when it is cancelled or fails, put back on a full refund; every step in the order notes. Refused at checkout, with the customer's message, whenever Rewloy refuses or cannot be reached: never a discount without a hold.
* Rewloy › Settings: the checkout codes' settings, each with Rewloy's default and editable by administrators: cards the shop takes, tax per kind, refunded orders, hold length.
* Checked on a real WordPress 7.1.2 / WooCommerce 11.1.2 store against the real Rewloy, classic and block checkout, HPOS on and off (docs/VERIFIED.md). Turkish for every new string.

= 0.3.0 =
* A top-level "Rewloy" menu with four tabs: Overview (the card, the business, the link's health, the last orders, the card's numbers for 30 days), Cards (the latest activity on customers' cards, refreshed every 30 seconds, and a card lookup), Till (read a card, record a sale, use its rewards and balance at one branch) and Settings (the 0.2 screen; WooCommerce › Rewloy still opens it).
* What the plugin may do is Rewloy's to say (`GET /v1/me`): "Görüntüleme" and the till are chosen in the Rewloy panel and change there at once. The till is off unless turned on there, and works at one branch only.
* Exact links to the Rewloy panel for everything done there: creating and designing cards, customers, campaigns, the team, branches, keys, billing.
* No customer's personal data in WordPress; a card number shows by its last four characters except on the till. A scanned card link's private key is dropped at once.
* Every till press carries its own key; a press with no clear answer stays pending, locks the till and is sent again with the same key, so Rewloy never writes it twice; the receipt number goes on the card's record.
* The till is for administrators by default (capability `rewloy_wc_till`); an administrator can open it to shop managers in Settings.
* Turkish for every new string.

= 0.2.2 =
* The connect screen no longer treats every card as a stamp card. It names the card's type, and says what an order does for each: stamp and points cards follow the rule; a VIP card counts one visit per paid order; a cashback card uses its own rate, shown with an example when Rewloy lists it. The rule fields are shown only when a stamp or points card can be chosen, and are labelled as applying to those two only. For a cashback card, "Below the threshold" now says the cashback came to nothing. Turkish for every new string.

= 0.2.1 =
* WordPress Plugin Check: request input is unslashed and sanitised in one step, an exception's text is marked as escaped where it is shown, and the short description fits 150 characters, and the earlier-invitation lookup skips the order itself in its loop instead of with `exclude`. No behaviour changes.

= 0.2.0 =
* Connect with a one-time code made in the Rewloy panel: no API key of your own, and the key the plugin keeps can work with this shop's link only. The API key stays as an advanced option, checked with the new self-check and with the E-ticaret role in mind.
* Once per order on Rewloy's own idempotency: every request carries the order's key, the order and the link; an unclear answer is asked again with the same key (automatically, then from the order's action list) and the card link is e-mailed when it comes. The permanent locks are gone; the one-e-mail-one-card rule stays.
* An order that reached Rewloy before its card is delivered again through its webhook, so it counts toward the card.
* Health on the screen: the last request from the shop and its result, the last refused request, the key Rewloy lists for the link. A test environment's key is said to be one.
* Checked against the real Rewloy: a repeat of an unclear answer is now really scheduled, and a link deleted in the Rewloy panel is explained on the screen with the way out opened.
* Turkish for every new string.

= 0.1.0 =
* First preview: connect with an API key, health and last orders, pause, resume, disconnect; optional checkout invitation; optional My Account tab; Turkish translation.

== Upgrade Notice ==

= 0.4.0 =
Customers can pay with their Rewloy card at checkout with a one-time code. Needs Rewloy with ADR 179; WooCommerce coupons must be on.

= 0.3.0 =
A small Rewloy panel in WordPress: overview, card activity and a till. What it may do is chosen in the Rewloy panel; the till is off until turned on there.

= 0.2.2 =
The connect screen says what a VIP or cashback card does with an order and hides the stamp and points rule when no such card can be chosen; no change to how orders are credited.

= 0.2.1 =
Code-review fixes for WordPress.org; no behaviour changes.

= 0.2.0 =
Connect with a one-time code; once-per-order now rests on Rewloy's idempotency, and an unclear answer is asked again instead of being lost.

= 0.1.0 =
First preview release.
