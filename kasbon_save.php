<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';

require_role(['admin', 'pelaksana']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('kasbon.php');
}
csrf_check();

$userId = (int) ($_POST['user_id'] ?? 0);
$tanggal = valid_tanggal((string) ($_POST['tanggal'] ?? '')) ?: date('Y-m-d');
$nominal = parse_money((string) ($_POST['nominal'] ?? ''));
$keterangan = trim((string) ($_POST['keterangan'] ?? ''));

$st = db()->prepare('SELECT nama FROM users WHERE id = ? AND perusahaan_id = ?');
$st->execute([$userId, tenant_id()]);
$nama = (string) ($st->fetchColumn() ?: '');

if ($nama === '') {
    flash('Tenaga tidak valid.', 'err');
    redirect('kasbon.php');
}
if ($nominal <= 0) {
    flash('Nominal kasbon harus lebih dari 0.', 'err');
    redirect('kasbon.php');
}

db()->prepare('INSERT INTO kasbon (user_id, tanggal, nominal, keterangan, created_by, perusahaan_id) VALUES (?,?,?,?,?,?)')
    ->execute([$userId, $tanggal, $nominal, $keterangan, (int) current_user()['id'], tenant_id()]);

flash('Kasbon <strong>' . e($nama) . '</strong> sebesar <strong>' . e(rupiah($nominal)) . '</strong> tercatat. Akan otomatis dipotong pada penggajian periode berikutnya.');
redirect('kasbon.php');
