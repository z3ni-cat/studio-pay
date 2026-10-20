<?php
/**
 * ingest.php — endpoint tunggal penerima notifikasi (dari listener HP / input manual)
 * POST: key, text=notif mentah  |  atau amount=angka+ref=string
 * Respon: JSON {ok, matched, msg}
 */
require_once __DIR__ . '/../lib/paycore.php';

header('Content-Type: application/json; charset=utf-8');

// ---- kredensial (GANTI) ----
$CONFIG = array(
    'db_dsn'  => 'mysql:host=localhost;dbname=pay;charset=utf8mb4',
    'db_user' => 'pay',
    'db_pass' => 'GANTI',
    'qris'    => '', // QRIS statis merchant (isikan sendiri; jangan commit punyamu)
    'keys'    => array('GANTI_KEY_LISTENER'),
);

try {
    $pdo = new PDO($CONFIG['db_dsn'], $CONFIG['db_user'], $CONFIG['db_pass'],
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
              PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => 'DB gagal'));
    exit;
}

$core = new PayCore($pdo, $CONFIG['qris']);

$got = isset($_POST['key']) ? $_POST['key'] : (isset($_GET['key']) ? $_GET['key'] : '');
$ok = false;
foreach ($CONFIG['keys'] as $k) {
    if ($k !== '' && $got !== '' && hash_equals($k, $got)) { $ok = true; break; }
}
if (!$ok) { echo json_encode(array('ok' => false, 'error' => 'Key tidak valid')); exit; }

// opsional: expire invoice telat bayar (murah, sekalian tiap ingest)
$core->expirePending(60);

$amount = isset($_POST['amount']) ? (int) $_POST['amount'] : (isset($_GET['amount']) ? (int) $_GET['amount'] : 0);
$ref    = isset($_POST['ref']) ? $_POST['ref'] : (isset($_GET['ref']) ? $_GET['ref'] : null);
$text   = isset($_POST['text']) ? $_POST['text'] : (isset($_GET['text']) ? $_GET['text'] : '');

// filter arah: hanya notif PENERIMAAN dana
if ($text !== '' && $amount <= 0) {
    if (!preg_match('/menerima|dana masuk|diterima|masuk ke/i', $text)
        || preg_match('/membayar|top.?up|penarikan|withdraw|berhasil dikirim/i', $text)) {
        echo json_encode(array('ok' => true, 'matched' => '', 'msg' => 'Skip: bukan notif penerimaan dana'));
        exit;
    }
}

echo json_encode($core->ingest($text, $amount > 0 ? $amount : null, $ref));
