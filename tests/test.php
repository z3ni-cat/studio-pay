<?php
/** Tes PayCore: nominal unik, ingest idempoten, parser, expire. Payload SINTETIS (WARUNG CONTOH). */
require_once __DIR__ . '/../lib/paycore.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT DEFAULT 0,
    ext_id TEXT DEFAULT \'\', amount INT NOT NULL, metode TEXT DEFAULT \'qris_own\',
    qris_string TEXT, status TEXT DEFAULT \'pending\', ket TEXT DEFAULT \'\',
    paid_at DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE mutasi_log (id INTEGER PRIMARY KEY AUTOINCREMENT, ref TEXT UNIQUE,
    amount INT DEFAULT 0, invoice_id INT DEFAULT 0, matched INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');

// QRIS statis SINTETIS (merchant fiktif "WARUNG CONTOH", DANA dummy) — struktur valid + CRC benar
function tlv(string $tag, string $val): string { return $tag . sprintf('%02d', strlen($val)) . $val; }
$akun  = tlv('00', 'COM.DANA.WWW') . tlv('01', '93600914123456789012') . tlv('02', 'DANA543210') . tlv('03', 'UMI');
$akun2 = tlv('00', 'ID.CO.QRIS.WWW') . tlv('02', 'ID1029998887776');
$body  = tlv('00', '01') . tlv('01', '11')
       . tlv('26', $akun) . tlv('51', $akun2)
       . tlv('52', '5499') . tlv('53', '360')
       . tlv('58', 'ID') . tlv('59', 'WARUNG CONTOH') . tlv('60', 'BANDUNG') . tlv('61', '40111')
       . tlv('62', tlv('07', 'A01'))
       . '6304';
$QRIS = $body . crc16_ccitt_false($body);

$pay = new PayCore($pdo, $QRIS);
$fails = 0;
function cek($nama, $ok) { global $fails; echo ($ok ? 'OK  ' : 'FAIL') . " $nama\n"; if (!$ok) $fails++; }

// 1. nominal unik untuk 2 invoice harga sama
$i1 = $pay->buatInvoice(1, 1000, 't1');
$i2 = $pay->buatInvoice(1, 1000, 't2');
cek('nominal unik 1001-1099 & beda', $i1['ok'] && $i2['ok'] && $i1['nominal'] !== $i2['nominal']
    && $i1['nominal'] >= 1001 && $i1['nominal'] <= 1099);

// QR dinamis valid & nominal terpasang
$els = qris_tlv_parse($i1['qris']);
cek('QR dinamis valid, nominal = ' . $i1['nominal'],
    qris_validasi($i1['qris'])['ok'] && qris_describe($els)['nominal'] === (string) $i1['nominal']);

// 2. ingest match (format notif asli GoPay)
$r = $pay->ingest('Pembayaran QRIS diterima | Rp ' . $i2['nominal'] . ' di WARUNG CONTOH', null, 'REF-A');
cek('ingest match', !empty($r['matched']));

// 3. idempoten
$r2 = $pay->ingest('Pembayaran QRIS diterima | Rp ' . $i2['nominal'] . ' di WARUNG CONTOH', null, 'REF-A');
cek('replay idempoten', $r2['msg'] === 'Ref sudah pernah diproses (idempoten)');

// 4. parser
cek('parser desimal 1.250.750,00', PayCore::parseNotifAmount('menerima Rp1.250.750,00') === 1250750);
cek('parser 150.000', PayCore::parseNotifAmount('DANA MASUK Rp150.000 dari purchase') === 150000);

// 5. expire
$pdo->exec("UPDATE invoices SET created_at = datetime('now', '-90 minutes') WHERE id=" . $i1['invoice_id']);
$n = $pay->expirePending(60);
cek('expire pending', $n >= 1);

// 6. nominal tak dikenal -> dicatat unmatched
$r3 = $pay->ingest('Dana masuk Rp777.777', null, 'REF-B');
cek('unknown dicatat', empty($r3['matched']));

echo $fails ? "\n{$fails} FAIL\n" : "\nSEMUA OK\n";
exit($fails ? 1 : 0);
