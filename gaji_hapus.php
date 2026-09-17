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

// Kasbon yang tadinya dipotong dikembalikan menjadi kasbon aktif
db()->prepare('UPDATE kasbon SET penggajian_id = NULL WHERE penggajian_id = ? AND perusahaan_id = ?')
    ->execute([$id, tenant_id()]);
db()->prepare('DELETE FROM penggajian_item WHERE penggajian_id = ? AND perusahaan_id = ?')->execute([$id, tenant_id()]);
db()->prepare('DELETE FROM penggajian WHERE id = ? AND perusahaan_id = ?')->execute([$id, tenant_id()]);

flash('Penggajian <strong>' . e((string) $row['pekerja_nama']) . '</strong> dihapus. Kasbon yang sempat dipotong kembali menjadi kasbon aktif.', 'ok');
redirect('gaji.php');
