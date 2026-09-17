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
    flash('Kamu tidak bisa menonaktifkan akunmu sendiri.', 'err');
    redirect('users.php');
}

$baru = (int) $person['aktif'] === 1 ? 0 : 1;
db()->prepare('UPDATE users SET aktif = ? WHERE id = ?')->execute([$baru, $id]);
flash('Akun <strong>' . e($person['nama']) . '</strong> ' . ($baru ? 'diaktifkan kembali.' : 'dinonaktifkan — tidak bisa login sampai diaktifkan lagi.'));
redirect('users.php');
