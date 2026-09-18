<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';

/**
 * Menyimpan domain dasar untuk sub-domain pelanggan (khusus pengelola aplikasi).
 * Contoh isi: agsapkkreatif.my.id  → pelanggan memakai pt-maju.agsapkkreatif.my.id
 */
require_owner();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('perusahaan.php');
}
csrf_check();

$nilai = trim((string) ($_POST['subdomain_base'] ?? ''));
$nilai = str_replace(['https://', 'http://', '/'], '', $nilai);

// Validasi: daftar domain dipisah koma/spasi
$bersih = [];
foreach (preg_split('/[,\s]+/', $nilai) ?: [] as $d) {
    $d = ltrim(trim($d), '.');
    if ($d === '') {
        continue;
    }
    if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $d) !== 1) {
        flash('Domain <strong>' . e($d) . '</strong> tidak valid. Contoh yang benar: <span class="mono">agsapkkreatif.my.id</span>', 'err');
        redirect('perusahaan.php');
    }
    $bersih[] = strtolower($d);
}

app_setting_set('subdomain_base', implode(',', array_unique($bersih)));

if ($bersih) {
    flash('Domain sub-domain disimpan: <strong>' . e(implode(', ', array_unique($bersih))) . '</strong>. '
        . 'Sekarang pelanggan bisa membuka <span class="mono">nama-perusahaan.' . e($bersih[0]) . '</span> '
        . 'dan aplikasi otomatis terkunci ke perusahaan tersebut.');
} else {
    flash('Domain sub-domain dikosongkan. Pelanggan kembali memakai tautan <span class="mono">login.php?p=slug</span>.');
}
redirect('perusahaan.php');
