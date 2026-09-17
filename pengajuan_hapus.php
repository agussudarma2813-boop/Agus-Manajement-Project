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
    flash('Kamu tidak berhak menghapus pengajuan pada project ini.', 'err');
    redirect('pengajuan.php');
}

$item = item_pengajuan($id);
db()->prepare('DELETE FROM pengajuan_item WHERE pengajuan_id = ? AND perusahaan_id = ?')->execute([$id, tenant_id()]);
db()->prepare('DELETE FROM pengajuan WHERE id = ? AND perusahaan_id = ?')->execute([$id, tenant_id()]);
foreach ($item as $it) {
    tagih_sync_volume((int) $it['pekerjaan_id']);
}

flash('Pengajuan <strong>' . e((string) $row['nomor']) . '</strong> dihapus. Volumenya kembali menjadi sisa yang belum diajukan.', 'ok');
redirect('pengajuan.php');
