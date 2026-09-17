<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';

$u = require_role(['admin', 'pelaksana']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('absensi.php');
}
csrf_check();

/* ============================ Mode edit satu baris ============================ */
if (empty($_POST['batch'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $row = get_absensi($id);
    if (!$row) {
        flash('Catatan absensi tidak ditemukan.', 'err');
        redirect('absensi.php');
    }
    if (!can_record_absensi(get_project((int) $row['project_id']))) {
        flash('Kamu tidak berhak mengubah catatan absensi pada project ini.', 'err');
        redirect('absensi.php');
    }

    $tanggal = valid_tanggal((string) ($_POST['tanggal'] ?? ''));
    $projectId = (int) ($_POST['project_id'] ?? 0);
    $project = get_project($projectId);
    $hari = (float) str_replace(',', '.', (string) ($_POST['hari'] ?? '1'));
    $upah = parse_money((string) ($_POST['upah'] ?? '0'));
    $errors = [];

    if ($tanggal === '') {
        $errors[] = 'Tanggal tidak valid.';
    }
    if (!can_record_absensi($project)) {
        $errors[] = 'Project tidak valid atau bukan tanggung jawabmu.';
    }
    if ($hari <= 0 || $hari > 5) {
        $errors[] = 'Jumlah hari harus antara 0,5 dan 5.';
    }
    if ($upah < 0) {
        $errors[] = 'Tarif upah tidak boleh negatif.';
    }
    if ($tanggal !== '') {
        $st = db()->prepare('SELECT id FROM absensi WHERE user_id = ? AND tanggal = ? AND id <> ? AND perusahaan_id = ?');
        $st->execute([(int) $row['user_id'], $tanggal, $id, tenant_id()]);
        if ($st->fetchColumn()) {
            $errors[] = 'Pekerja ini sudah punya catatan kehadiran pada tanggal tersebut.';
        }
    }

    if ($errors) {
        $_SESSION['absensi_errors'] = $errors;
        redirect('absensi_form.php?id=' . $id);
    }

    $pekerjaanId = (int) ($_POST['pekerjaan_id'] ?? 0);
    if ($pekerjaanId) {
        $pj = get_pekerjaan($pekerjaanId);
        if (!$pj || (int) $pj['project_id'] !== $projectId) {
            $pekerjaanId = 0;
        }
    }

    db()->prepare('UPDATE absensi SET tanggal=?, project_id=?, pekerjaan_id=?, hari=?, upah=?, keterangan=? WHERE id=?')
        ->execute([
            $tanggal,
            $projectId ?: null,
            $pekerjaanId ?: null,
            $hari,
            $upah,
            trim((string) ($_POST['keterangan'] ?? '')),
            $id,
        ]);

    flash('Catatan kehadiran tanggal <strong>' . e(tgl($tanggal)) . '</strong> berhasil diperbarui.');
    redirect('absensi.php?dari=' . $tanggal . '&sampai=' . $tanggal);
}

/* ============================ Mode input massal ============================ */
$tanggal = valid_tanggal((string) ($_POST['tanggal'] ?? ''));
$projectId = (int) ($_POST['project_id'] ?? 0);
$project = get_project($projectId);
$pekerjaanId = (int) ($_POST['pekerjaan_id'] ?? 0);
$pekerjaIds = array_values(array_unique(array_map('intval', (array) ($_POST['pekerja'] ?? []))));
$hariArr = (array) ($_POST['hari'] ?? []);
$upahArr = (array) ($_POST['upah'] ?? []);

$errors = [];
if ($tanggal === '') {
    $errors[] = 'Tanggal kerja wajib diisi dengan format tanggal yang benar.';
}
if (!can_record_absensi($project)) {
    $errors[] = 'Project tidak valid atau bukan tanggung jawabmu.';
}
if (!$pekerjaIds) {
    $errors[] = 'Centang minimal satu pekerja yang masuk kerja.';
}
if ($errors) {
    $_SESSION['absensi_errors'] = $errors;
    redirect('absensi_form.php?tanggal=' . urlencode((string) ($_POST['tanggal'] ?? '')) . '&project_id=' . $projectId);
}

if ($pekerjaanId) {
    $pj = get_pekerjaan($pekerjaanId);
    if (!$pj || (int) $pj['project_id'] !== $projectId) {
        $pekerjaanId = 0;
    }
}

// Hanya pekerja/pelaksana aktif yang valid
$valid = [];
foreach (all_users() as $p) {
    if ($p['role'] !== 'admin' && (int) $p['aktif'] === 1) {
        $valid[(int) $p['id']] = (float) $p['upah_harian'];
    }
}

$ins = db()->prepare(
    'INSERT OR IGNORE INTO absensi (user_id, project_id, pekerjaan_id, tanggal, hari, upah, keterangan, created_by)
     VALUES (?,?,?,?,?,?,?,?)'
);

$tersimpan = 0;
$dilewati = 0;
foreach ($pekerjaIds as $pid) {
    if (!isset($valid[$pid])) {
        $dilewati++;
        continue;
    }
    $hari = (float) str_replace(',', '.', (string) ($hariArr[$pid] ?? $hariArr[(string) $pid] ?? '1'));
    if ($hari <= 0 || $hari > 5) {
        $hari = 1.0;
    }
    $upahInput = $upahArr[$pid] ?? $upahArr[(string) $pid] ?? null;
    $upah = $upahInput === null || trim((string) $upahInput) === ''
        ? $valid[$pid]
        : parse_money((string) $upahInput);

    $ins->execute([$pid, $projectId, $pekerjaanId ?: null, $tanggal, $hari, $upah, '', (int) $u['id']]);
    if ($ins->rowCount() > 0) {
        $tersimpan++;
    } else {
        $dilewati++;
    }
}

$pesan = '<strong>' . $tersimpan . ' catatan kehadiran</strong> tersimpan untuk tanggal ' . e(tgl($tanggal)) . '.';
if ($dilewati > 0) {
    $pesan .= ' ' . $dilewati . ' dilewati karena sudah tercatat pada tanggal itu.';
}
flash($pesan, $tersimpan > 0 ? 'ok' : 'info');
redirect('absensi.php?dari=' . $tanggal . '&sampai=' . $tanggal . '&project_id=' . $projectId);
