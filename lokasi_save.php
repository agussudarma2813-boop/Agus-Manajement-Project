<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';

require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('projects.php');
}
csrf_check();

$projectId = (int) ($_POST['project_id'] ?? 0);
$project = get_project($projectId);
if (!can_manage_project($project)) {
    flash('Kamu tidak berhak menambah lokasi pada project ini.', 'err');
    redirect('projects.php');
}

$nama = trim((string) ($_POST['nama'] ?? ''));
if ($nama === '') {
    flash('Nama lokasi wajib diisi.', 'err');
    redirect('project_detail.php?id=' . $projectId);
}

$id = (int) ($_POST['id'] ?? 0);
$args = [
    $nama,
    trim((string) ($_POST['alamat'] ?? '')),
    trim((string) ($_POST['keterangan'] ?? '')),
];

if ($id) {
    $lok = get_lokasi($id);
    if (!$lok || (int) $lok['project_id'] !== $projectId) {
        flash('Lokasi tidak ditemukan.', 'err');
        redirect('project_detail.php?id=' . $projectId);
    }
    $args[] = $id;
    db()->prepare('UPDATE lokasi SET nama=?, alamat=?, keterangan=? WHERE id=?')->execute($args);
    flash('Lokasi <strong>' . e($nama) . '</strong> berhasil diperbarui.');
} else {
    db()->prepare('INSERT INTO lokasi (nama, alamat, keterangan, project_id) VALUES (?,?,?,?)')
        ->execute([...$args, $projectId]);
    flash('Lokasi <strong>' . e($nama) . '</strong> berhasil ditambahkan.');
}

redirect('project_detail.php?id=' . $projectId);
