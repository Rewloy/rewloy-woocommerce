# Rewloy for WooCommerce

**WooCommerce mağazanızın siparişleri Rewloy sadakat kartlarını doldursun.**

> **Durum: önizleme (0.x): yayımlanmadı.** Eklentinin henüz yayımlanmış bir sürümü
> yok; WordPress.org dizininde de değil. Kod burada. **0.4.0** PHPUnit'le ve gerçek bir
> WordPress 7.1.2 + WooCommerce 11.1.2 mağazasında, yerelde çalışan gerçek Rewloy'a karşı
> denendi (klasik ve blok ödeme, HPOS açık ve kapalı; [docs/VERIFIED.md](docs/VERIFIED.md)).
> WooCommerce mağazanızı Rewloy'a **bugün eklentisiz de bağlayabilirsiniz**; yolu aşağıda.

[Rewloy](https://rewloy.com), işletmelerin dijital sadakat kartlarını
müşterinin telefonuna koyar: damga, puan, VIP, cashback, hediye kartı, kupon ve
indirim. Kartlar iPhone'da Apple Cüzdan'da, Android'de Rewloy Cüzdan ve Google
Cüzdan'da, her yerde web kartında açılır. Kasada QR okutulur. Online satışta ise
ödenen sipariş kartı kendiliğinden doldurur.

## Eklenti ne yapar

- **WordPress'te küçük bir Rewloy paneli (0.3.0).** Üst menüde **Rewloy**, dört sekme:
  - *Özet*: bağlı kart ve işletme, bağlantının sağlığı, son siparişler ve kartın son 30
    günlük sayıları, her biri neyi saydığıyla (açık kartlar, verilen kartlar, ziyaretler,
    kullanılan ödüller);
  - *Kartlar*: müşterilerin kartlarındaki son işlemler (ne, ne zaman, nerede; kart son 4
    karakteriyle), sekme açıkken 30 saniyede bir yenilenir; numarayla kart sorgulama;
  - *Kasa*: kartı numarasıyla ya da USB/Bluetooth okuyucuyla okutma, şube uyarıları,
    **satış** (tutar ve fiş numarası) ve kartın kendi işlemleri (ödül, bakiye, kupon),
    harcayanlar onay ister; yalnız bir şubede; varsayılan olarak yalnız yöneticiler (`rewloy_wc_till`;
    Ayarlar'da "Mağaza yöneticileri de kasayı kullanabilir"); net yanıt gelmeyen basış bekler, kasa kilitlenir,
    "Tekrar dene" aynı anahtarla gönderir;
  - *Ayarlar*: bağlantı ve seçenekler, eskisi gibi (WooCommerce › Rewloy de buraya açılır).

  Eklentinin neler yapabileceğini Rewloy söyler (`GET /v1/me`): **Görüntüleme**
  (varsayılan açık) ve **Kasa** (varsayılan kapalı) Rewloy panelinde, bağlantının
  sayfasındaki "WordPress yetkileri"nden açılıp kapanır, hemen uygulanır. Kart
  oluşturmak ve tasarlamak, kampanyalar, ekip ve fatura Rewloy panelinde kalır; eklenti
  tam sayfasına bağlantı verir. WordPress'te hiçbir müşterinin adı, e-postası ya da
  telefonu görünmez; API anahtarı tarayıcıya hiç ulaşmaz.
- **Ödeme adımında Rewloy kartları (0.4.0).** Müşteri kartında (kartın sayfası ya da
  Rewloy Cüzdan) "Online alışverişte kullan" ile tek kullanımlık bir kod oluşturur,
  `RW-XXXX-XXXX`, ve klasik ya da blok ödeme sayfasının **kupon alanına** yazar:
  - cashback kartı ve tutarlı kupon siparişten **indirim** olarak düşer (vergiden önce);
  - hediye kartı **ödeme** olarak ayrı, vergisiz bir eksi satırla düşer (vergiden sonra,
    ürünlerin KDV'si değişmez; WooCommerce böyle bir satırın en fazla vergi öncesi tutarı
    düşmesine izin verir);
  - indirim kartı yüzdesini verir; damga, puan ve VIP kartı siparişi **o karta bağlar**
    (sipariş e-postası başka bir kartınki olsa da bu karta işlenir).

  Tutar sipariş verilince Rewloy'da **ayrılır** (ayırma olmadan ödeme alınmaz: Rewloy
  reddederse ya da yanıt vermezse sipariş, müşterinin anlayacağı bir iletiyle reddedilir),
  ödenince **düşülür**, iptal ya da başarısızlıkta karta **döner**, tam iadede karta
  **geri yüklenir**; her adım sipariş notunda yazar. Her seçim Rewloy › Ayarlar'da
  varsayılanı olan bir ayardır (yalnız yöneticiler değiştirir): işletmenin diğer
  kartlarından hangileri kabul edilir, türe göre vergi (muhasebecinize danışın), iade
  edilen siparişin geri alacağı, ödenmeyen siparişin bekletme süresi (1–30 gün, varsayılan 7).
- **Kodla tek adımda bağlanma.** Rewloy panelinde kartı ve kuralı seçip tek
  kullanımlık bir **bağlantı kodu** alırsınız (15 dakika geçerli); Rewloy › Ayarlar
  ekranına yapıştırıp **Bağla**'ya basarsınız. Rewloy'daki bağlantıyı ve
  WooCommerce webhook'unu eklenti kurar ve yalnız bu mağazanın bağlantısıyla
  çalışabilen bir API anahtarı saklar: **kendi API anahtarınıza gerek yok**, mağazada
  güçlü bir anahtar durmaz. API anahtarıyla bağlanmak, betikle kurulan siteler için
  *gelişmiş* bir seçenek olarak ekranın altında durur.
- **Sağlık, panelin kendi sözleriyle.** Mağazadan gelen son istek ve sonucu
  (*Karta işlendi*, *Kartı yok*, *Eşiğin altında*, *Bağlantı kapalıyken*, *Başka para
  birimi*, *Kayıtlı siparişin tekrarı*…), imzası tutmadığı için reddedilen son istek
  (*İmza tutmadı*), webhook'un durumu ve başarısız teslim sayısı, Rewloy'daki anahtarın
  adı ve son siparişler. Aynı ekrandan bağlantıyı duraklatır, sürdürür ya da kaldırırsınız.
- **Kurallar** panelle aynı: damga ve puan kartlarında sipariş başına ya da sipariş
  tutarına göre; VIP kartında her ödenmiş sipariş bir ziyaret; cashback kartında
  kartın kendi oranı.
- **Ödeme sayfasında davet (isteğe bağlı, varsayılan kapalı).** İşaretsiz bir kutu ve
  yanında aydınlatma metni. Sipariş ödendiğinde ve kutu işaretlenmişse fatura
  e-postası için **en fazla bir kez** kart açılır; kartın özel bağlantısı alıcıya
  e-postayla gider, mağazada saklanmaz. Kartı kazandıran sipariş de o karta
  sayılır: sipariş bildirimi karttan önce gelmişse (*Kartı yok* diye kaydedilmişse)
  eklenti siparişi webhook'u üzerinden yeniden teslim eder. Yanıt belirsizse aynı
  istek aynı anahtarla yinelenir; Rewloy yinelemeyi açtığı kartla yanıtlar, ikinci
  kart açılmaz. Klasik ödeme sayfasında ve (WooCommerce 8.9+) blok ödeme
  sayfasında çalışır.
- **Hesabım › Sadakat kartım (isteğe bağlı, varsayılan kapalı).** Kısa bir metin,
  Rewloy Cüzdan düğmesi ve katılım bağlantınız. **Kart verisi göstermez ve Rewloy'a
  çağrı yapmaz:** WooCommerce bir hesabın e-postasının kişiye ait olduğunu
  doğrulamaz; e-postayla eşleşen kartı göstermek, başkasının adresiyle kayıt olan
  herkesin onun kartını görmesi demek olurdu. Müşteri adresini Rewloy Cüzdan'da
  bir kodla kanıtlar ve kartlarını orada görür.
- HPOS (özel sipariş tabloları) uyumlu; siparişlere yalnız `WC_Order` API'siyle dokunur.
- Çalışma zamanında Composer bağımlılığı yok; başka eklentilerle çakışmaz.
- Türkçe ve İngilizce (`languages/`). MIT lisanslı, izleme yok, uzaktan kod yok.

Gereksinimler: WordPress 6.4+, WooCommerce 8.0+, PHP 8.1+ ve e-ticaret özelliği olan
bir Rewloy hesabı.

## Kurulum

WordPress.org'da yayımlanana kadar:

1. Bu deponun **Releases** sayfasındaki `rewloy-for-woocommerce-x.y.z.zip` dosyasını
   indirin (ilk sürüm yayımlandığında). Kendiniz üretmek için: `composer install`
   ardından `bin/build-zip`; zip `build/` altında çıkar.
2. WordPress'te **Eklentiler › Yeni ekle › Eklenti yükle** ile yükleyip etkinleştirin.
3. Rewloy panelinde **E-ticaret › Mağaza bağla › WooCommerce › "Rewloy eklentisiyle
   (önerilen)"**: kartı ve kuralı seçin, şifrenizi yeniden girin. Rewloy size
   `rwc_…` ile başlayan tek kullanımlık bir kod verir; kod yalnız bir kez gösterilir
   ve 15 dakika geçerlidir.
4. **Rewloy › Ayarlar** (ya da WooCommerce › Rewloy) ekranına kodu yapıştırıp **Bağla**'ya basın.
5. Kasayı kullanacaksanız Rewloy panelinde bağlantının sayfasında **WordPress yetkileri**
   bölümünden Kasa'yı bir şubede açın (nedeni ve şifrenizle).

Anahtar ekranda bir daha gösterilmez; yalnız `rwk_` ve Rewloy'un kendi listelerinde
yazdığı gizli olmayan 10 karakter görünür. Kod hiçbir yere kaydedilmez.

**Gelişmiş: API anahtarıyla.** Kimsenin kod üretemediği durumlar için (betikle
kurulan site: WP-CLI, dağıtım araçları; bir deneme kopyası): kod tek kullanımlıktır,
15 dakika geçerlidir ve Rewloy paneline girmiş birini ister. Ekranda **Gelişmiş**
altına, Rewloy panelinde **Geliştirici** bölümünden **E-ticaret** rolüyle
oluşturduğunuz anahtarı yapıştırın (kartlar, mağaza bağlantıları ve kart verme; başka
bir şey değil). Rewloy anahtarı `GET /v1/me` ile denetler, eksik yetkiyi söyler ve
ancak ondan sonra kaydeder; sonra kartı ve kuralı seçersiniz. Anahtarı
`wp-config.php`'de de tanımlayabilirsiniz; o zaman o geçerlidir ve hiçbir yere
kaydedilmez:

```php
define( 'REWLOY_API_KEY', 'rwk_…' );
```

## Neler gider

Eklenti yalnız Rewloy'a (`https://app.rewloy.com`) veri gönderir.

| Ne zaman | Ne gider |
|---|---|
| Kodla bağlanırken (yalnız `manage_woocommerce`) | Bağlantı kodu ve sitenin başlığı (Rewloy'daki anahtar listesinde anahtarın adı olur). Yanıt: bağlantı, sırrı ve yalnız o bağlantıya bağlı bir API anahtarı. |
| Yönetirken | O anahtar (her çağrıda yetki başlığı olarak). Yanıt: bağlantının durumu, mağazadan son istek ve sonucu, son siparişlerin numarası, sonucu ve zamanı. API anahtarıyla bağlandıysanız ayrıca seçtiğiniz kart ve kural. |
| Her sipariş güncellemesinde (WooCommerce webhook'u) | Siparişin **numarası, durumu, para birimi ve tutarı**; **fatura e-postası** yalnız sipariş *işleniyor* ya da *tamamlandı* olduğunda. Yalnız mağazanın ve Rewloy'un bildiği bir sırla imzalı. Ad, adres, telefon ve ürünler **gitmez**: eklenti webhook'un içeriğini bu alanlara indirir. Rewloy e-postayı yalnız mevcut kartı bulmak, tutarı yalnız hesaplamak için okur; sipariş numarasını, sonucunu ve zamanını saklar. |
| Davet açıksa ve alıcı kutuyu işaretlediyse, sipariş ödenince | Fatura e-postası, kart açmak için; yanında aydınlatma metninin sunulduğu beyanı, siparişin numarası ve bağlantının kimliği (sipariş karta sayılsın diye). Yanıt belirsizse aynı istek aynı anahtarla yinelenir. Sipariş karttan önce Rewloy'a ulaşmışsa WooCommerce siparişi webhook'undan yeniden teslim eder (yukarıdaki satır). |
| Rewloy menüsü: Kartlar ve Kasa (yalnız `manage_woocommerce`) | Yazılan ya da okutulan **kart numarası** (okutulan kart bağlantısının geri kalanı, özel `k` anahtarı dahil, hemen atılır, hiçbir yere yazılmaz); kasada **ödenen toplam**, **fiş numarası** ve kasanın şubesi. Yanıt: kartın durumu ve son işlemler; hiçbir kişinin adı, e-postası ya da telefonu gelmez. Çağrıları tarayıcı değil WordPress yapar. |
| Ödeme adımında bir Rewloy kodu (0.4.0) | Yazılan **kod** ve sepetin para birimi (ne sağladığını sormak için), alışverişçinin kişisel veri taşımayan bir özeti (WooCommerce müşteri ya da oturum kimliğinin sitenin sırrıyla HMAC-SHA256'sı; kimliğin kendisi gitmez); sonra **sipariş numarası**, siparişin koddan aldığı tutar ve indirimden önceki toplamı (ayırmak, düşmek, bırakmak ya da iade etmek için). Yanıt: kartın programı, türü ve son dört karakteri; ad, e-posta ya da kart numarası gelmez. Eklenti kodu saklamaz (WooCommerce kodu siparişin kupon satırında tutar). |
| Hesabım sekmesi | Hiçbir şey. |

Müşterilerinizi, sipariş e-postası ve tutarının kart eşleştirmesi için Rewloy'a
iletildiği konusunda aydınlatmak sizin yükümlülüğünüzdür; ödeme sayfasındaki kutu
katılım formunun veri sorumlusu notunu işletmenizin adıyla gösterir
(Rewloy › Ayarlar'da ad ve iletişim e-postası girilir). Ayrıntılar:
[aydınlatma metni](https://rewloy.com/gizlilik), [koşullar](https://rewloy.com/kosullar).

**Eklentiyi silmek** ayarlarını ve webhook'unu bu siteden kaldırır. Rewloy'daki
hiçbir şeyi silmez: bağlantı ve kartlar, siz Rewloy panelinden silene kadar durur.
Ayar ekranı bunu söyler.

## Güvenlik

- Her ayar işlemi `manage_woocommerce` ister, ardından kendi nonce'unu denetler;
  her girdi temizlenir, her çıktı kaçışlanır; her PHP dosyası doğrudan açılmaya
  karşı korumalıdır.
- API anahtarı kendi seçeneğinde (otomatik yüklenmez) durur, hiçbir mesaja,
  nota, günlüğe ya da hata iletisine yazılmaz, ekranda geri gösterilmez. Kodla
  bağlanınca bu anahtar yalnız bir mağaza bağlantısına bağlıdır; bağlantı silinince
  Rewloy onu iptal eder. Bağlantı kodu girilen alanda parola alanıdır, hiçbir yere
  kaydedilmez ve hiçbir iletiye yazılmaz.
- Webhook'un teslim adresi yalnız API'nin kendi sunucusunda ve
  `/hooks/store/<bağlantı>` yolunda kabul edilir; başka bir adres gelirse
  hiçbir şey bağlanmaz ve oluşan bağlantı geri alınır.
- Kart açma (`issuePass`) sipariş başına **en fazla bir kez**. Her istek siparişin
  kendi `Idempotency-Key`'ini, `orderId`'sini ve `shopId`'sini taşır; Rewloy aynı
  anahtar ve aynı gövdeyle yinelemeyi ilk yanıtla (aynı kart) karşılar, başka
  gövdeyle reddeder. Yanıt belirsizse (zaman aşımı, 5xx) aynı istek aynı anahtarla
  yinelenir: istemci iki kez, ardından zamanlanmış eylem 5 dakika, 1 saat ve 6 saat
  sonra, sonra siparişin eylem listesinden elle. Yinelenecek istek aynı olamıyorsa
  (e-posta düzenlendi, bağlantı yenilendi) ya da altı günü geçtiyse gönderilmez ve
  sipariş notu Rewloy panelinde aramayı söyler. Kalıcı kilitler yok: sipariş ve
  e-posta başına kısa, kendiliğinden biten bir kilit iki sürecin bağlantıyı iki kez
  postalamasını önler. Rewloy aynı e-posta için ikinci kartı engellemediğinden
  "bir e-posta, bir kart" kuralı eklentinindir: aynı e-posta bu mağazadan bir kez
  davet edilir. Ayrıntı: [docs/DECISIONS.md](docs/DECISIONS.md) D29.

Bir güvenlik açığı bulursanız [SECURITY.md](SECURITY.md) dosyasındaki yoldan
özel olarak bildirin. Lütfen herkese açık issue açmayın.

## Geliştirme

PHP 8.1+ ve Composer yeter; WordPress, MySQL ya da Docker gerekmez.

```sh
composer install
bin/lint                       # php -l, her dosya
vendor/bin/phpstan analyse     # WordPress ve WooCommerce stub'larıyla
vendor/bin/phpunit             # Brain Monkey ile; ağa hiç çıkmaz
bin/make-pot && bin/make-mo    # çeviri şablonu ve .mo (GNU gettext gerekir)
bin/build-zip                  # WordPress.org zip'i: build/
```

Testler gerçek bir WordPress ya da WooCommerce çalıştırmaz; WordPress işlevlerini
Brain Monkey, WooCommerce sınıflarını küçük test çiftleri karşılar. Kararlar:
[docs/DECISIONS.md](docs/DECISIONS.md).

## Bugün, eklentisiz

Rewloy, WooCommerce'in kendi webhook'larıyla çalışır:

1. Rewloy panelinde **E-ticaret › Mağaza bağla**. Platform olarak WooCommerce'i,
   sonra kartı (programı) ve kuralı seçin:
   - sipariş başına damga ya da puan;
   - tutara göre damga ya da puan;
   - cashback kartında kartın kendi oranı;
   - VIP kartında her sipariş bir ziyaret.
2. Rewloy bir **teslim adresi** (webhook URL) ve bir **gizli anahtar** üretir.
   Anahtar yalnız bir kez gösterilir.
3. WooCommerce'te **Ayarlar › Gelişmiş › Webhook'lar › Webhook ekle**:
   - Durum *Etkin*, Konu *Sipariş güncellendi*;
   - Teslim URL'si ve Gizli anahtar, Rewloy'un verdikleri;
   - API sürümü *WP REST API v3*.
4. Kaydedin. WooCommerce'in ilk deneme isteği sipariş sayılmaz.

**Sonra:**
- Ödenmiş sipariş (WooCommerce'te *işleniyor* ya da *tamamlandı*), e-postası
  bu karttaki bir müşteriyle eşleşirse kartına eklenir.
- Kartı olmayana kart açılmaz.
- Her sipariş bir kez sayılır.
- İmzası tutmayan istek reddedilir.

Rewloy siparişten yalnız numarasını, sonucunu ve zamanını saklar. E-posta ve
tutar yalnız eşleştirmek ve hesaplamak için okunur, kaydedilmez. Gelen her
sipariş, sonucuyla birlikte panelde bağlantının kendi sayfasında görünür.

## Belgeler

| | |
|---|---|
| Geliştiriciler | https://rewloy.com/gelistiriciler |
| API referansı | https://rewloy.com/gelistiriciler/api |
| E-ticaret | https://rewloy.com/e-ticaret |
| Değişiklik günlüğü | https://rewloy.com/gelistiriciler/degisiklikler |
| Kararlar | [docs/DECISIONS.md](docs/DECISIONS.md) |

## Lisans

[MIT](LICENSE)

---

## English

**Let your WooCommerce shop's orders fill Rewloy loyalty cards.**

**Status: preview (0.x): not published.** There is no release yet, and it is not on
WordPress.org. The code is here. **0.4.0** has been tested with PHPUnit and on a real
WordPress 7.1.2 + WooCommerce 11.1.2 store against the real Rewloy running locally (classic and
block checkout, HPOS on and off; [docs/VERIFIED.md](docs/VERIFIED.md)).

You can connect WooCommerce today without a plugin:
1. In the Rewloy panel, go to E-ticaret › Mağaza bağla and choose WooCommerce.
2. Add a WooCommerce webhook: topic *Order updated*, API version *WP REST API v3*,
   and the delivery URL and secret that Rewloy gives you.
3. A paid order (*processing* or *completed*) then credits the card of the
   customer whose e-mail matches.

Rewloy stores only the order number, its outcome and the time.

### What the plugin does

- **A small Rewloy panel in WordPress (0.3.0).** A top-level **Rewloy** menu with four
  tabs: *Overview* (the card, the business, the link's health, the last orders, the
  card's numbers for 30 days, each named by what it counts), *Cards* (the latest activity
  on customers' cards, a card by its last four characters, refreshed every 30 seconds;
  a card lookup), *Till* (read a card typed or scanned, record a sale with its receipt
  number, use the card's rewards and balance, at one branch) and *Settings* (as before;
  WooCommerce › Rewloy still opens it). Rewloy says what the plugin may do (`GET /v1/me`):
  "Görüntüleme" (on by default) and the till (off by default) are turned on and off in
  the Rewloy panel on the shop link's page, at once. Creating and designing cards,
  campaigns, the team and billing stay in the Rewloy panel, one exact link away. No
  customer's name, e-mail or phone shows in WordPress, and the key never reaches the
  browser.
- **Rewloy cards at the checkout (0.4.0).** A customer makes a one-time code on their card
  (`RW-XXXX-XXXX`) and types it into the coupon field of the classic or block checkout. A
  cashback card or a money coupon is a discount before tax; a gift card is a payment after
  tax (a negative, untaxed line: the goods' KDV stays); a discount card gives its percent; a
  stamp, points or VIP card ties the order to that card. The value is held when the order is
  placed (no payment without the hold), taken when it is paid, released when it is cancelled
  or fails, put back on a full refund, each step in the order notes. Every choice is a
  setting with a default under Rewloy › Settings (administrators only).
- **Connect in one step, with a code.** In the Rewloy panel you choose the card and the
  rule and get a one-time **connect code** (15 minutes); paste it under Rewloy ›
  Settings and press *Bağla* (Connect). The plugin creates the link in Rewloy and the
  WooCommerce webhook itself and keeps an API key that can work with that link only:
  **you need no API key of your own**, and no strong key stays on the shop. An API key
  remains as an *advanced* option for sites set up from a script.
- **Health in the panel's own words.** The last request from the shop and what became of
  it, the last request refused for its signature, the webhook's status and failures, the
  key Rewloy lists for the link and the last orders with their outcomes. Pause, resume
  and disconnect are on the same screen.
- **Rules** as in the panel: per order or per amount for stamp and points cards; one
  visit per paid order for VIP; the card's own rate for cashback.
- **Invitation at checkout (optional, off by default).** An unticked box with the
  privacy notice beside it. When the order is paid and the box was ticked, **at most
  one** card is opened for the billing e-mail; its private link is e-mailed to the
  buyer and not stored. The order that earned the card counts toward it: if its webhook
  reached Rewloy first (recorded "Kartı yok"), the plugin delivers the order again.
  An unclear answer is asked again with the same key, which Rewloy answers with the card
  it already opened. Classic checkout, and the block checkout from WooCommerce 8.9.
- **My Account › "Sadakat kartım" (optional, off by default).** A text, a button to
  Rewloy Cüzdan and your join link. **No card data and no call to Rewloy**:
  WooCommerce does not verify that an account's e-mail is the person's own, so a
  card matched by e-mail must never be shown.
- HPOS compatible, no runtime Composer dependency, Turkish and English, MIT, no
  tracking, no remote code.

Requires WordPress 6.4+, WooCommerce 8.0+, PHP 8.1+ and a Rewloy account with the
e-commerce feature.

### Install

Until it is on WordPress.org: take the zip from this repository's Releases (once the
first one is published) or build it with `composer install && bin/build-zip`, upload it
under Plugins › Add New › Upload Plugin, activate it, and in the Rewloy panel go to
E-ticaret › Mağaza bağla › WooCommerce › "Rewloy eklentisiyle", choose the card and the
rule and take the code. Open Rewloy › Settings, paste it, press Connect. For the till,
turn it on with its branch in the Rewloy panel, on the link's page ("WordPress yetkileri"). The key the
code makes is never shown back, only `rwk_` and its ten public characters; the code is
kept nowhere.

*Advanced, when nobody can make a code* (a site set up from a script, a staging copy; a
code is single-use, lasts 15 minutes and needs someone signed in to the panel): paste an
API key made with the **E-ticaret** role under "Advanced", or define `REWLOY_API_KEY` in
`wp-config.php`. Rewloy checks the key with `GET /v1/me` and the plugin names any
permission it lacks.

### What is sent

Only to Rewloy (`https://app.rewloy.com`): the connect code and the site's title (it
names the key in Rewloy's list) when you connect, then the key it returned on each call
(and the card and rule when you connect with a key of your own); for each order update, the webhook sends the order's number,
status, currency and total, and the billing e-mail once the order is processing or
completed, signed with a secret (the plugin cuts the webhook's body down to those
fields: no names, addresses, phone numbers or items); if
you turn the invitation on, the billing e-mail of an order whose box was ticked, with the
order's number and the link's id (a repeat with the same key if the answer was unclear).
On the Rewloy menu's Cards and Till tabs: the card number typed or scanned (the rest of a
scanned card link, its private key included, is dropped at once), and on the till the paid
total, the receipt number and the till's branch; Rewloy sends back no one's name, e-mail or
phone. A Rewloy code typed at checkout: the code and the basket's currency with an opaque
per-shopper hash, then the order's number, what it took from the code and its total before
discounts; Rewloy answers with the card's programme, kind and last four characters.
The My Account tab sends nothing. Deleting the plugin removes its options and webhook
from your site and deletes nothing in Rewloy. As the shop you must inform your
customers that order e-mails and totals go to Rewloy for matching.

### Development

PHP 8.1+ and Composer; no WordPress, MySQL or Docker. `composer install`, then
`bin/lint`, `vendor/bin/phpstan analyse`, `vendor/bin/phpunit`, `bin/build-zip`. See
[docs/DECISIONS.md](docs/DECISIONS.md) for every decision and its reason, and
[SECURITY.md](SECURITY.md) to report a vulnerability privately.
