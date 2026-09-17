<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/xlsx.php';

/**
 * Export pengajuan ke Excel.
 *
 * Isinya sederhana dan hanya yang dibutuhkan untuk menagih ke perusahaan:
 *   nama project · daftar sub pekerjaan yang diajukan (volume, satuan, harga ke perusahaan, jumlah) · total
 *
 * Satu project = satu sheet. Format: file .xlsx asli (rapi: judul, header berwarna,
 * garis tabel, lebar kolom, format ribuan, header dibekukan).
 * Bila ekstensi zip tidak tersedia, otomatis memakai format tabel HTML yang tetap rapi.
 */
require_role(['admin', 'pelaksana']);

$projectId = (int) ($_GET['project_id'] ?? 0);

if ($projectId) {
    $project = get_project($projectId);
    if (!$project || !can_manage_project($project)) {
        flash('Project tidak ditemukan atau bukan tanggung jawabmu.', 'err');
        redirect('pengajuan.php');
    }
    $daftar = [$project];
} else {
    $daftar = [];
    foreach (project_berpengajuan() as $p) {
        if (can_manage_project($p)) {
            $daftar[] = $p;
        }
    }
    if (!$daftar) {
        foreach (selectable_projects() as $p) {
            if (can_manage_project($p)) {
                $daftar[] = $p;
            }
        }
    }
}

if (!$daftar) {
    flash('Belum ada project yang bisa diexport.', 'err');
    redirect('pengajuan.php');
}

/* ---------------- Susun data per project ---------------- */
$kolom = ['A' => 6, 'B' => 46, 'C' => 12, 'D' => 11, 'E' => 22, 'F' => 22];
$sheets = [];
$totalSemua = 0.0;

foreach ($daftar as $project) {
    $pid = (int) $project['id'];
    $pengajuan = fetch_pengajuan(['project_id' => $pid]);

    $baris = [
        ['cells' => [['v' => 'PENGAJUAN KE PERUSAHAAN', 's' => 'judul']], 'merge' => true, 'tinggi' => 26],
        ['cells' => [['v' => 'Project', 's' => 'subjudul'], ['v' => (string) $project['kode'] . ' — ' . $project['nama']]]],
        ['cells' => [['v' => 'Pelaksana', 's' => 'subjudul'], ['v' => (string) ($project['pelaksana_nama'] ?? '-')]]],
        ['cells' => [['v' => 'Dicetak', 's' => 'subjudul'], ['v' => tgl(date('Y-m-d'))]]],
        ['cells' => []],
        ['cells' => [
            ['v' => 'No', 's' => 'header'],
            ['v' => 'Sub Pekerjaan', 's' => 'header'],
            ['v' => 'Volume', 's' => 'header'],
            ['v' => 'Satuan', 's' => 'header'],
            ['v' => 'Harga ke Perusahaan', 's' => 'header'],
            ['v' => 'Jumlah', 's' => 'header'],
        ], 'tinggi' => 24],
    ];

    $no = 0;
    $total = 0.0;
    $barisHeader = count($baris);
    foreach ($pengajuan as $peng) {
        $items = item_pengajuan((int) $peng['id']);
        if (!$items) {
            continue;
        }
        // kalau lebih dari satu pengajuan (termin), beri pemisah agar mudah dilacak
        if (count($pengajuan) > 1) {
            $baris[] = ['cells' => [[
                'v' => 'Pengajuan ' . $peng['nomor'] . '  ·  ' . tgl($peng['tanggal'])
                    . '  ·  ' . ($peng['status'] === 'dibayar' ? 'sudah dibayar' : 'sudah diajukan'),
                's' => 'subjudul',
            ]], 'merge' => true];
        }
        foreach ($items as $it) {
            $nilai = (float) $it['volume'] * (float) $it['harga_jasa'];
            $total += $nilai;
            $baris[] = ['cells' => [
                ['v' => ++$no, 't' => 'n', 's' => 'teks'],
                ['v' => (string) $it['nama_item'], 's' => 'teks'],
                ['v' => (float) $it['volume'], 't' => 'n', 's' => 'angka'],
                ['v' => (string) $it['satuan'], 's' => 'teks'],
                ['v' => (float) $it['harga_jasa'], 't' => 'n', 's' => 'angka'],
                ['v' => $nilai, 't' => 'n', 's' => 'angka'],
            ]];
        }
    }

    if ($no === 0) {
        $baris[] = ['cells' => [['v' => '', 's' => 'teks'], ['v' => 'Belum ada item yang diajukan.', 's' => 'teks']]];
    } else {
        $baris[] = ['cells' => [
            ['v' => '', 's' => 'total_teks'],
            ['v' => 'TOTAL', 's' => 'total_teks'],
            ['v' => '', 's' => 'total_teks'],
            ['v' => '', 's' => 'total_teks'],
            ['v' => '', 's' => 'total_teks'],
            ['v' => $total, 't' => 'n', 's' => 'total_angka'],
        ], 'tinggi' => 22];
    }
    $totalSemua += $total;

    $sheets[] = [
        'nama' => (string) $project['nama'],
        'baris' => $baris,
        'lebar' => $kolom,
        'freeze' => $barisHeader,
    ];
}

$namaFile = $projectId
    ? 'pengajuan-' . slugify((string) ($daftar[0]['kode'] . '-' . $daftar[0]['nama']))
    : 'pengajuan-semua-project';

/* ---------------- Kirim sebagai .xlsx ---------------- */
$xlsx = new XlsxExport();
foreach ($sheets as $s) {
    $xlsx->tambahSheet($s['nama'], $s['baris'], $s['lebar'], $s['freeze']);
}
if ($xlsx->kirim($namaFile)) {
    exit;
}

/* ---------------- Cadangan: tabel HTML rapi (bila zip tidak tersedia) ---------------- */
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $namaFile . '.xls"');
header('Cache-Control: no-store');
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">
<head><meta charset="utf-8">
<style>
  table { border-collapse: collapse; font-family: Calibri, Arial, sans-serif; font-size: 11pt; }
  .judul { font-size: 15pt; font-weight: bold; color: #1F1B3A; }
  .label { color: #635F82; }
  th { background: #4F46E5; color: #fff; font-weight: bold; text-align: center; padding: 6px 10px; border: 1px solid #D5DAE5; }
  td { padding: 5px 10px; border: 1px solid #D5DAE5; vertical-align: middle; }
  td.no { text-align: center; width: 40px; }
  td.angka { text-align: right; }
  td.total { font-weight: bold; border-top: 2px solid #4F46E5; }
  tr.sub th { background: #EEF2FF; color: #3730A3; text-align: left; }
</style></head>
<body>
<?php foreach ($sheets as $s): ?>
  <table>
    <?php foreach ($s['baris'] as $r): ?>
      <?php $cells = $r['cells'] ?? []; if (!$cells) { echo '<tr><td colspan="6" style="border:0">&nbsp;</td></tr>'; continue; } ?>
      <?php if (!empty($r['merge']) && count($cells) === 1): ?>
        <tr><td colspan="6" class="judul"><?= e((string) $cells[0]['v']) ?></td></tr>
        <?php continue; ?>
      <?php endif; ?>
      <?php if (!empty($r['merge'])): ?>
        <tr class="sub"><th colspan="6"><?= e((string) $cells[0]['v']) ?></th></tr>
        <?php continue; ?>
      <?php endif; ?>
      <tr>
        <?php foreach ($cells as $i => $c): ?>
          <?php
          $gaya = (string) ($c['s'] ?? '');
          $v = $c['v'] ?? '';
          $kelas = 'teks';
          if ($gaya === 'header') {
              echo '<th>' . e((string) $v) . '</th>';
              continue;
          }
          if ($gaya === 'judul') {
              echo '<td colspan="6" class="judul">' . e((string) $v) . '</td>';
              continue;
          }
          if ($gaya === 'subjudul') {
              echo '<td class="label">' . e((string) $v) . '</td>';
              continue;
          }
          if ($gaya === 'total_teks' || $gaya === 'total_angka') {
              $kelas = $gaya === 'total_angka' ? 'angka total' : 'total';
          } elseif ($gaya === 'angka') {
              $kelas = 'angka';
          }
          if ($i === 0 && $gaya === 'teks' && is_numeric($v)) {
              $kelas = 'no';
          }
          $teksNilai = ($gaya === 'angka' || $gaya === 'total_angka') ? num((float) $v) : (string) $v;
          echo '<td class="' . $kelas . '">' . e($teksNilai) . '</td>';
          ?>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
  </table>
  <br>
<?php endforeach; ?>
</body></html>
