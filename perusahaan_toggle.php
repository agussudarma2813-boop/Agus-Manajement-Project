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

$baru = (int) $row['aktif'] === 1 ? 0 : 1;
db()->prepare('UPDATE perusahaan SET aktif = ? WHERE id = ?')->execute([$baru, $id]);

flash('Perusahaan <strong>' . e($row['nama']) . '</strong> ' . ($baru ? 'diaktifkan kembali.' : 'dinonaktifkan — akun mereka tidak bisa login sampai diaktifkan lagi.'));
redirect('perusahaan.php');
