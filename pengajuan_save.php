<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';

$u = require_role(['admin', 'pelaksana']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pengajuan.php');
}
csrf_check();

$projectId = (int) ($_POST['project_id'] ?? 0);
$project = get_project($projectId);
$tanggal = valid_tanggal((string) ($_POST['tanggal'] ?? '')) ?: date('Y-m-d');
$catatan = trim((string) ($_POST['catatan'] ?? ''));
$pilih = array_values(array_unique(array_map('intval', (array) ($_POST['pekerjaan'] ?? []))));
$volArr = (array) ($_POST['volume'] ?? []);

$balik = 'pengajuan_form.php?project_id=' . $projectId;

$errors = [];
if (!can_manage_project($project)) {
    $errors[] = 'Project tidak valid atau bukan tanggung jawabmu.';
}
if (!$pilih) {
    $errors[] = 'Centang minimal satu sub pekerjaan yang mau diajukan.';
}

// validasi tiap item: milik project ini + volume > 0 + tidak melebihi sisa
$siap = [];
if (!$errors) {
    $items = [];
    foreach (item_untuk_pengajuan($projectId) as $it) {
        $items[(int) $it['id']] = $it;
    }
    foreach ($pilih as $wid) {
        if (!isset($items[$wid])) {
            $errors[] = 'Ada item yang tidak sesuai dengan project ini.';
            break;
        }
        $it = $items[$wid];
        $vol = (float) str_replace(',', '.', trim((string) ($volArr[$wid] ?? $volArr[(string) $wid] ?? '0')));
        if ($vol <= 0) {
            $errors[] = 'Volume untuk "' . e($it['nama']) . '" harus lebih dari 0.';
            break;
        }
        if ($vol > $it['sisa'] + 0.001) {
            $errors[] = 'Volume "' . e($it['nama']) . '" melebihi sisa yang belum diajukan ('
                . e(num($it['sisa']) . ' ' . $it['satuan']) . ').';
            break;
        }
        $siap[] = ['item' => $it, 'volume' => $vol];
    }
}

if ($errors) {
    $_SESSION['pengajuan_errors'] = $errors;
    $nilai = [];
    foreach ((array) $volArr as $k => $v) {
        $nilai[(int) $k] = (string) $v;
    }
    $_SESSION['pengajuan_nilai'] = $nilai;
    redirect($balik);
}

$total = 0.0;
foreach ($siap as $s) {
    $total += $s['volume'] * (float) $s['item']['harga_jasa'];
}

// nomor pengajuan sederhana: PJ-<kode project>-<urut>
$st = db()->prepare('SELECT COUNT(*) FROM pengajuan WHERE project_id = ? AND perusahaan_id = ?');
$st->execute([$projectId, tenant_id()]);
$urut = (int) $st->fetchColumn() + 1;
$nomor = 'PJ-' . preg_replace('/[^A-Za-z0-9]/', '', (string) $project['kode']) . '-' . str_pad((string) $urut, 3, '0', STR_PAD_LEFT);

db()->prepare(
    "INSERT INTO pengajuan (nomor, project_id, tanggal, status, tanggal_bayar, catatan, created_by, perusahaan_id)
     VALUES (?,?,?,'diajukan','',?,?,?)"
)->execute([$nomor, $projectId, $tanggal, $catatan, (int) $u['id'], tenant_id()]);
$pengajuanId = (int) db()->lastInsertId();

$ins = db()->prepare(
    'INSERT INTO pengajuan_item (pengajuan_id, pekerjaan_id, volume, harga_jasa, satuan, nama_item, perusahaan_id)
     VALUES (?,?,?,?,?,?,?)'
);
foreach ($siap as $s) {
    $it = $s['item'];
    $ins->execute([
        $pengajuanId,
        (int) $it['id'],
        $s['volume'],
        (float) $it['harga_jasa'],
        (string) $it['satuan'],
        (string) $it['nama'],
        tenant_id(),
    ]);
    tagih_sync_volume((int) $it['id']);
}

flash('Pengajuan <strong>' . e($nomor) . '</strong> tersimpan: ' . count($siap) . ' sub pekerjaan · nilai <strong>'
    . e(rupiah($total)) . '</strong>. Volume yang belum diajukan masih bisa diajukan lagi nanti.');
redirect('pengajuan_detail.php?id=' . $pengajuanId);
