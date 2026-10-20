<?php
/**
 * PayCore — payment core ala QRIS Own (pola Sayabayar Pro, tanpa memegang dana)
 * PHP 7.4+, PDO MySQL. Tabel diasumsikan: invoices(id, ext_id, amount, metode,
 * status, created_at), mutasi_log(ref UNIQUE, amount, invoice_id, matched, created_at).
 * PRINSIP: dana SELALU langsung ke QRIS merchant sendiri. Core ini hanya membuat
 * QR dinamis + mencocokkan notifikasi -> status paid. BUKAN escrow/agregator.
 */
require_once __DIR__ . '/qris.php';

class PayCore
{
    /** @var PDO */
    private $pdo;
    /** @var string QRIS statis merchant (211 char contoh uji) */
    private $qrisStatic;
    /** @var int nominal maksimum; di atas ini suffix 1-99 tak lagi unik terhadap harga */
    private $maxBase;

    public function __construct(PDO $pdo, string $qrisStatic, int $maxBase = 214000)
    {
        $this->pdo = $pdo;
        $this->qrisStatic = $qrisStatic;
        $this->maxBase = $maxBase;
    }

    /** Nominal unik: harga + suffix 1-99 yang belum dipakai invoice pending. */
    public function nominalUnik(int $harga): int
    {
        $base = min($harga, $this->maxBase);
        $st = $this->pdo->prepare(
            "SELECT amount FROM invoices WHERE status='pending' AND amount BETWEEN ? AND ?"
        );
        $st->execute(array($base + 1, $base + 99));
        $dipakai = array();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $dipakai[(int) $r['amount']] = true;
        }
        for ($i = 1; $i <= 99; $i++) {
            $n = $base + (($i + (int) date('H')) % 99);
            if (empty($dipakai[$n])) return $n;
        }
        return $base + ((int) (microtime(true) * 10) % 99 + 1); // fallback: admin reconcile
    }

    /** Buat invoice + QR dinamis. Return array(ok, invoice_id, qris, nominal). */
    public function buatInvoice(int $userId, int $harga, string $ket = ''): array
    {
        $nominal = $this->nominalUnik($harga);
        $r = qris_statis_ke_dinamis($this->qrisStatic, (string) $nominal);
        if (empty($r['ok'])) {
            return array('ok' => false, 'error' => isset($r['error']) ? $r['error'] : 'QRIS gagal');
        }
        $ext = 'QR' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $st = $this->pdo->prepare(
            "INSERT INTO invoices (user_id, ext_id, amount, metode, qris_string, status, ket, created_at)
             VALUES (?,?,?,?,?, 'pending', ?, ?)"
        );
        $st->execute(array($userId, $ext, $nominal, 'qris_own', $r['qris'], mb_substr($ket, 0, 100), date('Y-m-d H:i:s')));
        return array('ok' => true, 'invoice_id' => (int) $this->pdo->lastInsertId(),
            'qris' => $r['qris'], 'nominal' => $nominal, 'ext_id' => $ext);
    }

    /** Parse nominal dari teks notifikasi (GoPay/bank). 0 = tidak ketemu. */
    public static function parseNotifAmount(string $text): int
    {
        $t = (string) $text;
        $t = preg_replace('/(\d)\.(\d{3})\.(\d{3}),\d{2}\b/', '$1$2$3', $t);
        $t = preg_replace('/(\d)\.(\d{3}),\d{2}\b/', '$1$2', $t);
        if (!preg_match_all('/(?:Rp|IDR)\s*\.?\s*([\d.,]+)/i', $t, $m)) return 0;
        $cands = array();
        foreach ($m[1] as $raw) {
            $v = preg_replace('/[^\d]/', '', $raw);
            if ($v !== '' && strlen($v) <= 9) $cands[] = (int) $v;
        }
        return $cands ? max($cands) : 0;
    }

    /** Ingest notifikasi: cocokkan nominal ke invoice pending. Idempoten via mutasi_log. */
    public function ingest(string $text, ?int $amount = null, ?string $ref = null): array
    {
        if ($amount === null || $amount <= 0) $amount = self::parseNotifAmount($text);
        if ($amount <= 0) return array('ok' => true, 'matched' => '', 'msg' => 'Nominal tidak terbaca');
        if ($ref === null || $ref === '') $ref = 'HP-' . substr(sha1($text), 0, 16);

        // ledger idempotensi global
        $st = $this->pdo->prepare('SELECT invoice_id, matched FROM mutasi_log WHERE ref=?');
        $st->execute(array($ref));
        if ($log = $st->fetch(PDO::FETCH_ASSOC)) {
            return array('ok' => true, 'matched' => $log['matched'] ? (string) (int) $log['invoice_id'] : '',
                'msg' => 'Ref sudah pernah diproses (idempoten)');
        }

        $st = $this->pdo->prepare(
            "SELECT id, ext_id FROM invoices WHERE status='pending' AND metode='qris_own' AND amount=? ORDER BY id ASC LIMIT 2"
        );
        $st->execute(array($amount));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $invId = 0; $ext = '';
        if (count($rows) === 1) {
            $invId = (int) $rows[0]['id'];
            $ext = $rows[0]['ext_id'];
            $this->pdo->prepare("UPDATE invoices SET status='paid', paid_at=? WHERE id=? AND status='pending'")
                ->execute(array(date('Y-m-d H:i:s'), $invId));
        }
        try {
            $this->pdo->prepare('INSERT INTO mutasi_log (ref, amount, invoice_id, matched, created_at) VALUES (?,?,?,?,?)')
                ->execute(array($ref, $amount, $invId, $invId > 0 ? 1 : 0, date('Y-m-d H:i:s')));
        } catch (Exception $e) { /* race: ref sudah ada */ }

        if (count($rows) === 0) return array('ok' => true, 'matched' => '', 'msg' => 'Tidak ada invoice pending nominal Rp' . number_format($amount, 0, ',', '.'));
        if (count($rows) > 1) return array('ok' => true, 'matched' => '', 'msg' => 'AMBIGU: nominal sama, selesaikan manual');
        return array('ok' => true, 'matched' => $ext, 'invoice_id' => $invId, 'msg' => 'PAID');
    }

    /** Expire invoice pending lebih tua dari $menit. */
    public function expirePending(int $menit = 60): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - $menit * 60);
        $st = $this->pdo->prepare("UPDATE invoices SET status='cancel' WHERE status='pending' AND created_at < ?");
        $st->execute(array($cutoff));
        return $st->rowCount();
    }
}
