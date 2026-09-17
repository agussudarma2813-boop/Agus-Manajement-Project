<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';

$u = require_login();
$bolehAtur = in_array($u['role'], ['admin', 'pelaksana'], true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('jadwal_harian.php');
}
csrf_check();

$id = (int) ($_POST['id'] ?? 0);
$userId = (int) ($_POST['user_id'] ?? $u['id']);
$tanggal = valid_tanggal((string) ($_POST['tanggal'] ?? ''));
$projectId = (int) ($_POST['project_id'] ?? 0);
$jadwalId = (int) ($_POST['jadwal_id'] ?? 0);
// Form "banyak pekerjaan" mengirim array; mode edit mengirim nilai tunggal.
// (Jangan cast ke string saat array — pernah jadi Warning + header gagal terkirim.)
$multiItem = is_array($_POST['pekerjaan_id'] ?? null);
$rawPekerjaan = $multiItem ? '' : trim((string) ($_POST['pekerjaan_id'] ?? ''));
$pekerjaanId = (!$multiItem && str_starts_with($rawPekerjaan, 'm')) ? 0 : (int) $rawPekerjaan;
$volume = $multiItem ? 0.0 : (float) str_replace(',', '.', trim((string) ($_POST['volume'] ?? '0')));
$keterangan = $multiItem ? '' : trim((string) ($_POST['keterangan'] ?? ''));

/**
 * Daftar baris pekerjaan yang dilaporkan. Satu pengiriman bisa memuat beberapa
 * pekerjaan (mis. narik kabel + pasang unit + terminasi + pasang panel).
 * Mode edit tetap satu baris.
 */
$barisInput = [];
if ($multiItem) {
    $pidArr = array_values((array) $_POST['pekerjaan_id']);
    $volArr = array_values((array) ($_POST['volume'] ?? []));
    $ketArr = array_values((array) ($_POST['keterangan'] ?? []));
    foreach ($pidArr as $i => $pid) {
        // nilai "m<id>" = item dari master harga yang belum ada di project
        $raw = trim((string) $pid);
        $masterPilih = str_starts_with($raw, 'm') ? (int) substr($raw, 1) : 0;
        $pid = $masterPilih ? 0 : (int) $raw;
        $vol = (float) str_replace(',', '.', trim((string) ($volArr[$i] ?? '0')));
        $ket = trim((string) ($ketArr[$i] ?? ''));
        if ($masterPilih <= 0 && $pid <= 0 && $vol <= 0) {
            continue; // baris kosong
        }
        $barisInput[] = [
            'pekerjaan_id' => $pid,
            'master_id' => $masterPilih,
            'volume' => $vol,
            'keterangan' => $ket,
        ];
    }
} elseif ($pekerjaanId > 0) {
    $barisInput[] = ['pekerjaan_id' => $pekerjaanId, 'master_id' => 0, 'volume' => $volume, 'keterangan' => $keterangan];
}

// Baris yang memilih item master: buat itemnya di project ini (kalau belum ada),
// supaya laporannya tetap tercatat & masuk hitungan tagihan/upah.
foreach ($barisInput as $i => $b) {
    if (empty($b['master_id'])) {
        continue;
    }
    $pjBaru = pastikan_pekerjaan_dari_master($projectId, (int) $b['master_id'], (int) $u['id']);
    if ($pjBaru) {
        $barisInput[$i]['pekerjaan_id'] = (int) $pjBaru['id'];
    } else {
        $barisInput[$i]['pekerjaan_id'] = 0;
    }
}

// nilai tunggal dipakai untuk mode edit & pesan
$pekerjaanId = $id ? $pekerjaanId : (int) ($barisInput[0]['pekerjaan_id'] ?? 0);
if ($id) {
    $volume = (float) $volume;
    $keterangan = trim((string) $keterangan);
}

$errors = [];
$balik = 'laporan_kerja_form.php?' . http_build_query(array_filter([
    'id' => $id ?: null,
    'jadwal_id' => $jadwalId ?: null,
    'user_id' => $bolehAtur ? $userId : null,
    'tanggal' => $tanggal ?: null,
    'project_id' => $projectId ?: null,
    'pekerjaan_id' => $pekerjaanId ?: null,
]));

/* ---------- Hak akses ---------- */
if (!$bolehAtur && $userId !== (int) $u['id']) {
    flash('Kamu hanya bisa mengisi hasil kerja atas namamu sendiri.', 'err');
    redirect('jadwal_harian.php');
}

if ($tanggal === '') {
    $errors[] = 'Tanggal kerja wajib diisi.';
}
if (!$barisInput) {
    $errors[] = 'Pilih minimal satu pekerjaan dan isi volumenya.';
}

// setiap baris harus item milik project yang sama + volumenya > 0
$pekerjaanValid = [];
foreach ($barisInput as $b) {
    if ($b['pekerjaan_id'] <= 0) {
        $errors[] = 'Ada baris yang belum dipilih pekerjaannya.';
        break;
    }
    $pj = get_pekerjaan((int) $b['pekerjaan_id']);
    if (!$pj || (int) $pj['project_id'] !== $projectId) {
        $errors[] = 'Ada pekerjaan yang tidak sesuai dengan project yang dipilih.';
        break;
    }
    if ($b['volume'] <= 0) {
        $errors[] = 'Volume tiap pekerjaan harus lebih dari 0 (' . e($pj['nama']) . ').';
        break;
    }
    if ($bolehAtur && !can_manage_pekerjaan($pj)) {
        $errors[] = 'Kamu hanya bisa mengisi hasil kerja pada project yang kamu kelola.';
        break;
    }
    $pekerjaanValid[(int) $pj['id']] = $pj;
}
// mode edit: kalau memilih item master, buat/siapkan itemnya lebih dulu
if ($id && str_starts_with($rawPekerjaan, 'm')) {
    $pjEdit = pastikan_pekerjaan_dari_master($projectId, (int) substr($rawPekerjaan, 1), (int) $u['id']);
    if ($pjEdit) {
        $pekerjaanId = (int) $pjEdit['id'];
        $pekerjaanValid[$pekerjaanId] = $pjEdit;
    }
}

$pekerjaan = $pekerjaanValid[$pekerjaanId] ?? null;

$pekerja = null;
if (!$errors) {
    $st = db()->prepare('SELECT * FROM users WHERE id = ? AND perusahaan_id = ?');
    $st->execute([$userId, tenant_id()]);
    $pekerja = $st->fetch();
    if (!$pekerja) {
        $errors[] = 'Data tenaga tidak ditemukan.';
    }
}

if ($errors) {
    $_SESSION['laporan_errors'] = $errors;
    redirect($balik);
}

/* ---------- Simpan ---------- */
$isBorongan = (string) $pekerja['skema'] === 'borongan';

/* ===== Mode edit (satu baris) ===== */
if ($id) {
    $lama = get_laporan($id);
    if (!$lama) {
        flash('Laporan tidak ditemukan.', 'err');
        redirect('laporan_kerja.php');
    }
    $tarif = tarif_upah($pekerjaan, $userId);
    $upahSatuan = $isBorongan ? $tarif : 0.0;

    db()->prepare('UPDATE laporan_kerja SET tanggal=?, project_id=?, pekerjaan_id=?, volume=?, upah_satuan=?, keterangan=?, jadwal_id=? WHERE id=?')
        ->execute([$tanggal, $projectId, $pekerjaanId, $volume, $upahSatuan, $keterangan, $jadwalId ?: null, $id]);

    sync_volume_realisasi((int) $lama['pekerjaan_id']);
    if ((int) $lama['pekerjaan_id'] !== $pekerjaanId) {
        sync_volume_realisasi($pekerjaanId);
    }
    // absensi otomatis lama dihapus, lalu dibuat ulang untuk tanggal/item yang baru
    if ($lama['tanggal'] !== $tanggal || (int) $lama['pekerjaan_id'] !== $pekerjaanId) {
        auto_absensi_hapus((int) $lama['user_id'], (string) $lama['tanggal'], (int) $lama['pekerjaan_id']);
    }
    auto_absensi($userId, $tanggal, $projectId, $pekerjaanId, (int) $u['id']);
    flash('Laporan hasil kerja <strong>' . e($pekerja['nama']) . '</strong> berhasil diperbarui.'
        . ($isBorongan ? ' Upah terhitung <strong>' . e(rupiah($volume * $tarif)) . '</strong>.' : ''));
    redirect('jadwal_harian.php?tanggal=' . $tanggal . '&project_id=' . $projectId);
}

/* ===== Mode baru: bisa beberapa pekerjaan sekaligus ===== */
$ins = db()->prepare(
    'INSERT INTO laporan_kerja (tanggal, user_id, project_id, pekerjaan_id, jadwal_id, volume, upah_satuan, keterangan, created_by)
     VALUES (?,?,?,?,?,?,?,?,?)'
);
$logPj = db()->prepare('INSERT INTO progress_log (pekerjaan_id, user_id, tanggal, progress, catatan) VALUES (?,?,?,?,?)');

$tersimpan = 0;
$totalVolume = 0.0;
$totalUpah = 0.0;
$dilewati = 0;
$itemBaru = [];

foreach ($pekerjaanValid as $wid => $pj) {
    // gabungkan baris dengan item yang sama (kalau pekerja mengisi item sama dua kali)
    $volItem = 0.0;
    $ketItem = [];
    foreach ($barisInput as $b) {
        if ((int) $b['pekerjaan_id'] !== (int) $wid) {
            continue;
        }
        $volItem += (float) $b['volume'];
        if ($b['keterangan'] !== '') {
            $ketItem[] = $b['keterangan'];
        }
    }
    if ($volItem <= 0) {
        $dilewati++;
        continue;
    }

    $tarif = tarif_upah($pj, $userId);
    $upahSatuan = $isBorongan ? $tarif : 0.0;
    $ket = implode('; ', array_unique($ketItem));

    $ins->execute([$tanggal, $userId, $projectId, (int) $wid, $jadwalId ?: null, $volItem, $upahSatuan, $ket, (int) $u['id']]);

    pastikan_penugasan((int) $wid, $userId);
    $volAkhir = sync_volume_realisasi((int) $wid);
    auto_absensi($userId, $tanggal, $projectId, (int) $wid, (int) $u['id']);

    $logPj->execute([
        (int) $wid, $userId, $tanggal, (int) $pj['progress'],
        'Hasil kerja harian: ' . num($volItem) . ' ' . $pj['satuan'] . ' oleh ' . $pekerja['nama']
        . ($ket !== '' ? ' — ' . $ket : ''),
    ]);

    $tersimpan++;
    $totalVolume += $volItem;
    $totalUpah += $volItem * $upahSatuan;
    $itemBaru[] = $pj['nama'] . ' ' . num($volItem) . ' ' . $pj['satuan'];
}

if ($tersimpan === 0) {
    flash('Tidak ada baris yang tersimpan — pilih pekerjaan dan isi volumenya.', 'err');
    redirect('jadwal_harian.php?tanggal=' . $tanggal);
}

db()->prepare('UPDATE jadwal SET pekerjaan_id = COALESCE(pekerjaan_id, ?) WHERE tanggal = ? AND user_id = ? AND project_id = ?')
    ->execute([(int) array_key_first($pekerjaanValid), $tanggal, $userId, $projectId]);

$pesan = '<strong>' . $tersimpan . ' pekerjaan</strong> tersimpan untuk <strong>' . e($pekerja['nama']) . '</strong> (' . e(tgl($tanggal)) . '): '
    . e(implode(' · ', array_slice($itemBaru, 0, 4))) . (count($itemBaru) > 4 ? ', dll' : '') . '.';
if ($isBorongan) {
    $pesan .= ' Total volume ' . e(num($totalVolume)) . ' → upah terhitung <strong>' . e(rupiah($totalUpah)) . '</strong>.';
} else {
    $pesan .= ' Volume dipakai sebagai dasar tagihan ke perusahaan; absensi hari ini otomatis dicatat.';
}
if ($dilewati > 0) {
    $pesan .= ' ' . $dilewati . ' baris dilewati (volume 0).';
}
flash($pesan);

redirect('jadwal_harian.php?tanggal=' . $tanggal . '&project_id=' . $projectId);
