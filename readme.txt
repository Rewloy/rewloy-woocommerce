=== Rewloy for WooCommerce ===
Contributors: rewloy
Tags: loyalty, rewards, woocommerce, stamp card, wallet
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: MIT
License URI: https://opensource.org/licenses/MIT
WC requires at least: 8.0
WC tested up to: 11.1

Paid WooCommerce orders fill Rewloy loyalty cards. Connect with a one-time code. Optional invitation at checkout. / Ödenmiş siparişler Rewloy sadakat kartlarını doldurur.

== Description ==

**English**

[Rewloy](https://rewloy.com) puts a business's digital loyalty cards on the customer's phone: stamps, points, VIP and cashback cards, in Apple Wallet, Google Wallet and Rewloy Cüzdan. At the till a QR code is scanned. This plugin does the same for your online shop: a paid order fills the card.

* **Connect in one step, with a code.** In the Rewloy panel you choose the card and the rule and get a one-time code (good for 15 minutes); paste it under WooCommerce › Rewloy. The plugin makes the link in Rewloy and the WooCommerce webhook itself, and keeps an API key that can work with this shop's link only. You need no API key of your own. (An API key can still be used, as an advanced option, for sites set up from a script.)
* **Health on the screen.** The last request from the shop and what became of it, the last request Rewloy refused for its signature, the webhook's status and failures, and the last orders with their outcomes, in the Rewloy panel's own words.
* **Rules.** For stamp and points cards: per order, or per amount of the order total. A VIP card counts one visit per paid order; a cashback card uses its own rate.
* **Only paid orders** (*processing* or *completed*), each once, on the card the buyer already has (matched by the order's e-mail). A card is never opened by an order alone.
* **Pause and resume**, or remove the connection, from the same screen.
* **Invitation at checkout (optional, off by default).** An unticked box, with the privacy notice beside it. When the order is paid and the box was ticked, one card is opened for the billing e-mail and its private link is e-mailed to the buyer. The order that earned the card counts toward it, whichever of the two reaches Rewloy first.
* **My Account › "Sadakat kartım" (optional, off by default).** A short text, a button to Rewloy Cüzdan and your join link. It never shows card data and makes no call to Rewloy: WooCommerce does not verify that an account's e-mail belongs to the person, so a card must not be shown by e-mail.
* Works with High-Performance Order Storage (HPOS) and with the block checkout (the invitation box needs WooCommerce 8.9 or newer there).
* Turkish and English. MIT licensed, no tracking, no remote code.

You need a Rewloy account with the e-commerce feature. The plugin does not create one.

**Türkçe**

[Rewloy](https://rewloy.com), işletmelerin dijital sadakat kartlarını müşterinin telefonuna koyar: damga, puan, VIP ve cashback kartları; Apple Cüzdan, Google Cüzdan ve Rewloy Cüzdan'da. Kasada QR okutulur. Bu eklenti aynısını online mağazanız için yapar: ödenmiş sipariş kartı doldurur.

* **Kodla tek adımda bağlanma.** Rewloy panelinde kartı ve kuralı seçip tek kullanımlık bir kod alırsınız (15 dakika geçerli); WooCommerce › Rewloy ekranına yapıştırırsınız. Rewloy'daki bağlantıyı ve WooCommerce webhook'unu eklenti kurar ve yalnız bu mağazanın bağlantısıyla çalışabilen bir API anahtarı saklar. Kendi API anahtarınıza gerek yok. (API anahtarı, betikle kurulan siteler için gelişmiş bir seçenek olarak durur.)
* **Sağlık ekranda.** Mağazadan gelen son istek ve sonucu, imzası tutmadığı için reddedilen son istek, webhook'un durumu ve başarısız teslimleri, son siparişler ve sonuçları; Rewloy panelinin kendi sözleriyle.
* **Kurallar.** Damga ve puan kartlarında sipariş başına ya da sipariş tutarına göre. VIP kartında her ödenmiş sipariş bir ziyarettir; cashback kartında kartın kendi oranı uygulanır.
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
3. Open WooCommerce › Rewloy, paste the code and press Connect.
4. Optional: turn on the checkout invitation and the My Account tab, and name the data controller (your business) for the notice.

Advanced, for a site set up from a script, a staging copy or a key kept in wp-config.php: under "Advanced" on the same screen, paste an API key made with the E-ticaret role (or define `REWLOY_API_KEY` in wp-config.php), choose the card and the rule, press Connect.

Kurulum: eklentiyi Eklentiler › Yeni ekle › Eklenti yükle ile yükleyip etkinleştirin (WooCommerce etkin olmalı). Rewloy panelinde E-ticaret › Mağaza bağla › WooCommerce › "Rewloy eklentisiyle" yolundan kartı ve kuralı seçip kodu alın; WooCommerce › Rewloy ekranına yapıştırıp Bağla'ya basın. Gelişmiş: betikle kurulan siteler için aynı ekranda "Gelişmiş" altından E-ticaret rolüyle oluşturulmuş bir API anahtarı kullanılabilir.

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

= Why does the My Account tab not show my customer's card? =
WooCommerce does not check that an account's e-mail address belongs to the person who registered it. Showing a card by e-mail would let anyone who signs up with someone else's address see that person's card. The tab sends the customer to Rewloy Cüzdan, where they prove the address with a code.

= What does uninstalling do? =
It removes the plugin's options and its webhook from your site. It does not call Rewloy and does not delete anything there: the link and the cards stay until you delete them in the Rewloy panel. Order notes and the invitation state on orders stay.

= The webhook shows failed deliveries. =
WooCommerce turns a webhook off after repeated failures. Open WooCommerce › Rewloy and press "Turn the webhook on again", then check the webhook's delivery log (the screen links to it).

= Gift cards, coupons, discount cards? =
They are given by code and are not filled by orders, so they cannot be connected.

== Privacy ==

The plugin sends data to **Rewloy** (https://rewloy.com), the service this plugin connects your shop to. It does nothing else over the network: no tracking, no analytics, no updates from elsewhere, no remote code.

What is sent, and when:

1. **Connecting** (WooCommerce › Rewloy, administrators only): the one-time code you pasted, and this site's title, which names the key in Rewloy's list of keys. Rewloy answers with the shop link, its secret and an API key that can work with that link only; the plugin keeps the key (not the code) and puts the secret in the webhook. With an API key of your own instead (the advanced way): the key, as the Bearer credential, and the card and rule you chose.
   **Managing:** the key, as the Bearer credential of every call to `https://app.rewloy.com/v1`. Rewloy answers with the link's state, when it last heard from the shop and what became of it, and the last orders' number, outcome and time (no personal data).
2. **Paid orders, by the WooCommerce webhook** this plugin creates: for every order update, the order's number, status, currency and total, and the **billing e-mail** once the order is processing or completed, signed with a secret only your shop and Rewloy hold. Nothing else of the order (no names, addresses, phone numbers or items). Rewloy reads the e-mail only to find the buyer's existing card and the total only to calculate; it stores the order number, its outcome and the time. The privacy notice says the same: https://rewloy.com/gizlilik
3. **Checkout invitation, only if you turn it on and only when the buyer ticks the box:** the order's billing e-mail is sent to Rewloy to open a card (with the statement that the privacy notice was shown), together with the order's number and the shop link's id so that the order counts toward the card. If Rewloy's answer is unclear the same request is sent again, a few times, with the same key. Rewloy's answer carries the card's private link; the plugin e-mails it to the buyer and does not store it. If the order reached Rewloy before the card existed, the plugin has WooCommerce deliver the order through the webhook again (item 2).
4. **My Account tab:** nothing is sent.

You, as the shop, are responsible for informing your customers that order e-mails and totals are passed to Rewloy for matching. The box at checkout shows the notice of the join form, in your business's name; you can set the name and a contact e-mail under WooCommerce › Rewloy.

Rewloy's terms: https://rewloy.com/kosullar

Türkçe özet: Eklenti yalnız Rewloy'a veri gönderir (bağlanırken bağlantı kodu ve sitenin başlığı, sonra anahtar; siparişlerin numarası, durumu, para birimi ve tutarı, fatura e-postası ise yalnız sipariş işleniyor ya da tamamlandı olduğunda; davet açıksa ve alıcı kutuyu işaretlediyse fatura e-postası, sipariş numarası ve bağlantı kimliğiyle birlikte). Siparişin ad, adres, telefon ve ürünleri gitmez. Hesabım sekmesi hiçbir şey göndermez. İzleme yoktur. Müşterilerinizi, sipariş e-postası ve tutarının kart eşleştirmesi için Rewloy'a iletildiği konusunda aydınlatmak sizin yükümlülüğünüzdür.

== Changelog ==

= 0.2.0 =
* Connect with a one-time code made in the Rewloy panel: no API key of your own, and the key the plugin keeps can work with this shop's link only. The API key stays as an advanced option, checked with the new self-check and with the E-ticaret role in mind.
* Once per order on Rewloy's own idempotency: every request carries the order's key, the order and the link; an unclear answer is asked again with the same key (automatically, then from the order's action list) and the card link is e-mailed when it comes. The permanent locks are gone; the one-e-mail-one-card rule stays.
* An order that reached Rewloy before its card is delivered again through its webhook, so it counts toward the card.
* Health on the screen: the last request from the shop and its result, the last refused request, the key Rewloy lists for the link. A test environment's key is said to be one.
* Turkish for every new string.

= 0.1.0 =
* First preview: connect with an API key, health and last orders, pause, resume, disconnect; optional checkout invitation; optional My Account tab; Turkish translation.

== Upgrade Notice ==

= 0.2.0 =
Connect with a one-time code; once-per-order now rests on Rewloy's idempotency, and an unclear answer is asked again instead of being lost.

= 0.1.0 =
First preview release.
