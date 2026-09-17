<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';

require_role(['admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('harga_satuan.php');
}
csrf_check();

$id = (int) ($_POST['id'] ?? 0);
$item = get_harga_satuan($id);
if (!$item) {
    flash('Item harga tidak ditemukan.', 'err');
    redirect('harga_satuan.php');
}

// Pekerjaan yang memakai item ini tetap menyimpan harga yang sudah dicatat,
// hanya keterkaitan ke master yang dilepas (ON DELETE SET NULL).
db()->prepare('DELETE FROM harga_satuan WHERE id = ?')->execute([$id]);
flash('Item harga <strong>' . e($item['nama']) . '</strong> telah dihapus. Pekerjaan yang sudah memakainya tidak berubah.', 'ok');
redirect('harga_satuan.php');
