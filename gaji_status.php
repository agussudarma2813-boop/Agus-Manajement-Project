<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';

require_role(['admin', 'pelaksana']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('gaji.php');
}
csrf_check();

$id = (int) ($_POST['id'] ?? 0);
$row = get_penggajian($id);
if (!$row) {
    flash('Penggajian tidak ditemukan.', 'err');
    redirect('gaji.php');
}

$status = (string) ($_POST['status'] ?? '');
if (!in_array($status, ['belum', 'dibayar'], true)) {
    flash('Status tidak dikenal.', 'err');
    redirect('gaji_detail.php?id=' . $id);
}

$bayar = $status === 'dibayar' ? (valid_tanggal((string) ($_POST['tanggal_bayar'] ?? '')) ?: date('Y-m-d')) : '';
db()->prepare('UPDATE penggajian SET status = ?, tanggal_bayar = ? WHERE id = ? AND perusahaan_id = ?')
    ->execute([$status, $bayar, $id, tenant_id()]);

flash('Gaji <strong>' . e((string) $row['pekerja_nama']) . '</strong> periode ' . e(tgl($row['periode_dari']) . ' – ' . tgl($row['periode_sampai']))
    . ' ditandai <strong>' . ($status === 'dibayar' ? 'SUDAH DIBAYAR' : 'belum dibayar') . '</strong>.');
redirect('gaji_detail.php?id=' . $id);
