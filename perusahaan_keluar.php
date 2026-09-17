<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';

require_login();
unset($_SESSION['as_perusahaan']);
flash('Keluar dari mode bantu. Kembali ke data perusahaan Anda.', 'info');
redirect('dashboard.php');
