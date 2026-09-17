<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';

require_owner();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('perusahaan.php');
}
csrf_check();

$id = (int) ($_POST['id'] ?? 0);
$row = get_perusahaan($id);
if (!$row) {
    flash('Perusahaan tidak ditemukan.', 'err');
    redirect('perusahaan.php');
}
if (tenant_id() === $id) {
    flash('Perusahaan yang sedang Anda pakai tidak bisa dihapus. Keluar dari mode bantu dulu.', 'err');
    redirect('perusahaan.php');
}

$konfirmasi = trim((string) ($_POST['konfirmasi'] ?? ''));
if ($konfirmasi !== $row['nama']) {
    flash('Untuk menghapus, nama perusahaan harus diketik sama persis.', 'err');
    redirect('perusahaan.php');
}

// Hapus berantai: data anak dulu, baru project & user perusahaan tersebut.
db()->prepare('DELETE FROM penggajian_item WHERE perusahaan_id = ?')->execute([$id]);
db()->prepare('DELETE FROM kasbon WHERE perusahaan_id = ?')->execute([$id]);
db()->prepare('DELETE FROM penggajian WHERE perusahaan_id = ?')->execute([$id]);
db()->prepare('DELETE FROM pengajuan_item WHERE perusahaan_id = ?')->execute([$id]);
db()->prepare('DELETE FROM pengajuan WHERE perusahaan_id = ?')->execute([$id]);
db()->prepare('DELETE FROM laporan_kerja WHERE perusahaan_id = ?')->execute([$id]);
db()->prepare('DELETE FROM absensi WHERE perusahaan_id = ?')->execute([$id]);
db()->prepare('DELETE FROM jadwal WHERE perusahaan_id = ?')->execute([$id]);
db()->prepare('DELETE FROM progress_log WHERE pekerjaan_id IN (SELECT id FROM pekerjaan WHERE perusahaan_id = ?)')->execute([$id]);
db()->prepare('DELETE FROM pekerjaan_pekerja WHERE perusahaan_id = ?')->execute([$id]);
db()->prepare('DELETE FROM pekerjaan WHERE perusahaan_id = ?')->execute([$id]);
db()->prepare('DELETE FROM lokasi WHERE perusahaan_id = ?')->execute([$id]);
db()->prepare('DELETE FROM projects WHERE perusahaan_id = ?')->execute([$id]);
db()->prepare('DELETE FROM harga_satuan WHERE perusahaan_id = ?')->execute([$id]);
db()->prepare('DELETE FROM users WHERE perusahaan_id = ?')->execute([$id]);
db()->prepare('DELETE FROM perusahaan WHERE id = ?')->execute([$id]);

flash('Perusahaan <strong>' . e($row['nama']) . '</strong> beserta seluruh datanya telah dihapus.', 'ok');
redirect('perusahaan.php');
