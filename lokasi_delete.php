<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';

require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('projects.php');
}
csrf_check();

$id = (int) ($_POST['id'] ?? 0);
$lok = get_lokasi($id);
if (!$lok) {
    flash('Lokasi tidak ditemukan.', 'err');
    redirect('projects.php');
}

$project = get_project((int) $lok['project_id']);
if (!can_manage_project($project)) {
    flash('Kamu tidak berhak menghapus lokasi pada project ini.', 'err');
    redirect('project_detail.php?id=' . (int) $lok['project_id']);
}

db()->prepare('DELETE FROM lokasi WHERE id = ?')->execute([$id]);
flash('Lokasi <strong>' . e($lok['nama']) . '</strong> telah dihapus.', 'ok');
redirect('project_detail.php?id=' . (int) $lok['project_id']);
