<?php
/**
 * lib_qris.php — REA QRIS: parser, validator, konverter statis→dinamis
 * Implementasi spesifikasi publik EMVCo QR Code (EMV QRCPS-B) + profil QRIS Indonesia (MPM).
 * Format: TLV [tag 2 digit][len 2 digit][value], CRC16-CCITT-FALSE (tag 63).
 * Garis integritas: tag rekening merchant (26-51) TIDAK PERNAH diubah — hanya penyajian
 * (metode + nominal). Bukan payment gateway: dana tidak pernah melalui alat ini.
 * PHP >= 7.4, tanpa dependensi.
 */

/** CRC16-CCITT-FALSE (poly 0x1021, init 0xFFFF). Check value: "123456789" -> 29B1 */
function crc16_ccitt_false(string $s): string {
    $crc = 0xFFFF;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $crc ^= ord($s[$i]) << 8;
        for ($b = 0; $b < 8; $b++) {
            $crc = ($crc & 0x8000) ? ((($crc << 1) ^ 0x1021) & 0xFFFF) : (($crc << 1) & 0xFFFF);
        }
    }
    return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
}

/** Parse string TLV jadi daftar elemen ['tag','val','nested']; null kalau rusak. */
function qris_tlv_parse(string $s, int $depth = 0): ?array {
    $out = [];
    $i = 0;
    $n = strlen($s);
    while ($i + 4 <= $n) {
        $tag = substr($s, $i, 2);
        if (!ctype_digit($tag)) return null;
        $len = (int)substr($s, $i + 2, 2);
        if (substr($s, $i + 2, 2) !== sprintf('%02d', $len)) return null; // len harus 2 digit kanonik
        $i += 4;
        if ($i + $len > $n) return null;
        $val = substr($s, $i, $len);
        $i += $len;
        $el = ['tag' => $tag, 'val' => $val, 'nested' => null];
        $t = (int)$tag;
        if ($depth < 2 && $t >= 26 && $t <= 51 && $val !== '') {
            $try = qris_tlv_parse($val, $depth + 1);
            if ($try !== null && qris_tlv_encode($try) === $val) {
                $el['nested'] = $try;
            }
        }
        $out[] = $el;
    }
    return ($i === $n && $out) ? $out : null;
}

/** Encode balik daftar elemen jadi string TLV. */
function qris_tlv_encode(array $els): string {
    $s = '';
    foreach ($els as $e) {
        $l = strlen($e['val']);
        if ($l > 99) return ''; // guard: nilai >99 byte di luar spesifikasi
        $s .= $e['tag'] . sprintf('%02d', $l) . $e['val'];
    }
    return $s;
}

/** Ringkasan manusiawi dari daftar elemen (rekursif utk tag akun 26-51). */
function qris_describe(array $els): array {
    $d = [];
    foreach ($els as $e) {
        switch ($e['tag']) {
            case '00': $d['format'] = $e['val']; break;
            case '01': $d['metode'] = ($e['val'] === '12') ? 'dinamis' : 'statis'; break;
            case '52': $d['mcc'] = $e['val']; break;
            case '53': $d['mata_uang'] = $e['val']; break;
            case '54': $d['nominal'] = $e['val']; break;
            case '58': $d['negara'] = $e['val']; break;
            case '59': $d['nama_merchant'] = $e['val']; break;
            case '60': $d['kota'] = $e['val']; break;
            case '61': $d['kode_pos'] = $e['val']; break;
            case '63': $d['crc'] = $e['val']; break;
            case '26': case '51':
                $d['akun'][] = ($e['nested'] !== null)
                    ? qris_describe($e['nested'])
                    : ['raw' => $e['val']];
                break;
            default:
                if ($e['nested'] !== null) { $d['akun'][] = qris_describe($e['nested']); }
        }
    }
    return $d;
}

/** Validasi struktur + CRC. Return ['ok'=>bool, 'error'=>?string, 'els'=>?array]. */
function qris_validasi(string $qris): array {
    // hanya buang newline/tab (artefak copy-paste) — SPASI DI DALAM VALUE ADALAH DATA SAH
    // (mis. "WARUNG MAJU JAYA"); menghapusnya merusak panjang TLV.
    $qris = trim(preg_replace('/[\r\n\t]/', '', $qris));
    if (strlen($qris) < 20) return ['ok' => false, 'error' => 'payload terlalu pendek', 'els' => null];
    $crc = strtoupper(substr($qris, -4));
    // parse FULL string: elemen terakhir = tag 63, valuenya = CRC itu sendiri.
    // (CRC dihitung atas payload+`6304`, yaitu string ini minus 4 karakter terakhir.)
    $els = qris_tlv_parse($qris);
    if ($els === null) return ['ok' => false, 'error' => 'struktur TLV rusak', 'els' => null];
    if (end($els)['tag'] !== '63') return ['ok' => false, 'error' => 'tag CRC 6304 tidak di akhir', 'els' => $els];
    $hit = crc16_ccitt_false(substr($qris, 0, -4));
    if ($hit !== $crc) return ['ok' => false, 'error' => "CRC tidak cocok (hitung $hit vs $crc)", 'els' => $els];
    return ['ok' => true, 'error' => null, 'els' => $els];
}

/**
 * Konversi QRIS statis -> dinamis (set tag 01=12, sisip/replace tag 54 nominal).
 * $nominal: angka "25000" atau "25000.5". Tag 26-51 dijamin byte-identik.
 * Return ['ok', 'error'?, 'qris'?, 'nominal'?].
 */
function qris_statis_ke_dinamis(string $qris, string $nominal): array {
    $qris = trim(preg_replace('/[\r\n\t]/', '', $qris));
    $v = qris_validasi($qris);
    if (!$v['ok']) return ['ok' => false, 'error' => 'input bukan QRIS valid: ' . $v['error']];
    if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $nominal)) return ['ok' => false, 'error' => 'nominal tidak valid'];
    // format mengikuti praktik produksi: bulat -> integer ("25000"), desimal -> 2 tempat ("25000.50")
    $amount = preg_match('/^\d+$/', $nominal) ? $nominal : number_format((float)$nominal, 2, '.', '');

    // fingerprint tag rekening (26-51) sebelum & sesudah — wajib identik
    $akun_before = '';
    foreach ($v['els'] as $e) { $t = (int)$e['tag']; if ($t >= 26 && $t <= 51) $akun_before .= $e['tag'] . $e['val'] . '|'; }

    $out = [];
    $put54 = false;
    foreach ($v['els'] as $e) {
        if ($e['tag'] === '63') continue;              // CRC dihitung ulang
        if ($e['tag'] === '54') continue;              // nominal lama dibuang
        if ($e['tag'] === '01') $e['val'] = '12';      // statis -> dinamis
        $out[] = $e;
        if ($e['tag'] === '53' && !$put54) {           // posisi kanonik: setelah 53 (currency)
            $out[] = ['tag' => '54', 'val' => $amount, 'nested' => null];
            $put54 = true;
        }
    }
    if (!$put54) { // fallback: sebelum tag 58, atau di akhir
        $tmp = []; $done = false;
        foreach ($out as $e) {
            if ($e['tag'] === '58' && !$done) { $tmp[] = ['tag' => '54', 'val' => $amount, 'nested' => null]; $done = true; }
            $tmp[] = $e;
        }
        if (!$done) $tmp[] = ['tag' => '54', 'val' => $amount, 'nested' => null];
        $out = $tmp;
    }

    $akun_after = '';
    foreach ($out as $e) { $t = (int)$e['tag']; if ($t >= 26 && $t <= 51) $akun_after .= $e['tag'] . $e['val'] . '|'; }
    if ($akun_before !== $akun_after) return ['ok' => false, 'error' => 'integritas: tag rekening berubah — dibatalkan'];

    $payload = qris_tlv_encode($out);
    if ($payload === '') return ['ok' => false, 'error' => 'encode gagal (nilai >99 byte?)'];
    $s = $payload . '6304';
    return ['ok' => true, 'error' => null, 'qris' => $s . crc16_ccitt_false($s), 'nominal' => $amount];
}
