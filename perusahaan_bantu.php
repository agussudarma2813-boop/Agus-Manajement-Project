<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';

$u = require_owner();
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

$alasan = tenant_alasan_tutup($row);
if ($alasan !== '') {
    flash('Tidak bisa masuk: ' . e($alasan), 'err');
    redirect('perusahaan.php');
}

$_SESSION['as_perusahaan'] = $id;
flash('Anda masuk sebagai perusahaan <strong>' . e($row['nama']) . '</strong> (mode bantu). Data yang tampil sekarang milik mereka.', 'info');
redirect('dashboard.php');
