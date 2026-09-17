<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';

$u = require_role(['admin', 'pelaksana']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('gaji.php');
}
csrf_check();

$dari = valid_tanggal((string) ($_POST['dari'] ?? ''));
$sampai = valid_tanggal((string) ($_POST['sampai'] ?? ''));
$pilih = array_values(array_unique(array_map('intval', (array) ($_POST['pekerja'] ?? []))));

if ($dari === '' || $sampai === '' || $sampai < $dari) {
    flash('Periode gaji tidak valid.', 'err');
    redirect('gaji.php');
}
if (!$pilih) {
    flash('Centang minimal satu tenaga yang mau digaji.', 'err');
    redirect('gaji.php?dari=' . $dari . '&sampai=' . $sampai);
}

$valid = [];
foreach (all_users() as $p) {
    if ($p['role'] !== 'admin' && (int) $p['aktif'] === 1) {
        $valid[(int) $p['id']] = true;
    }
}

$dibuat = 0;
$dilewati = 0;
$total = 0.0;
$totalKasbon = 0.0;
$nama = [];
foreach ($pilih as $uid) {
    if (!isset($valid[$uid])) {
        $dilewati++;
        continue;
    }
    $g = hitung_gaji($uid, $dari, $sampai);
    if ($g['sudah']) {
        $dilewati++;
        continue;
    }
    $id = buat_penggajian($uid, $dari, $sampai, (int) $u['id']);
    if ($id) {
        $dibuat++;
        $total += $g['diterima'];
        $totalKasbon += $g['total_kasbon'];
        $nama[] = $g['orang']['nama'] ?? '';
    } else {
        $dilewati++;
    }
}

if ($dibuat === 0) {
    flash('Tidak ada penggajian baru yang dibuat (mungkin sudah pernah digaji pada periode ini).', 'info');
    redirect('gaji.php?dari=' . $dari . '&sampai=' . $sampai);
}

$pesan = '<strong>' . $dibuat . ' penggajian</strong> dibuat untuk periode ' . e(tgl($dari)) . ' – ' . e(tgl($sampai))
    . ' · total diterima ' . e(rupiah($total));
if ($totalKasbon > 0) {
    $pesan .= ' (kasbon dipotong ' . e(rupiah($totalKasbon)) . ')';
}
$pesan .= '. Status awal: <strong>Belum dibayar</strong> — tandai sudah dibayar di halaman rincian.';
if ($dilewati > 0) {
    $pesan .= ' ' . $dilewati . ' dilewati (sudah digaji/tidak valid).';
}
flash($pesan);
redirect('gaji.php?dari=' . $dari . '&sampai=' . $sampai);
