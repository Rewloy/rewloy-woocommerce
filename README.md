# Rewloy for WooCommerce

**WooCommerce mağazanızın siparişleri Rewloy sadakat kartlarını doldursun.**

> **Durum: hazırlanıyor.** Eklentinin henüz yayımlanmış bir sürümü yok. WooCommerce
> mağazanızı Rewloy'a **bugün eklentisiz bağlayabilirsiniz**; yolu aşağıda.

[Rewloy](https://rewloy.com), işletmelerin dijital sadakat kartlarını
müşterinin telefonuna koyar: damga, puan, VIP, cashback, hediye kartı, kupon ve
indirim. Kartlar iPhone'da Apple Cüzdan'da, Android'de Rewloy Cüzdan ve Google
Cüzdan'da, her yerde web kartında açılır. Kasada QR okutulur. Online satışta ise
ödenen sipariş kartı kendiliğinden doldurur.

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

## Eklenti neler getirecek

- **Tek adımda bağlanma.** Rewloy API anahtarını yapıştırıp kartı seçmek
  yeter. Webhook'u eklenti kurar ve sağlığını gösterir.
- **Hesabım › Kartım.** Müşteri kartını ve bakiyesini mağazanın kendi
  sayfasında görür; telefonunun cüzdanına ekleyebilir.
- **Ödeme sayfasında katılım daveti.** Kartı olmayan müşteriye, KVKK
  aydınlatmasıyla birlikte.
- WordPress.org eklenti dizininde yayımlanacak.

Liste bir plandır, söz değildir. Neyin geldiğini sürüm notları söyleyecek.

## Belgeler

| | |
|---|---|
| Geliştiriciler | https://rewloy.com/gelistiriciler |
| API referansı | https://rewloy.com/gelistiriciler/api |
| E-ticaret | https://rewloy.com/e-ticaret |
| Değişiklik günlüğü | https://rewloy.com/gelistiriciler/degisiklikler |

## Güvenlik

Bir güvenlik açığı bulursanız [SECURITY.md](SECURITY.md) dosyasındaki yoldan
özel olarak bildirin. Lütfen herkese açık issue açmayın.

## Lisans

[MIT](LICENSE)

---

## English

**Let your WooCommerce shop's orders fill Rewloy loyalty cards.**

**Status: in development.** There is no release of the plugin yet.

You can connect WooCommerce today without a plugin:
1. In the Rewloy panel, go to E-ticaret › Mağaza bağla and choose WooCommerce.
2. Add a WooCommerce webhook: topic *Order updated*, API version *WP REST API v3*,
   and the delivery URL and secret that Rewloy gives you.
3. A paid order (*processing* or *completed*) then credits the card of the
   customer whose e-mail matches.

Rewloy stores only the order number, its outcome and the time.

**Planned for the plugin:**
- one-step connection with an API key;
- the customer's card in *My Account*;
- a join invitation at checkout.

It will be published on WordPress.org, MIT licensed.
