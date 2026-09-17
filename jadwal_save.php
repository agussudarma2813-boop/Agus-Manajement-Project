<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';

require_role(['admin', 'pelaksana']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('jadwal_harian.php');
}
csrf_check();

/* ============================ Edit satu jadwal ============================ */
if (empty($_POST['batch'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $row = get_jadwal($id);
    if (!$row) {
        flash('Jadwal tidak ditemukan.', 'err');
        redirect('jadwal_harian.php');
    }
    if (!can_manage_project(get_project((int) $row['project_id']))) {
        flash('Kamu tidak berhak mengubah jadwal pada project ini.', 'err');
        redirect('jadwal_harian.php');
    }

    $tanggal = valid_tanggal((string) ($_POST['tanggal'] ?? ''));
    $projectId = (int) ($_POST['project_id'] ?? 0);
    $project = get_project($projectId);
    $errors = [];
    if ($tanggal === '') {
        $errors[] = 'Tanggal tidak valid.';
    }
    if (!can_manage_project($project)) {
        $errors[] = 'Project tidak valid atau bukan tanggung jawabmu.';
    }
    if ($tanggal !== '') {
        $st = db()->prepare('SELECT id FROM jadwal WHERE tanggal = ? AND user_id = ? AND project_id = ? AND id <> ? AND perusahaan_id = ?');
        $st->execute([$tanggal, (int) $row['user_id'], $projectId, $id, tenant_id()]);
        if ($st->fetchColumn()) {
            $errors[] = 'Tenaga ini sudah punya jadwal pada tanggal & project tersebut.';
        }
    }
    if ($errors) {
        $_SESSION['jadwal_errors'] = $errors;
        redirect('jadwal_form.php?id=' . $id);
    }

    $pekerjaanId = (int) ($_POST['pekerjaan_id'] ?? 0);
    if ($pekerjaanId) {
        $pj = get_pekerjaan($pekerjaanId);
        if (!$pj || (int) $pj['project_id'] !== $projectId) {
            $pekerjaanId = 0;
        }
    }
    $lokasiId = (int) ($_POST['lokasi_id'] ?? 0);
    if ($lokasiId) {
        $lok = get_lokasi($lokasiId);
        if (!$lok || (int) $lok['project_id'] !== $projectId) {
            $lokasiId = 0;
        }
    }

    db()->prepare('UPDATE jadwal SET tanggal=?, project_id=?, pekerjaan_id=?, lokasi_id=?, catatan=? WHERE id=?')
        ->execute([$tanggal, $projectId, $pekerjaanId ?: null, $lokasiId ?: null,
            trim((string) ($_POST['catatan'] ?? '')), $id]);

    flash('Jadwal <strong>' . e($row['pekerja_nama']) . '</strong> pada ' . e(tgl($tanggal)) . ' berhasil diperbarui.');
    redirect('jadwal_harian.php?tanggal=' . $tanggal);
}

/* ============================ Susun massal ============================ */
$tanggal = valid_tanggal((string) ($_POST['tanggal'] ?? ''));
$projectId = (int) ($_POST['project_id'] ?? 0);
$project = get_project($projectId);
$pekerjaIds = array_values(array_unique(array_map('intval', (array) ($_POST['pekerja'] ?? []))));
$errors = [];

if ($tanggal === '') {
    $errors[] = 'Tanggal kerja wajib diisi.';
}
if (!can_manage_project($project)) {
    $errors[] = 'Project tidak valid atau bukan tanggung jawabmu.';
}
if (!$pekerjaIds) {
    $errors[] = 'Centang minimal satu tenaga yang bekerja.';
}
if ($errors) {
    $_SESSION['jadwal_errors'] = $errors;
    redirect('jadwal_form.php?tanggal=' . urlencode((string) ($_POST['tanggal'] ?? '')) . '&project_id=' . $projectId);
}

$pekerjaanId = (int) ($_POST['pekerjaan_id'] ?? 0);
if ($pekerjaanId) {
    $pj = get_pekerjaan($pekerjaanId);
    if (!$pj || (int) $pj['project_id'] !== $projectId) {
        $pekerjaanId = 0;
    }
}
$lokasiId = (int) ($_POST['lokasi_id'] ?? 0);
if ($lokasiId) {
    $lok = get_lokasi($lokasiId);
    if (!$lok || (int) $lok['project_id'] !== $projectId) {
        $lokasiId = 0;
    }
}
$catatan = trim((string) ($_POST['catatan'] ?? ''));

$valid = [];
foreach (all_users() as $p) {
    if ($p['role'] !== 'admin' && (int) $p['aktif'] === 1) {
        $valid[(int) $p['id']] = true;
    }
}

$ins = db()->prepare(
    'INSERT OR IGNORE INTO jadwal (tanggal, user_id, project_id, pekerjaan_id, lokasi_id, catatan, created_by)
     VALUES (?,?,?,?,?,?,?)'
);
$tersimpan = 0;
$dilewati = 0;
foreach ($pekerjaIds as $pid) {
    if (!isset($valid[$pid])) {
        $dilewati++;
        continue;
    }
    $ins->execute([$tanggal, $pid, $projectId, $pekerjaanId ?: null, $lokasiId ?: null, $catatan, (int) current_user()['id']]);
    if ($ins->rowCount() > 0) {
        $tersimpan++;
    } else {
        $dilewati++;
    }
}

$pesan = '<strong>' . $tersimpan . ' jadwal</strong> tersimpan untuk ' . e(tgl($tanggal)) . '.';
if ($dilewati > 0) {
    $pesan .= ' ' . $dilewati . ' dilewati karena sudah dijadwalkan.';
}
flash($pesan, $tersimpan > 0 ? 'ok' : 'info');
redirect('jadwal_harian.php?tanggal=' . $tanggal . '&project_id=' . $projectId);
