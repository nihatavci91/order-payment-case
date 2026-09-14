# Sipariş ve Ödeme API'si

Bir müşterinin siparişi oluşturmasından, ödemenin kesinleşip siparişin işlenmeye hazır hale gelmesine kadar geçen süreci yöneten RESTful API.

- **Teknolojiler:** Laravel 13, PHP 8.4, MySQL 8.4, RabbitMQ 4.1, Redis 7.4, Nginx
- **Tutarlar** kuruş cinsinden tam sayı olarak tutulur (`12500` = 125,00 TL).
- Mimari kararlar, kullanılan pattern'ler ve alternatifler için: [DESIGN.md](DESIGN.md)
- Projenin tasarımında ve geliştirilmesinde yapay zeka destekli geliştirme aracı **[Claude Code](https://claude.com/claude-code)** kullanılmıştır. Ayrıntılar: [DESIGN.md › Kaynaklar](DESIGN.md#11-kaynaklar)

---

## 1. Kurulum

Bilgisayarda yalnızca **Docker Desktop** (Docker Compose dahil) kurulu olması yeterli. PHP, Composer ya da Node kurmanıza gerek yok.

### Adım 1: Servisleri ayağa kaldırın

```sh
docker compose up -d --build
```

Bu komut şu container'ları başlatır:

| Servis | Görevi |
| --- | --- |
| `setup` | `.env` yoksa oluşturur; `composer install`, uygulama anahtarı, migration ve **demo verisi** yükleme adımlarını çalıştırır, sonra kapanır |
| `app` + `nginx` | API (`http://localhost:8080`) |
| `mysql`, `redis`, `rabbitmq` | Veritabanı, circuit breaker durumu, kuyruk |
| `worker`, `worker-store-2` | Mağaza 1 ve mağaza 2 için ödeme işlerini işler |
| `scheduler`, `scheduler-store-2` | Outbox gönderimi, süresi dolan siparişler, alarm logları |

### Adım 2: Kurulumun bitmesini bekleyin

```sh
docker compose logs -f setup
```

Migration'lar ve demo verisi yüklenince `setup` container'ı kendiliğinden kapanır (`Ctrl+C` ile log takibinden çıkabilirsiniz). Ek bir komut çalıştırmanıza gerek yok; API kullanıma hazırdır.

### Demo verisi (otomatik yüklenir)

`setup` her çalıştığında `php artisan db:seed` de çalışır ve `DemoDataSeeder` tablolara örnek veri ekler.

- **Tekrar çalıştırmak güvenlidir.** Müşteri ve ürünler e-posta/SKU'ya göre, siparişler sabit idempotency anahtarlarına göre kontrol edilir. `docker compose up` kaç kez çalışırsa çalışsın veri çoğalmaz ve mevcut stok değişmez.
- **Yalnızca `local` ve `testing` ortamında çalışır.** Production veritabanına demo verisi yazılmaz.
- Siparişler doğrudan tabloya yazılmaz, gerçek servisler üzerinden oluşturulur. Stok rezervasyonu, ödeme kaydı ve outbox mesajları bu yüzden tutarlıdır; worker'lar ödemeleri birkaç saniye içinde işler.

Sıfırdan kurulumda oluşan ID'ler aşağıdaki gibidir. README'deki ve Postman collection'ındaki örnekler bu ID'leri kullanır.

**Müşteriler**

| ID | E-posta | Mağaza |
| --- | --- | --- |
| 1 | `customer1@example.test` | 1 |
| 2 | `customer2@example.test` | 2 |
| 3 | `customer3@example.test` | 1 |
| 4 | `customer4@example.test` | 2 |

**Ürünler**

| ID | SKU | Ürün | Fiyat | Stok | Not |
| --- | --- | --- | --- | --- | --- |
| 1 | `STORE-1-BOOK` | Defter | 125,00 TL | 100 | |
| 2 | `STORE-1-PEN` | Kalem Seti | 49,90 TL | 200 | |
| 3 | `STORE-1-BAG` | Sırt Çantası | 899,00 TL | 25 | |
| 4 | `STORE-1-LAMP` | Masa Lambası | 450,00 TL | 1 | Yetersiz stok denemesi için |
| 5 | `STORE-1-OLD` | Eski Model Termos | 300,00 TL | 10 | Satışta değil (`is_active = false`) |
| 6 | `STORE-2-BOOK` | Defter | 125,00 TL | 100 | Mağaza 2 |
| 7 | `STORE-2-MUG` | Kupa | 199,00 TL | 50 | Mağaza 2 |
| 8 | `STORE-2-HEADSET` | Kulaklık | 1.499,00 TL | 10 | Mağaza 2 |

Stok değerleri ilk durumu gösterir; aşağıdaki demo siparişler bir kısmını ayırır.

**Demo siparişler** (worker'lar işledikten sonraki durum)

| Müşteri | Sepet | Ödeme senaryosu | Beklenen son durum |
| --- | --- | --- | --- |
| 1 | 2 × Defter | `success` | `ready_for_processing` |
| 1 | 1 × Sırt Çantası | `declined` | `payment_failed`, stok geri eklenir |
| 3 | 3 × Kalem Seti, 1 × Defter | `timeout_after_charge` | `ready_for_processing` (tek tahsilat) |
| 3 | 1 × Defter | ödeme yok | `pending_payment`, 15 dakika sonra otomatik `cancelled` |
| 1 | 1 × Kalem Seti | ödeme yok, iptal edildi | `cancelled` |
| 2 | 2 × Kupa | `success` | `ready_for_processing` (mağaza 2) |
| 4 | 1 × Kulaklık | `timeout` | `ready_for_processing` (sorgu + tekrar deneme, mağaza 2) |

Veritabanında daha önceden başka kayıtlar varsa ID'ler farklı olabilir. Güncel ID'leri görmek için:

```sh
docker compose exec app php artisan demo:customers
docker compose exec app php artisan demo:products
docker compose exec app php artisan demo:products --store=2
```

Veritabanını tamamen sıfırlayıp demo verisiyle baştan başlamak için (**tüm yerel veri silinir**):

```sh
docker compose down -v
docker compose up -d --build
```

### Adresler

| Adres | Açıklama |
| --- | --- |
| `http://localhost:8080/api` | API |
| `http://localhost:8080/up` | Sağlık kontrolü |
| `http://localhost:15672` | RabbitMQ paneli (`order_user` / `order_password`) |

Portlar yalnızca `127.0.0.1`'e açılır.

> **Daha önce kurduysanız:** `.env` dosyanızı `.env.example` ile karşılaştırın (`DB_HOST=mysql`, `REDIS_HOST=redis`, `QUEUE_CONNECTION=rabbitmq`, `PAYMENT_QUEUE_CONNECTION=rabbitmq`). Ayar değiştirdikten sonra `php artisan config:clear` çalıştırıp worker ve scheduler container'larını yeniden başlatın.

---

## 2. API Kullanımı

### Müşteri kimliği: `X-Customer-Id`

Kimlik doğrulama (login, token) case kapsamında istenmediği için eklenmedi. Müşteri her istekte **`X-Customer-Id` header'ı** ile belirtilir:

- Header yoksa, sayı değilse ya da böyle bir müşteri yoksa → `401`
- Mağaza bilgisi müşterinin kaydından alınır; body'de `user_id` veya `store_id` gönderilirse → `422`
- Müşteri yalnızca kendi siparişlerini görebilir; başkasının siparişi → `404`

### Endpoint listesi

| Metot ve adres | Ne yapar | Başarılı yanıt |
| --- | --- | --- |
| `POST /api/orders` | Sipariş oluşturur, stoğu ayırır | `201` (tekrar istekte `200`) |
| `GET /api/orders/{order}` | Siparişi ve ödeme durumunu gösterir | `200` |
| `POST /api/orders/{order}/payment` | Ödemeyi başlatır (asenkron) | `202` (tekrar istekte `200`) |
| `GET /api/orders/{order}/payment` | Ödeme durumu, hata kodu, sonraki deneme zamanı | `200` |
| `POST /api/orders/{order}/cancel` | Siparişi iptal eder | `200`, iade bekliyorsa `202` |
| `POST /api/orders/{order}/complete` | Hazır siparişi tamamlar (aynı mağaza) | `200` |
| `GET /api/metrics` | Prometheus formatında metrikler (header gerekmez) | `200` |

`{order}` değeri, sipariş yanıtındaki `order_number` (UUID) alanıdır. İstek sınırı IP başına dakikada 120 istektir.

### Postman ile kullanım (önerilen)

Hazır collection: [`postman/order-payment-case.postman_collection.json`](postman/order-payment-case.postman_collection.json)

1. Postman'de **Import** → dosyayı seçin.
2. Collection'ı açıp **Variables** sekmesini kontrol edin. `customerId`, `productId` gibi değerler sıfırdan kurulumdaki demo ID'lerine göre ayarlıdır.
3. Klasörleri sırayla tek tek çalıştırın ya da **Run collection** ile hepsini birden çalıştırın.

| Klasör | İçerik |
| --- | --- |
| `0 - Sağlık kontrolü` | `/up` |
| `1 - Başarılı akış` | Sipariş oluştur → aynı isteği tekrar gönder → ödeme başlat → çift tıklama → sonucu bekle → tamamla |
| `2 - İptal akışı` | Sipariş oluştur → iptal → tekrar iptal → iptal edilmiş siparişe ödeme (`409`) |
| `3 - Ödeme senaryoları` | `scenario` değişkenini (`declined`, `timeout`, `timeout_after_charge` …) değiştirerek mock sağlayıcı davranışlarını dener |
| `4 - Hatalı istekler` | `401`, `404`, `409`, `422` örnekleri: header yok, başka müşteri, yetersiz stok, pasif ürün, kart bilgisi vb. |
| `5 - Metrikler` | Prometheus metrikleri |

Collection'daki istekler şunları kendiliğinden yapar:

- Her yeni siparişte yeni bir `Idempotency-Key` üretilir.
- `order_number` bir sonraki istekler için kaydedilir.
- "Sonucu bekle" isteği, worker ödemeyi işleyene kadar Runner içinde kendini tekrarlar.
- Her isteğin beklenen HTTP kodu test olarak tanımlıdır.

`Authorization` header'ına veya Bearer token'a gerek yoktur.

### curl ile kullanım (Linux / macOS / Git Bash)

Örnekler sıfırdan kurulumdaki demo verisini kullanır: müşteri `1`, ürünler `1` ve `2` (mağaza 1).

**1) Sipariş oluştur**

```sh
curl -i -X POST http://localhost:8080/api/orders \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -H 'X-Customer-Id: 1' \
  -H 'Idempotency-Key: 3f8e2a4c-5b6d-4e7f-8a9b-0c1d2e3f4a5b' \
  -d '{"items":[{"product_id":1,"quantity":2},{"product_id":2,"quantity":1}]}'
```

- Yanıt `201 Created` döner. `data.order_number` bir sonraki adımlarda kullanılır, `data.formatted_total` ise tutarı okunur biçimde gösterir (`299,90 TRY`).
- Aynı komutu **tekrar** çalıştırın: yeni sipariş açılmaz, aynı sipariş `200` ile döner.
- Anahtarı aynı bırakıp `quantity` değerini değiştirin: `409 Conflict`.
- Yeni bir sipariş için `Idempotency-Key` değerini değiştirin (herhangi bir UUID).
- Fiyat ve toplam tutar sunucuda hesaplanır; body'de tutar gönderilse de dikkate alınmaz.

**2) Ödemeyi başlat**

```sh
ORDER=buraya-data.order_number-degerini-yazin

curl -i -X POST "http://localhost:8080/api/orders/$ORDER/payment" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -H 'X-Customer-Id: 1' \
  -d '{"scenario":"success"}'
```

Yanıt `202 Accepted` döner; ödeme kuyruğa alınır ve birkaç saniye içinde worker tarafından işlenir. Aynı istek tekrar gelirse aynı ödeme kaydı `200` ile döner, farklı `scenario` ile gelirse `409`. Diğer senaryolar için [Mock Ödeme Sağlayıcısı](#3-mock-ödeme-sağlayıcısı) bölümüne bakın.

**3) Ödeme ve sipariş durumunu takip et**

```sh
curl -s "http://localhost:8080/api/orders/$ORDER/payment" -H 'Accept: application/json' -H 'X-Customer-Id: 1'
curl -s "http://localhost:8080/api/orders/$ORDER" -H 'Accept: application/json' -H 'X-Customer-Id: 1'
```

Ödeme `succeeded` olduğunda sipariş `ready_for_processing` durumuna geçer.

**4) Siparişi tamamla veya iptal et**

```sh
# Tamamla (sipariş ready_for_processing olmalı)
curl -i -X POST "http://localhost:8080/api/orders/$ORDER/complete" -H 'Accept: application/json' -H 'X-Customer-Id: 1'

# İptal et (tamamlanmamış bir siparişte)
curl -i -X POST "http://localhost:8080/api/orders/$ORDER/cancel" -H 'Accept: application/json' -H 'X-Customer-Id: 1'
```

**5) Hata örnekleri**

```sh
# 401: müşteri belirtilmedi
curl -i "http://localhost:8080/api/orders/$ORDER" -H 'Accept: application/json'

# 404: aynı mağazadaki başka bir müşteri (3) bu siparişi göremez
curl -i "http://localhost:8080/api/orders/$ORDER" -H 'Accept: application/json' -H 'X-Customer-Id: 3'

# 422: Masa Lambası (4) stokta 1 adet var, 2 adet istenemez
curl -i -X POST http://localhost:8080/api/orders \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H 'X-Customer-Id: 1' \
  -H 'Idempotency-Key: 9a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d' \
  -d '{"items":[{"product_id":4,"quantity":2}]}'
```

### PowerShell ile kullanım (Windows)

PowerShell'de JSON içeren curl komutlarında tırnak sorunu yaşanabilir. `Invoke-RestMethod` daha rahattır:

```powershell
$base = 'http://localhost:8080/api'
$headers = @{ 'Accept' = 'application/json'; 'X-Customer-Id' = '1' }

# 1) Sipariş oluştur
$body = @{ items = @(@{ product_id = 1; quantity = 2 }, @{ product_id = 2; quantity = 1 }) } | ConvertTo-Json -Depth 5
$order = Invoke-RestMethod -Method Post -Uri "$base/orders" -ContentType 'application/json' -Body $body `
    -Headers ($headers + @{ 'Idempotency-Key' = [guid]::NewGuid().ToString() })
$orderNumber = $order.data.order_number
$order.data | Select-Object order_number, status, formatted_total

# 2) Ödemeyi başlat
Invoke-RestMethod -Method Post -Uri "$base/orders/$orderNumber/payment" -ContentType 'application/json' `
    -Body '{"scenario":"success"}' -Headers $headers

# 3) Birkaç saniye sonra durumu kontrol et
Start-Sleep -Seconds 8
(Invoke-RestMethod -Uri "$base/orders/$orderNumber" -Headers $headers).data | Select-Object status, formatted_total
```

---

## 3. Mock Ödeme Sağlayıcısı

Gerçek bir ödeme entegrasyonu yok. Sağlayıcının davranışı, ödeme isteğindeki `scenario` alanıyla seçilir:

| `scenario` | Ne olur? |
| --- | --- |
| `success` | Ödeme başarılı, sipariş `ready_for_processing` olur |
| `declined` | Kart reddedilir, stok geri bırakılır, sipariş `payment_failed` olur |
| `timeout` | İlk denemede para çekilmez ve yanıt gelmez. Sistem önce sorgular, sonra aynı anahtarla tekrar dener |
| `timeout_after_charge` | Para çekilir ama yanıt kaybolur. Sorgu tek tahsilatı bulur, ikinci kez para çekilmez |
| `unavailable` | Sağlayıcı sürekli kapalı. 5 denemeden sonra `requires_review` durumuna geçer |
| `refund_timeout` | İade yapılır ama yanıt kaybolur. Tekrar deneme aynı sonucu verir |

- Senaryo seçimi yalnızca `local` ve `testing` ortamında açıktır; production'da sadece `success` kabul edilir.
- Kart numarası ve CVV alanları kabul edilmez (`422`).
- `mock_transactions` tablosu sağlayıcının kendi kayıtlarını taklit eder.

---

## 4. Hata Durumlarında Sistem Nasıl Toparlanır?

| Durum | Sistemin davranışı |
| --- | --- |
| Kesin ret, ödeme başlamadan iptal, 15 dakika içinde ödenmeyen sipariş | Stok **bir kez** geri eklenir, sipariş iptal olur |
| Zaman aşımı | Sipariş `payment_pending_confirmation` olur. "Para çekilmedi" varsayılmaz; stok korunur, sağlayıcı sorgulanır |
| Ödeme sürerken iptal | Sipariş `cancellation_pending` olur. Para çekildiyse önce iade yapılır, sonra stok bırakılır |
| 5 başarısız otomatik deneme | `requires_review`. Denemeler arasında giderek artan bekleme ve küçük rastgele gecikme vardır. Sonuç belirsizse stok tutulur, operatör incelemesi beklenir |
| Worker çökmesi | 60 saniyelik sahiplik süresi dolar; dakikalık recovery işi ödemeyi yeniden kuyruğa alır. Yeni worker önce sağlayıcıyı sorgular. Eski worker'ın geç gelen yanıtı yeni sonucu ezemez |

Kesinti giderilip işlem incelendikten sonra operatör şu komutları kullanabilir:

```sh
# İncelemedeki ödeme için sağlayıcıyı yeniden sorgula
docker compose exec app php artisan payments:reconcile PAYMENT_NUMBER --store=1

# Süresi dolan siparişleri ve bekleyen ödemeleri hemen toparla
docker compose exec app php artisan orders:recover --store=1

# Outbox'taki mesajları hemen kuyruğa gönder
docker compose exec app php artisan outbox:publish --store=1
```

`payments:reconcile` ödeme sonucunu elle değiştirmez, sadece yeni bir sağlayıcı sorgusu planlar.

---

## 5. Log ve Metrikler

**Loglar**

```sh
docker compose logs -f worker scheduler
```

- Loglar JSON formatındadır; hem `storage/logs/workflow-YYYY-MM-DD.log` dosyasına hem stderr'e yazılır, dosyalar 14 gün saklanır.
- Sabit olay adları: `order.created`, `order.transitioned`, `payment.attempt_finished`, `payment.requires_review`, `circuit.opened`, `http.completed`
- Her yanıtta `X-Request-ID` header'ı vardır. İstekte geçerli bir UUID gönderilirse o korunur, yoksa sunucu üretir. Bu kimlik ödeme kaydına ve worker loglarına taşınır.
- Loglara yalnızca izin verilen alanlar yazılır (kimlikler, durumlar, hata kodu, süre). Header, body, parola, kart bilgisi, ham SQL ve exception mesajı loglanmaz.

**Metrikler**

```sh
curl http://localhost:8080/api/metrics
```

Her metrik `store_id` etiketiyle mağaza bazında döner:

- `orders_current`, `payments_current`: duruma göre sipariş/ödeme sayısı
- `payment_attempts_current`: toplam deneme sayısı
- `outbox_pending`, `outbox_oldest_age_seconds`: kuyruğa gönderilmeyi bekleyen mesajlar ve en eskisinin yaşı

Alarm için önerilenler: `payments_current{status="requires_review"} > 0` ve sürekli büyüyen `outbox_oldest_age_seconds`. İnceleme bekleyen ödeme varsa scheduler beş dakikada bir hata logu da yazar. Prometheus/Grafana sunucusu kurulmadı; endpoint scrape'e hazırdır.

---

## 6. Testler

```sh
# 1) Birim ve API testleri (SQLite, bellekte)
docker compose exec app php artisan test --compact

# 2) Eşzamanlılık ve altyapı testleri (ayrı MySQL, gerçek RabbitMQ ve Redis)
docker compose --profile test run --rm test

# 3) Kod stili kontrolü
docker compose exec app vendor/bin/pint --test
```

- **1. grup** sipariş/ödeme kurallarını, idempotency'yi, iptal/iade akışlarını, müşteri izolasyonunu, circuit breaker'ı ve log maskelemesini test eder. Ayrıca demo verisinin tekrar çalıştırıldığında çoğalmadığını ve production'da yüklenmediğini, model getter/setter'larının da doğru çalıştığını kontrol eder.
- **2. grup** geçici `mysql-test` container'ındaki `order_payment_test` veritabanında çalışır; asıl `order_payment` veritabanına dokunmaz (başka veritabanında migration çalıştırmayı reddeder). Aynı anda çalışan gerçek PHP süreçleriyle son ürün için yarışı, tekrarlanan sipariş/ödeme isteklerini, mükerrer iş teslimini ve paralel iptal/iadeyi sınar. Çalıştırmadan önce normal kurulumun tamamlanmış olması (`vendor` klasörü) gerekir.
- Bunlar yük testi değildir; saniyede binlerce istek ölçülmüş gibi bir iddiada bulunulmamaktadır.

---

## 7. Varsayımlar

- **Kimlik doğrulama kapsam dışı.** Müşteri `X-Customer-Id` header'ı ile tanımlanır. Gerçek ortamda bunun yerini token/gateway tabanlı bir kimlik doğrulama alır; kod tarafında sadece `ResolveCustomer` middleware'i değişir.
- **Rol ayrımı yok.** Sipariş tamamlama (`complete`) ayrı bir operatör rolü olmadığı için, siparişle aynı mağazadaki bir kullanıcı tarafından yapılabilir. Metrik endpoint'i header istemez; production'da iç ağa kısıtlanmalıdır.
- Her müşteri tek bir mağazaya bağlıdır. Sepet yalnızca o mağazanın aktif ve TRY cinsinden ürünlerini içerebilir. En fazla 100 farklı ürün, ürün başına en fazla 100 adet.
- `completed` son durumdur; tamamlanmış sipariş iptal edilemez (`409`). Kargo sonrası iade ayrı bir süreçtir.
- Başarısız ödemeden sonra aynı siparişe yeni ödeme açılmaz; yeni sipariş oluşturulur. Tahsilat ve iade denemeleri aynı ödeme kaydı üzerinde ilerler.
- Mock sağlayıcı ağ beklemeden timeout fırlatır. Gerçek bir adapter'da bağlantı/yanıt süreleri 20 saniyelik worker sınırından kısa olmalı ve sağlayıcının idempotency süresi toparlanma penceresini kapsamalıdır.
- Demo, iki mağazayı ayrı kuyruk, worker ve scheduler ile çalıştırır. Yeni bir mağaza için ayrı kapasite tanımlanmalıdır.

## 8. Yapılmayanlar

- Kimlik doğrulama ve yetkilendirme (bkz. varsayımlar)
- Gerçek ödeme sağlayıcısı entegrasyonu ve webhook
- Kısmi iade, çoklu para birimi
- Kargo, bildirim, muhasebe entegrasyonları (`OrderReady` olayı bu adımlar için hazır çıkış noktasıdır)
- Yük testi, Prometheus/Grafana kurulumu
- Production için gerekenler: HTTPS reverse proxy (production modunda düz HTTP istekleri `426` ile reddedilir), güvenilir proxy ayarları, yeni sırlar, yedekleme ve MySQL/Redis/RabbitMQ için yüksek erişilebilirlik
