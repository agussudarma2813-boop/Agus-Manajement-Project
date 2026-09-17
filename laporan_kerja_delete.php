<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';

$u = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('laporan_kerja.php');
}
csrf_check();

$id = (int) ($_POST['id'] ?? 0);
$row = get_laporan($id);
if (!$row) {
    flash('Laporan hasil kerja tidak ditemukan.', 'err');
    redirect('laporan_kerja.php');
}

$bolehAtur = in_array($u['role'], ['admin', 'pelaksana'], true)
    && can_manage_pekerjaan(get_pekerjaan((int) $row['pekerjaan_id']));
$milikSendiri = (int) $row['user_id'] === (int) $u['id'];
if (!$bolehAtur && !$milikSendiri) {
    flash('Kamu tidak berhak menghapus laporan ini.', 'err');
    redirect('laporan_kerja.php');
}

db()->prepare('DELETE FROM laporan_kerja WHERE id = ?')->execute([$id]);
sync_volume_realisasi((int) $row['pekerjaan_id']);

// Absensi yang dibuat otomatis oleh laporan ini ikut dibersihkan
$absenIkut = auto_absensi_hapus((int) $row['user_id'], (string) $row['tanggal'], (int) $row['pekerjaan_id']);

$st = db()->prepare('SELECT nama FROM users WHERE id = ? AND perusahaan_id = ?');
$st->execute([(int) $row['user_id'], tenant_id()]);
$nama = (string) ($st->fetchColumn() ?: 'Tenaga');

flash('Laporan hasil kerja <strong>' . e($nama) . '</strong> tanggal ' . e(tgl($row['tanggal'])) . ' telah dihapus. Volume akhir pekerjaan disesuaikan'
    . ($absenIkut ? ' dan absensi otomatis hari itu ikut dihapus.' : '.'), 'ok');
redirect('laporan_kerja.php?tanggal=' . $row['tanggal']);
