<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';

require_login();
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
if (!can_manage_pekerjaan($pj)) {
    flash('Kamu tidak berhak mengubah penugasan pekerja di pekerjaan ini.', 'err');
    redirect('pekerjaan_detail.php?id=' . $id);
}

simpan_penugasan($id, (array) ($_POST['pekerja'] ?? []), $_POST);

$detail = borongan_detail($id);
$pesan = count($detail['rows']) . ' pekerja ditugaskan pada <strong>' . e($pj['nama']) . '</strong>.';
if ($detail['total'] > 0) {
    $pesan .= ' Total upah borongan ' . e(rupiah($detail['total'])) . '.';
}
flash($pesan);
redirect('pekerjaan_detail.php?id=' . $id);
