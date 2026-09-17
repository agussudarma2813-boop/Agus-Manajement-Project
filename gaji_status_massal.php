<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';

/**
 * Menandai BANYAK penggajian sekaligus (sudah dibayar / belum dibayar).
 * Dipakai dari halaman Gaji lewat checkbox, supaya tidak perlu buka satu-satu.
 */
require_role(['admin', 'pelaksana']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('gaji.php');
}
csrf_check();

$status = (string) ($_POST['status'] ?? '');
if (!in_array($status, ['dibayar', 'belum'], true)) {
    flash('Status tidak dikenal.', 'err');
    redirect('gaji.php');
}

$idPilih = array_values(array_unique(array_map('intval', (array) ($_POST['gaji'] ?? []))));
$kembali = trim((string) ($_POST['kembali'] ?? ''));
if ($kembali === '' || str_contains($kembali, '://')) {
    $kembali = 'gaji.php';
}
if (!$idPilih) {
    flash('Centang dulu gaji yang mau ditandai.', 'err');
    redirect($kembali);
}

$tanggalBayar = valid_tanggal((string) ($_POST['tanggal_bayar'] ?? '')) ?: date('Y-m-d');

// Ambil hanya penggajian milik perusahaan ini
$tanda = implode(',', array_fill(0, count($idPilih), '?'));
$args = $idPilih;
$args[] = tenant_id();
$st = db()->prepare("SELECT id, user_id, status, total_dibayar FROM penggajian WHERE id IN ($tanda) AND perusahaan_id = ?");
$st->execute($args);
$boleh = [];
foreach ($st->fetchAll() as $r) {
    $boleh[(int) $r['id']] = $r;
}
if (!$boleh) {
    flash('Data penggajian tidak ditemukan pada perusahaan ini.', 'err');
    redirect($kembali);
}

$upd = db()->prepare('UPDATE penggajian SET status = ?, tanggal_bayar = ? WHERE id = ? AND perusahaan_id = ?');
$jml = 0;
$nilai = 0.0;
foreach ($boleh as $id => $r) {
    if ($r['status'] === $status) {
        continue; // sudah sesuai, tidak dihitung
    }
    $upd->execute([$status, $status === 'dibayar' ? $tanggalBayar : '', $id, tenant_id()]);
    $jml++;
    if ($status === 'dibayar') {
        $nilai += (float) $r['total_dibayar'];
    }
}

if ($jml === 0) {
    flash('Tidak ada perubahan — semua yang dicentang sudah berstatus sama.', 'info');
    redirect($kembali);
}

if ($status === 'dibayar') {
    flash('<strong>' . $jml . ' gaji</strong> ditandai <strong>SUDAH DIBAYAR</strong> (total ' . e(rupiah($nilai))
        . ', tanggal ' . e(tgl($tanggalBayar)) . ').');
} else {
    flash('<strong>' . $jml . ' gaji</strong> dikembalikan ke status <strong>Belum Dibayar</strong>.');
}
redirect($kembali);
