<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';

$me = require_role(['admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('users.php');
}
csrf_check();

$id = (int) ($_POST['id'] ?? 0);
$st = db()->prepare('SELECT * FROM users WHERE id = ? AND perusahaan_id = ?');
$st->execute([$id, tenant_id()]);
$person = $st->fetch();

if (!$person) {
    flash('Pengguna tidak ditemukan.', 'err');
    redirect('users.php');
}
if ((int) $person['id'] === (int) $me['id']) {
    flash('Kamu tidak bisa menghapus akunmu sendiri.', 'err');
    redirect('users.php');
}

$admins = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
if ($person['role'] === 'admin' && $admins <= 1) {
    flash('Minimal harus ada satu akun admin. Ubah peran akun lain menjadi admin dulu.', 'err');
    redirect('users.php');
}

db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
flash('Akun <strong>' . e($person['nama']) . '</strong> telah dihapus.', 'ok');
redirect('users.php');
