<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';

require_role(['admin', 'pelaksana']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('jadwal_harian.php');
}
csrf_check();

$id = (int) ($_POST['id'] ?? 0);
$row = get_jadwal($id);
if (!$row) {
    flash('Jadwal tidak ditemukan.', 'err');
    redirect('jadwal_harian.php');
}
if (!can_manage_project(get_project((int) $row['project_id']))) {
    flash('Kamu tidak berhak menghapus jadwal pada project ini.', 'err');
    redirect('jadwal_harian.php');
}

db()->prepare('DELETE FROM jadwal WHERE id = ?')->execute([$id]);
flash('Jadwal <strong>' . e($row['pekerja_nama']) . '</strong> tanggal ' . e(tgl($row['tanggal'])) . ' telah dihapus. Hasil kerja yang sudah dilaporkan tetap tersimpan.', 'ok');
redirect('jadwal_harian.php?tanggal=' . $row['tanggal']);
