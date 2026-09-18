<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';

/**
 * Titik masuk aplikasi.
 *
 * Di sini sub-domain pelanggan juga dibaca: kalau aplikasi dibuka lewat
 * pt-maju.agsapkkreatif.my.id, konteks perusahaan "pt-maju" disiapkan lebih dulu
 * (branding + penguncian login), baru diarahkan ke dashboard / halaman masuk.
 */
$perusahaanSub = perusahaan_dari_host();

if ($perusahaanSub) {
    // Simpan hanya slug-nya (bukan data sensitif) supaya halaman login memakai konteks yang sama
    $_SESSION['sub_slug'] = (string) $perusahaanSub['slug'];
} else {
    unset($_SESSION['sub_slug']);
}

redirect(current_user() ? 'dashboard.php' : 'login.php');
