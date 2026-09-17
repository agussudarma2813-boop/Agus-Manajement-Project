<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';

require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pekerjaan.php');
}
csrf_check();

$id = (int) ($_POST['id'] ?? 0);
$pj = get_pekerjaan($id);
if (!$pj) {
    flash('Pekerjaan tidak ditemukan.', 'err');
    redirect('pekerjaan.php');
}
if (!can_manage_pekerjaan($pj)) {
    flash('Kamu tidak berhak menghapus pekerjaan ini.', 'err');
    redirect('pekerjaan_detail.php?id=' . $id);
}

db()->prepare('DELETE FROM pekerjaan WHERE id = ?')->execute([$id]);
flash('Pekerjaan <strong>' . e($pj['nama']) . '</strong> telah dihapus.', 'ok');
redirect('project_detail.php?id=' . (int) $pj['project_id']);
