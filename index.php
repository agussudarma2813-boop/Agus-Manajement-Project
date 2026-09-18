<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';

/**
 * Titik masuk aplikasi.
 *
 * Sub-domain pelanggan dibaca di sini juga, supaya aplikasi langsung terkunci ke
 * perusahaan yang tepat begitu alamatnya dibuka (mis. pt-maju.agsapkkreatif.my.id),
 * lalu diarahkan ke dashboard (kalau sudah masuk) atau halaman login (yang akan
 * memakai nama & logo perusahaan tersebut).
 */
perusahaan_dari_host();

redirect(current_user() ? 'dashboard.php' : 'login.php');
