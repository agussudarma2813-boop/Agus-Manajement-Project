<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';

require_role(['admin', 'pelaksana']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('absensi.php');
}
csrf_check();

$id = (int) ($_POST['id'] ?? 0);
$row = get_absensi($id);
if (!$row) {
    flash('Catatan absensi tidak ditemukan.', 'err');
    redirect('absensi.php');
}
if (!can_record_absensi(get_project((int) $row['project_id']))) {
    flash('Kamu tidak berhak menghapus catatan absensi pada project ini.', 'err');
    redirect('absensi.php');
}

$st = db()->prepare('SELECT nama FROM users WHERE id = ? AND perusahaan_id = ?');
$st->execute([(int) $row['user_id'], tenant_id()]);
$nama = (string) ($st->fetchColumn() ?: 'Pekerja');

db()->prepare('DELETE FROM absensi WHERE id = ?')->execute([$id]);
flash('Catatan kehadiran <strong>' . e($nama) . '</strong> tanggal ' . e(tgl($row['tanggal'])) . ' telah dihapus.', 'ok');
redirect('absensi.php?dari=' . $row['tanggal'] . '&sampai=' . $row['tanggal']);
