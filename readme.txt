=== Rewloy for WooCommerce ===
Contributors: rewloy
Tags: loyalty, rewards, woocommerce, stamp card, wallet
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT
WC requires at least: 8.0
WC tested up to: 9.4

Paid WooCommerce orders fill Rewloy loyalty cards. Connect with an API key. Optional invitation at checkout. / Ödenmiş siparişler Rewloy sadakat kartlarını doldurur.

== Description ==

**English**

[Rewloy](https://rewloy.com) puts a business's digital loyalty cards on the customer's phone: stamps, points, VIP and cashback cards, in Apple Wallet, Google Wallet and Rewloy Cüzdan. At the till a QR code is scanned. This plugin does the same for your online shop: a paid order fills the card.

* **Connect in one step.** Paste a Rewloy API key under WooCommerce › Rewloy, choose the card and the rule, press Connect. The plugin creates the link in Rewloy and the WooCommerce webhook itself, and shows its health and the last orders with their outcomes.
* **Rules.** For stamp and points cards: per order, or per amount of the order total. A VIP card counts one visit per paid order; a cashback card uses its own rate.
* **Only paid orders** (*processing* or *completed*), each once, on the card the buyer already has (matched by the order's e-mail). A card is never opened by an order alone.
* **Pause and resume**, or remove the connection, from the same screen.
* **Invitation at checkout (optional, off by default).** An unticked box, with the privacy notice beside it. When the order is paid and the box was ticked, one card is opened for the billing e-mail and its private link is e-mailed to the buyer.
* **My Account › "Sadakat kartım" (optional, off by default).** A short text, a button to Rewloy Cüzdan and your join link. It never shows card data and makes no call to Rewloy: WooCommerce does not verify that an account's e-mail belongs to the person, so a card must not be shown by e-mail.
* Works with High-Performance Order Storage (HPOS) and with the block checkout (the invitation box needs WooCommerce 8.9 or newer there).
* Turkish and English. MIT licensed, no tracking, no remote code.

You need a Rewloy account with the e-commerce feature. The plugin does not create one.

**Türkçe**

[Rewloy](https://rewloy.com), işletmelerin dijital sadakat kartlarını müşterinin telefonuna koyar: damga, puan, VIP ve cashback kartları; Apple Cüzdan, Google Cüzdan ve Rewloy Cüzdan'da. Kasada QR okutulur. Bu eklenti aynısını online mağazanız için yapar: ödenmiş sipariş kartı doldurur.

* **Tek adımda bağlanma.** WooCommerce › Rewloy ekranında bir Rewloy API anahtarı yapıştırın, kartı ve kuralı seçin, Bağla'ya basın. Rewloy'daki bağlantıyı ve WooCommerce webhook'unu eklenti kurar; sağlığını ve son siparişleri sonuçlarıyla gösterir.
* **Kurallar.** Damga ve puan kartlarında sipariş başına ya da sipariş tutarına göre. VIP kartında her ödenmiş sipariş bir ziyarettir; cashback kartında kartın kendi oranı uygulanır.
* **Yalnız ödenmiş sipariş** (*işleniyor* ya da *tamamlandı*), her biri bir kez, alıcının zaten sahip olduğu karta (siparişin e-postasıyla eşleşir). Yalnız siparişle kart açılmaz.
* Aynı ekrandan **duraklatma ve sürdürme** ya da bağlantıyı kaldırma.
* **Ödeme sayfasında davet (isteğe bağlı, varsayılan kapalı).** İşaretsiz bir kutu ve yanında aydınlatma metni. Sipariş ödendiğinde ve kutu işaretlenmişse fatura e-postası için bir kart açılır; özel bağlantısı alıcıya e-postayla gönderilir.
* **Hesabım › Sadakat kartım (isteğe bağlı, varsayılan kapalı).** Kısa bir metin, Rewloy Cüzdan düğmesi ve katılım bağlantınız. Kart verisi göstermez, Rewloy'a çağrı yapmaz: WooCommerce bir hesabın e-postasının kişiye ait olduğunu doğrulamaz; bu yüzden kart e-postayla gösterilmemelidir.
* Yüksek Performanslı Sipariş Depolaması (HPOS) ve blok ödeme sayfasıyla çalışır (davet kutusu orada WooCommerce 8.9 ve üstünü ister).
* Türkçe ve İngilizce. MIT lisanslı, izleme yok, uzaktan kod yok.

Eklentiyi kullanmak için e-ticaret özelliği olan bir Rewloy hesabı gerekir; eklenti hesap açmaz.

== Installation ==

1. Upload the zip under Plugins › Add New › Upload Plugin, or unzip it into `wp-content/plugins/`. Activate it. WooCommerce must be active.
2. Open WooCommerce › Rewloy. In the Rewloy panel, under Developer, create an API key that may see cards and settings, manage API keys and shop links, and issue cards. Paste it. Rewloy checks it before it is saved. (Or define `REWLOY_API_KEY` in wp-config.php; that one wins.)
3. Choose the card and the rule, press Connect.
4. Optional: turn on the checkout invitation and the My Account tab, and name the data controller (your business) for the notice.

Kurulum: eklentiyi Eklentiler › Yeni ekle › Eklenti yükle ile yükleyip etkinleştirin (WooCommerce etkin olmalı); WooCommerce › Rewloy ekranında Rewloy panelinden oluşturduğunuz API anahtarını yapıştırın, kartı ve kuralı seçip Bağla'ya basın.

== Frequently Asked Questions ==

= Is my API key shown back to me? =
No. The screen shows only `rwk_` and the ten public characters Rewloy itself lists, then dots. The key is stored in its own option, not loaded on every request. If you define `REWLOY_API_KEY` in wp-config.php, nothing is stored.

= What happens to an order that is not paid, or whose buyer has no card? =
Nothing is added. Rewloy records the order's number and outcome ("No card", "Below the threshold", and so on) and you see it on the screen. A card is opened only by the optional checkout invitation, and only when the buyer ticked the box.

= Can an order open two cards? =
It is built not to. Rewloy itself does not de-duplicate cards, so the plugin does: before it asks Rewloy it takes a lock on the order and another on the e-mail address (kept for good once a card may have been opened), it writes "unknown" on the order before the request, and the request is never repeated, not even after a timeout. If Rewloy's answer is unclear the order note says so and nothing is sent again. One e-mail address is invited once from this shop. A card the customer got somewhere else is not known to the plugin.

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

1. **Connecting and managing** (WooCommerce › Rewloy, administrators only): your API key (as the Bearer credential of every call to `https://app.rewloy.com/v1`), the card you chose and the rule. Rewloy answers with the card list, the link's state and the last orders' number, outcome and time (no personal data).
2. **Paid orders, by the WooCommerce webhook** this plugin creates: for every order update, the order's number, status, currency and total, and the **billing e-mail** once the order is processing or completed, signed with a secret only your shop and Rewloy hold. Nothing else of the order (no names, addresses, phone numbers or items). Rewloy reads the e-mail only to find the buyer's existing card and the total only to calculate; it stores the order number, its outcome and the time. The privacy notice says the same: https://rewloy.com/gizlilik
3. **Checkout invitation, only if you turn it on and only when the buyer ticks the box:** the order's billing e-mail is sent once to Rewloy to open a card (with the statement that the privacy notice was shown). Rewloy's answer carries the card's private link; the plugin e-mails it to the buyer and does not store it.
4. **My Account tab:** nothing is sent.

You, as the shop, are responsible for informing your customers that order e-mails and totals are passed to Rewloy for matching. The box at checkout shows the notice of the join form, in your business's name; you can set the name and a contact e-mail under WooCommerce › Rewloy.

Rewloy's terms: https://rewloy.com/kosullar

Türkçe özet: Eklenti yalnız Rewloy'a veri gönderir (API anahtarı, seçilen kart ve kural; siparişlerin numarası, durumu, para birimi ve tutarı, fatura e-postası ise yalnız sipariş işleniyor ya da tamamlandı olduğunda; davet açıksa ve alıcı kutuyu işaretlediyse fatura e-postası). Siparişin ad, adres, telefon ve ürünleri gitmez. Hesabım sekmesi hiçbir şey göndermez. İzleme yoktur. Müşterilerinizi, sipariş e-postası ve tutarının kart eşleştirmesi için Rewloy'a iletildiği konusunda aydınlatmak sizin yükümlülüğünüzdür.

== Changelog ==

= 0.1.0 =
* First preview: connect with an API key, health and last orders, pause, resume, disconnect; optional checkout invitation; optional My Account tab; Turkish translation.

== Upgrade Notice ==

= 0.1.0 =
First preview release.
