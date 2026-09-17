<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';

$u = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pekerjaan.php');
}
csrf_check();

$id = (int) ($_POST['pekerjaan_id'] ?? 0);
$pj = get_pekerjaan($id);
if (!$pj) {
    flash('Pekerjaan tidak ditemukan.', 'err');
    redirect('pekerjaan.php');
}
if (!can_update_progress($pj)) {
    flash('Kamu tidak punya akses untuk memperbarui progress pekerjaan ini.', 'err');
    redirect('pekerjaan.php');
}

$progress = max(0, min(100, (int) ($_POST['progress'] ?? $pj['progress'])));
$catatan = trim((string) ($_POST['catatan'] ?? ''));
$tanggal = trim((string) ($_POST['tanggal'] ?? '')) ?: date('Y-m-d');
$status = $pj['status'];

$isManager = can_manage_pekerjaan($pj) || (int) $pj['pelaksana_id'] === (int) $u['id'];
$postedStatus = (string) ($_POST['status'] ?? '');

if ($isManager && isset(STATUS_PEKERJAAN[$postedStatus])) {
    $status = $postedStatus;
}

// Status mengikuti angka progress bila tidak diubah manual oleh pengelola
if ($status === 'selesai') {
    $progress = 100;
} elseif ($progress >= 100) {
    $status = 'selesai';
} elseif ($progress > 0 && in_array($status, ['belum', 'selesai'], true)) {
    $status = 'proses';
} elseif ($progress === 0 && $status === 'proses' && $isManager && $postedStatus === 'proses') {
    $status = 'proses';
}

$changed = ((int) $pj['progress'] !== $progress) || ($pj['status'] !== $status);
$hasNote = $catatan !== '';

if ($changed || $hasNote) {
    db()->prepare('INSERT INTO progress_log (pekerjaan_id, user_id, tanggal, progress, catatan) VALUES (?,?,?,?,?)')
        ->execute([$id, (int) $u['id'], $tanggal, $progress, $catatan]);
}

if ($changed) {
    db()->prepare('UPDATE pekerjaan SET progress = ?, status = ? WHERE id = ?')
        ->execute([$progress, $status, $id]);
    flash('Progress <strong>' . e($pj['nama']) . '</strong> diperbarui menjadi ' . $progress . '% (' . e(status_label($status)) . ').');
} elseif ($hasNote) {
    flash('Catatan lapangan untuk <strong>' . e($pj['nama']) . '</strong> tersimpan.');
} else {
    flash('Tidak ada perubahan yang perlu disimpan.', 'info');
}

redirect('pekerjaan_detail.php?id=' . $id);
