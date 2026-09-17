<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';

require_role(['admin', 'pelaksana']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pengajuan.php');
}
csrf_check();

$id = (int) ($_POST['id'] ?? 0);
$row = get_pengajuan($id);
if (!$row) {
    flash('Pengajuan tidak ditemukan.', 'err');
    redirect('pengajuan.php');
}
if (!can_manage_project(get_project((int) $row['project_id']))) {
    flash('Kamu tidak berhak mengubah pengajuan pada project ini.', 'err');
    redirect('pengajuan.php');
}

$status = (string) ($_POST['status'] ?? '');
if (!in_array($status, ['diajukan', 'dibayar'], true)) {
    flash('Status pengajuan tidak dikenal.', 'err');
    redirect('pengajuan_detail.php?id=' . $id);
}

$tanggalBayar = $status === 'dibayar'
    ? (valid_tanggal((string) ($_POST['tanggal_bayar'] ?? '')) ?: date('Y-m-d'))
    : '';

db()->prepare('UPDATE pengajuan SET status = ?, tanggal_bayar = ? WHERE id = ? AND perusahaan_id = ?')
    ->execute([$status, $tanggalBayar, $id, tenant_id()]);

flash('Pengajuan <strong>' . e((string) $row['nomor']) . '</strong> ditandai <strong>'
    . ($status === 'dibayar' ? 'Sudah Dibayar' : 'Sudah Diajukan (belum dibayar)') . '</strong>.');
redirect('pengajuan_detail.php?id=' . $id);
