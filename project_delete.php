<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';

$u = require_role(['admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('projects.php');
}
csrf_check();

$id = (int) ($_POST['id'] ?? 0);
$project = get_project($id);
if (!$project) {
    flash('Project tidak ditemukan.', 'err');
    redirect('projects.php');
}

db()->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
flash('Project <strong>' . e($project['nama']) . '</strong> beserta seluruh datanya telah dihapus.', 'ok');
redirect('projects.php');
