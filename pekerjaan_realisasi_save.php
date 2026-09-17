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
if (!can_update_progress($pj)) {
    flash('Kamu tidak berhak mencatat volume akhir pekerjaan ini.', 'err');
    redirect('pekerjaan_detail.php?id=' . $id);
}

$volume = (float) str_replace(',', '.', trim((string) ($_POST['volume_realisasi'] ?? '0')));
if ($volume < 0) {
    $volume = 0.0;
}
$tanggal = valid_tanggal((string) ($_POST['realisasi_tanggal'] ?? ''));
if ($volume > 0 && $tanggal === '') {
    $tanggal = date('Y-m-d');
}
$catatan = trim((string) ($_POST['realisasi_catatan'] ?? ''));

// Isian admin dianggap manual (volume_auto = 0). Kalau ada laporan harian,
// nilai ini akan kembali dihitung otomatis saat laporan berikutnya disimpan.
db()->prepare('UPDATE pekerjaan SET volume_realisasi = ?, realisasi_tanggal = ?, realisasi_catatan = ?, volume_auto = 0 WHERE id = ?')
    ->execute([$volume, $tanggal, $catatan, $id]);

if ($volume > 0) {
    db()->prepare('INSERT INTO progress_log (pekerjaan_id, user_id, tanggal, progress, catatan) VALUES (?,?,?,?,?)')
        ->execute([
            $id,
            (int) current_user()['id'],
            $tanggal,
            (int) $pj['progress'],
            'Volume akhir diselesaikan: ' . num($volume) . ' ' . $pj['satuan'] . ($catatan !== '' ? ' — ' . $catatan : ''),
        ]);
    flash('Volume akhir <strong>' . e(num($volume) . ' ' . $pj['satuan']) . '</strong> tersimpan untuk <strong>' . e($pj['nama']) . '</strong>.');
} else {
    flash('Volume akhir dikosongkan (belum ada volume yang diselesaikan).', 'info');
}
redirect('pekerjaan_detail.php?id=' . $id);
