# studio-pay

**Payment core QRIS "QRIS Own"** — pola yang dipakai [Zenicat Studio AI](https://zenicat.net/studio/) untuk terima pembayaran otomatis **tanpa perantara**: dana selalu masuk langsung ke QRIS merchant-mu sendiri, core ini hanya membuat QR dinamis + mencocokkan notifikasi pembayaran.

Terinspirasi pola komersial ala Sayabayar Pro (kode unik di nominal + verifikasi notifikasi), dibangun ulang sebagai library kecil yang bisa kamu selipkan ke sistem apa pun: Studio AI, toko online, bot WA/TG, auto-order.

```
Pembeli scan QRIS (dinamis, nominal unik)
  → dana masuk LANGSUNG ke QRIS pribadi/merchant-mu (Rp0 fee untuk mikro, MDR 0%)
  → notif e-wallet muncul di HP (GoPay/dll)
  → listener (AutoJs6, lihat listener/) POST teks notif ke server
  → PayCore cocokkan nominal unik → invoice PAID
  → sistemmu lanjut (kirim produk / jalankan generate / dll)
```

**Bukan payment gateway, bukan escrow, bukan agregator.** Core tidak memegang, memindahkan, atau menahan dana — hanya membaca status. Tidak butuh lisensi PJP.

## Isi repo

```
lib/qris.php         Engine TLV QRIS: statis → dinamis + nominal (paritas produksi)
lib/paycore.php      PayCore: nominal unik, buat invoice, ingest notif, expire
listener/gas9_studio.js  Listener AutoJs6 di HP: notif e-wallet → POST ke server
api-example/ingest.php   Endpoint penerima notifikasi (dengan filter arah + key)
panel/admin_bayar.php    Panel konfirmasi manual (fallback bila HP mati)
sql/schema.sql       Skema minimal (invoices + mutasi_log idempotensi)
tests/test.php       6 asersi: nominal unik, ingest, idempoten, parser, expire
```

## Konsep kunci

**Nominal unik** — harga Rp1.000 → invoice diberi suffix Rp1–99 (Rp1.037, Rp1.052, …). Nominal itulah "kode unik" pembayaran: notif yang masuk dicocokkan ke invoice pending bernominal sama. Maks ±99 invoice pending bersamaan.

**Ledger idempotensi** — tiap notifikasi masuk dicatat ke `mutasi_log` dengan ref unik (hash isi notif). Notif yang dikirim ulang Android **tidak pernah** diproses dua kali, bahkan jika invoice sudah paid.

**Filter arah** — notif *membayar / top up / penarikan* diabaikan; hanya *menerima dana* yang diproses.

**Expire** — invoice pending lebih tua dari 60 menit dicancel otomatis; nominalnya bebas dipakai lagi.

## Instalasi

```bash
git clone https://github.com/z3ni-cat/studio-pay.git
# 1. buat DB + tabel: mysql < sql/schema.sql
# 2. isi kredensial di api-example/ingest.php & panel/admin_bayar.php
# 3. pasang QRIS statis-mu sendiri di $CONFIG['qris']
# 4. tes: php tests/test.php
# 5. HP: install AutoJs6 → isi SERVER_URL + INGEST_KEY di listener → Run
```

Demo hidup: [zenicat.net/tools/qris/](https://zenicat.net/tools/qris/) (konverter statis→dinamis di browser).

## Persyaratan

- PHP ≥ 7.4, PDO (MySQL di produksi; SQLite bisa untuk uji)
- AutoJs6 v6.6+ di Android (listener), izin Notification access
- QRIS statis milikmu sendiri (bank/e-wallet mana pun)

## Lisensi

MIT — lihat [LICENSE](LICENSE).
