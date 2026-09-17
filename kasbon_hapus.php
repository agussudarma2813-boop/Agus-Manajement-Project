<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';

require_role(['admin', 'pelaksana']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('kasbon.php');
}
csrf_check();

$id = (int) ($_POST['id'] ?? 0);
$row = get_kasbon($id);
if (!$row) {
    flash('Kasbon tidak ditemukan.', 'err');
    redirect('kasbon.php');
}
if ($row['penggajian_id'] !== null) {
    flash('Kasbon ini sudah dipotong pada sebuah penggajian. Hapus penggajiannya dulu bila memang salah.', 'err');
    redirect('kasbon.php');
}

db()->prepare('DELETE FROM kasbon WHERE id = ? AND perusahaan_id = ?')->execute([$id, tenant_id()]);
flash('Kasbon sebesar <strong>' . e(rupiah($row['nominal'])) . '</strong> tanggal ' . e(tgl($row['tanggal'])) . ' telah dihapus.', 'ok');
redirect('kasbon.php');
