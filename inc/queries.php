<?php
declare(strict_types=1);

/**
 * Kumpulan query yang dipakai beberapa halaman.
 * Visibilitas data mengikuti peran user yang sedang login:
 *  - admin / pelaksana : seluruh data
 *  - pekerja           : hanya pekerjaan yang ditugaskan kepadanya
 */

/**
 * WHERE + args untuk daftar pekerjaan.
 *
 * `$f['semua_item'] = true` melewati batas peran (pekerja hanya melihat pekerjaan
 * yang ditugaskan). Dipakai di form laporan hasil kerja: dalam satu hari seorang
 * pekerja bisa mengerjakan beberapa item sekaligus (narik kabel, pasang unit,
 * terminasi, pasang panel), jadi semua item pada project itu harus bisa dipilih.
 */
function pekerjaan_filters(array $f): array
{
    $w = [];
    $a = [];
    $u = current_user();

    // Wajib: hanya data perusahaan yang sedang aktif
    $w[] = tenant_where('pr', $a);

    if ($u && $u['role'] === 'pekerja' && empty($f['semua_item'])) {
        $w[] = 'EXISTS (SELECT 1 FROM pekerjaan_pekerja x WHERE x.pekerjaan_id = pj.id AND x.user_id = ?)';
        $a[] = (int) $u['id'];
    }
    if (!empty($f['project_id'])) {
        $w[] = 'pj.project_id = ?';
        $a[] = (int) $f['project_id'];
    }
    if (!empty($f['lokasi_id'])) {
        $w[] = 'pj.lokasi_id = ?';
        $a[] = (int) $f['lokasi_id'];
    }
    if (!empty($f['pelaksana_id'])) {
        $w[] = 'pj.pelaksana_id = ?';
        $a[] = (int) $f['pelaksana_id'];
    }
    if (!empty($f['pekerja_id'])) {
        $w[] = 'EXISTS (SELECT 1 FROM pekerjaan_pekerja y WHERE y.pekerjaan_id = pj.id AND y.user_id = ?)';
        $a[] = (int) $f['pekerja_id'];
    }
    if (!empty($f['status'])) {
        if ($f['status'] === 'terlambat') {
            $w[] = "pj.status <> 'selesai' AND pj.deadline <> '' AND pj.deadline < date('now','localtime')";
        } else {
            $w[] = 'pj.status = ?';
            $a[] = $f['status'];
        }
    }
    if (!empty($f['q'])) {
        $like = '%' . $f['q'] . '%';
        $w[] = '(pj.nama LIKE ? OR pj.kategori LIKE ? OR pr.nama LIKE ? OR pr.kode LIKE ?)';
        array_push($a, $like, $like, $like, $like);
    }

    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $a];
}

function order_pekerjaan(string $order = ''): string
{
    if ($order !== '') {
        return $order;
    }
    return "CASE pj.status WHEN 'tertunda' THEN 1 WHEN 'proses' THEN 2 WHEN 'belum' THEN 3 ELSE 4 END,
            CASE WHEN pj.deadline = '' THEN 1 ELSE 0 END, pj.deadline ASC, pj.nama";
}

function fetch_pekerjaan(array $f, string $order = ''): array
{
    [$where, $args] = pekerjaan_filters($f);
    $sql = "SELECT pj.*, pr.nama AS project_nama, pr.kode AS project_kode, pr.pelaksana_id AS project_pelaksana_id,
                   l.nama AS lokasi_nama, u.nama AS pelaksana_nama
            FROM pekerjaan pj
            JOIN projects pr ON pr.id = pj.project_id
            LEFT JOIN lokasi l ON l.id = pj.lokasi_id
            LEFT JOIN users u ON u.id = pj.pelaksana_id
            $where
            ORDER BY " . order_pekerjaan($order);
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function count_pekerjaan(array $f): int
{
    [$where, $args] = pekerjaan_filters($f);
    $sql = "SELECT COUNT(*) FROM pekerjaan pj JOIN projects pr ON pr.id = pj.project_id $where";
    $st = db()->prepare($sql);
    $st->execute($args);
    return (int) $st->fetchColumn();
}

function avg_progress(array $f): int
{
    [$where, $args] = pekerjaan_filters($f);
    $sql = "SELECT AVG(pj.progress) FROM pekerjaan pj JOIN projects pr ON pr.id = pj.project_id $where";
    $st = db()->prepare($sql);
    $st->execute($args);
    $v = $st->fetchColumn();
    return $v === null ? 0 : (int) round((float) $v);
}

/** WHERE + args untuk daftar project sesuai peran */
function project_filters(array $f): array
{
    $w = [];
    $a = [];
    $u = current_user();

    $w[] = tenant_where('pr', $a);

    if ($u && $u['role'] === 'pekerja') {
        $w[] = 'EXISTS (SELECT 1 FROM pekerjaan pj JOIN pekerjaan_pekerja pp ON pp.pekerjaan_id = pj.id
                        WHERE pj.project_id = pr.id AND pp.user_id = ?)';
        $a[] = (int) $u['id'];
    }
    if (!empty($f['project_id'])) {
        $w[] = 'pr.id = ?';
        $a[] = (int) $f['project_id'];
    }
    if (!empty($f['pelaksana_id'])) {
        $w[] = 'pr.pelaksana_id = ?';
        $a[] = (int) $f['pelaksana_id'];
    }
    if (!empty($f['status'])) {
        $w[] = 'pr.status = ?';
        $a[] = $f['status'];
    }
    if (!empty($f['q'])) {
        $like = '%' . $f['q'] . '%';
        $w[] = '(pr.nama LIKE ? OR pr.kode LIKE ?)';
        array_push($a, $like, $like);
    }
    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $a];
}

function fetch_projects(array $f, string $order = ''): array
{
    [$where, $args] = project_filters($f);
    $order = $order ?: "CASE pr.status WHEN 'berjalan' THEN 1 WHEN 'perencanaan' THEN 2 WHEN 'ditunda' THEN 3 ELSE 4 END, pr.target_selesai = '', pr.target_selesai";
    $sql = "SELECT pr.*, u.nama AS pelaksana_nama, u.jabatan AS pelaksana_jabatan,
                   (SELECT COUNT(*) FROM pekerjaan pj WHERE pj.project_id = pr.id) AS jml_pekerjaan,
                   (SELECT COUNT(*) FROM pekerjaan pj WHERE pj.project_id = pr.id AND pj.status = 'selesai') AS jml_selesai,
                   (SELECT COUNT(*) FROM pekerjaan pj WHERE pj.project_id = pr.id AND pj.status <> 'selesai'
                        AND pj.deadline <> '' AND pj.deadline < date('now','localtime')) AS jml_terlambat,
                   (SELECT COUNT(*) FROM lokasi l WHERE l.project_id = pr.id) AS jml_lokasi,
                   (SELECT AVG(pj.progress) FROM pekerjaan pj WHERE pj.project_id = pr.id) AS avg_progress
            FROM projects pr
            LEFT JOIN users u ON u.id = pr.pelaksana_id
            $where
            ORDER BY $order";
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function get_project_summary(int $id): ?array
{
    $rows = fetch_projects(['project_id' => $id]);
    return $rows[0] ?? null;
}

/** Ringkasan beban kerja seorang pelaksana */
function pelaksana_stats(int $userId): array
{
    $st = db()->prepare(
        "SELECT
           (SELECT COUNT(*) FROM projects WHERE pelaksana_id = :id AND perusahaan_id = :tk) AS projects,
           (SELECT COUNT(*) FROM projects WHERE pelaksana_id = :id AND perusahaan_id = :tk AND status = 'berjalan') AS projects_berjalan,
           (SELECT COUNT(*) FROM pekerjaan WHERE pelaksana_id = :id AND perusahaan_id = :tk) AS pekerjaan,
           (SELECT COUNT(*) FROM pekerjaan WHERE pelaksana_id = :id AND perusahaan_id = :tk AND status = 'proses') AS proses,
           (SELECT COUNT(*) FROM pekerjaan WHERE pelaksana_id = :id AND perusahaan_id = :tk AND status = 'selesai') AS selesai,
           (SELECT AVG(progress) FROM pekerjaan WHERE pelaksana_id = :id AND perusahaan_id = :tk) AS avg_progress,
           (SELECT COUNT(*) FROM pekerjaan WHERE pelaksana_id = :id AND perusahaan_id = :tk AND status <> 'selesai'
                AND deadline <> '' AND deadline < date('now','localtime')) AS terlambat"
    );
    $st->execute([':id' => $userId, ':tk' => tenant_id()]);
    $r = $st->fetch() ?: [];
    $r['avg_progress'] = $r['avg_progress'] === null ? 0 : (int) round((float) $r['avg_progress']);
    return $r;
}

/** Ringkasan beban kerja seorang pekerja */
function pekerja_stats(int $userId): array
{
    $st = db()->prepare(
        "SELECT
           (SELECT COUNT(*) FROM pekerjaan_pekerja pp JOIN pekerjaan pj ON pj.id = pp.pekerjaan_id
                WHERE pp.user_id = :id AND pj.perusahaan_id = :tk) AS tugas,
           (SELECT COUNT(*) FROM pekerjaan_pekerja pp JOIN pekerjaan pj ON pj.id = pp.pekerjaan_id
                WHERE pp.user_id = :id AND pj.perusahaan_id = :tk AND pj.status = 'proses') AS proses,
           (SELECT COUNT(*) FROM pekerjaan_pekerja pp JOIN pekerjaan pj ON pj.id = pp.pekerjaan_id
                WHERE pp.user_id = :id AND pj.perusahaan_id = :tk AND pj.status = 'selesai') AS selesai,
           (SELECT COUNT(DISTINCT pj.project_id) FROM pekerjaan_pekerja pp JOIN pekerjaan pj ON pj.id = pp.pekerjaan_id
                WHERE pp.user_id = :id AND pj.perusahaan_id = :tk) AS projects,
           (SELECT COUNT(*) FROM pekerjaan_pekerja pp JOIN pekerjaan pj ON pj.id = pp.pekerjaan_id
                WHERE pp.user_id = :id AND pj.perusahaan_id = :tk AND pj.status <> 'selesai' AND pj.deadline <> ''
                AND pj.deadline < date('now','localtime')) AS terlambat"
    );
    $st->execute([':id' => $userId, ':tk' => tenant_id()]);
    return $st->fetch() ?: [];
}

/** Project yang boleh dibuka user (untuk dropdown) */
function selectable_projects(): array
{
    return fetch_projects([], 'pr.nama');
}

function selectable_pelaksana(): array
{
    return all_users('pelaksana');
}

function selectable_pekerja(): array
{
    return all_users('pekerja');
}

function lokasi_of_project(int $projectId): array
{
    $st = db()->prepare('SELECT * FROM lokasi WHERE project_id = ? AND perusahaan_id = ? ORDER BY nama');
    $st->execute([$projectId, tenant_id()]);
    return $st->fetchAll();
}

function log_of_pekerjaan(int $id): array
{
    $st = db()->prepare(
        'SELECT pl.*, u.nama AS user_nama FROM progress_log pl
         LEFT JOIN users u ON u.id = pl.user_id
         WHERE pl.pekerjaan_id = ?
         ORDER BY pl.tanggal DESC, pl.id DESC'
    );
    $st->execute([$id]);
    return $st->fetchAll();
}

/* ==========================================================================
   ABSENSI HARI KERJA & UPAH
   ========================================================================== */

/** WHERE + args untuk daftar absensi (pekerja hanya melihat absensinya sendiri) */
function absensi_filters(array $f): array
{
    $w = [];
    $a = [];
    $u = current_user();

    $w[] = tenant_where('a', $a);

    if ($u && $u['role'] === 'pekerja') {
        $w[] = 'a.user_id = ?';
        $a[] = (int) $u['id'];
    } elseif (!empty($f['user_id'])) {
        $w[] = 'a.user_id = ?';
        $a[] = (int) $f['user_id'];
    }
    if (!empty($f['project_id'])) {
        $w[] = 'a.project_id = ?';
        $a[] = (int) $f['project_id'];
    }
    if (!empty($f['dari'])) {
        $w[] = 'a.tanggal >= ?';
        $a[] = $f['dari'];
    }
    if (!empty($f['sampai'])) {
        $w[] = 'a.tanggal <= ?';
        $a[] = $f['sampai'];
    }
    if (!empty($f['q'])) {
        $like = '%' . $f['q'] . '%';
        $w[] = '(us.nama LIKE ? OR a.keterangan LIKE ?)';
        array_push($a, $like, $like);
    }

    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $a];
}

function fetch_absensi(array $f, int $limit = 500): array
{
    [$where, $args] = absensi_filters($f);
    $sql = "SELECT a.*, us.nama AS pekerja_nama, us.role AS pekerja_role, us.jabatan AS pekerja_jabatan,
                   pr.nama AS project_nama, pr.kode AS project_kode,
                   pj.nama AS pekerjaan_nama, cb.nama AS pencatat_nama
            FROM absensi a
            JOIN users us ON us.id = a.user_id
            LEFT JOIN projects pr ON pr.id = a.project_id
            LEFT JOIN pekerjaan pj ON pj.id = a.pekerjaan_id
            LEFT JOIN users cb ON cb.id = a.created_by
            $where
            ORDER BY a.tanggal DESC, us.nama
            LIMIT " . (int) $limit;
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** Ringkasan hari kerja + upah harian menurut filter */
function absensi_summary(array $f): array
{
    [$where, $args] = absensi_filters($f);
    $sql = "SELECT COUNT(*) AS baris,
                   COALESCE(SUM(a.hari), 0) AS hari,
                   COALESCE(SUM(a.hari * a.upah), 0) AS upah,
                   COUNT(DISTINCT a.user_id) AS orang,
                   COUNT(DISTINCT a.tanggal) AS tanggal
            FROM absensi a JOIN users us ON us.id = a.user_id $where";
    $st = db()->prepare($sql);
    $st->execute($args);
    $r = $st->fetch() ?: [];
    return [
        'baris'   => (int) ($r['baris'] ?? 0),
        'hari'    => (float) ($r['hari'] ?? 0),
        'upah'    => (float) ($r['upah'] ?? 0),
        'orang'   => (int) ($r['orang'] ?? 0),
        'tanggal' => (int) ($r['tanggal'] ?? 0),
    ];
}

function get_absensi(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM absensi WHERE id = ? AND perusahaan_id = ?');
    $st->execute([$id, tenant_id()]);
    return $st->fetch() ?: null;
}

/**
 * Rekap upah per pekerja: hari kerja, upah harian, borongan selesai/berjalan.
 * $f: dari, sampai, project_id, user_id
 */
function rekap_upah(array $f): array
{
    $u = current_user();
    $userId = (int) ($f['user_id'] ?? 0);
    if ($u && $u['role'] === 'pekerja') {
        $userId = (int) $u['id'];
    }
    $projectId = (int) ($f['project_id'] ?? 0);
    $dari = (string) ($f['dari'] ?? '');
    $sampai = (string) ($f['sampai'] ?? '');

    // 1. hari kerja + upah harian
    $w = ['1=1'];
    $a = [];
    if ($userId) {
        $w[] = 'a.user_id = ?';
        $a[] = $userId;
    }
    if ($projectId) {
        $w[] = 'a.project_id = ?';
        $a[] = $projectId;
    }
    if ($dari !== '') {
        $w[] = 'a.tanggal >= ?';
        $a[] = $dari;
    }
    if ($sampai !== '') {
        $w[] = 'a.tanggal <= ?';
        $a[] = $sampai;
    }
    $w[] = 'a.perusahaan_id = ?';
    $a[] = tenant_id();
    $sql = 'SELECT a.user_id, us.nama, us.role, us.jabatan,
                   us.upah_harian AS tarif_harian, us.skema,
                   COALESCE(SUM(a.hari), 0) AS hari,
                   COALESCE(SUM(a.hari * a.upah), 0) AS upah_absensi,
                   COUNT(DISTINCT a.project_id) AS jml_project
            FROM absensi a JOIN users us ON us.id = a.user_id
            WHERE ' . implode(' AND ', $w) . '
            GROUP BY a.user_id';
    $st = db()->prepare($sql);
    $st->execute($a);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[(int) $r['user_id']] = [
            'user_id'     => (int) $r['user_id'],
            'nama'        => $r['nama'],
            'role'        => $r['role'],
            'jabatan'     => $r['jabatan'],
            'tarif'       => (float) $r['tarif_harian'],
            'skema'       => $r['skema'],
            'hari'        => (float) $r['hari'],
            'upah_harian' => (float) $r['upah_absensi'],
            'jml_project' => (int) $r['jml_project'],
            'volume_lapor' => 0.0,
            'borongan_selesai'  => 0.0,
            'borongan_berjalan' => 0.0,
            'jml_borongan'      => 0,
        ];
    }

    // 2. upah borongan (hanya pekerja ber-skema borongan)
    $bor = upah_borongan_per_pekerja(['project_id' => $projectId, 'user_id' => $userId]);

    foreach ($bor as $uid => $b) {
        if (!isset($rows[$uid])) {
            $st = db()->prepare('SELECT nama, role, jabatan, upah_harian, skema FROM users WHERE id = ? AND perusahaan_id = ?');
            $st->execute([$uid, tenant_id()]);
            $u2 = $st->fetch();
            if (!$u2) {
                continue;
            }
            $rows[$uid] = [
                'user_id' => $uid, 'nama' => $u2['nama'], 'role' => $u2['role'], 'jabatan' => $u2['jabatan'],
                'tarif' => (float) $u2['upah_harian'], 'skema' => $u2['skema'],
                'hari' => 0.0, 'upah_harian' => 0.0, 'jml_project' => 0,
                'borongan_selesai' => 0.0, 'borongan_berjalan' => 0.0, 'jml_borongan' => 0, 'volume_lapor' => 0.0,
            ];
        }
        $rows[$uid]['borongan_selesai'] = $b['cair'];
        $rows[$uid]['borongan_berjalan'] = $b['berjalan'];
        $rows[$uid]['jml_borongan'] = $b['jml'];
        $rows[$uid]['volume_lapor'] = $b['volume'];
    }

    foreach ($rows as $uid => &$r) {
        $r['skema'] = $r['skema'] ?? 'harian';
        // pekerja borongan tidak digaji harian -> absensinya hanya catatan kehadiran
        if ($r['skema'] === 'borongan') {
            $r['upah_harian'] = 0.0;
        }
        $r['total'] = $r['upah_harian'] + $r['borongan_selesai'];
        $r['total_berjalan'] = $r['borongan_berjalan'];
    }
    unset($r);

    usort($rows, fn($x, $y) => $y['total'] <=> $x['total'] ?: strcmp($x['nama'], $y['nama']));
    return $rows;
}

/** Hari kerja & upah seorang pekerja pada satu pekerjaan */
function absensi_of_pekerjaan(int $pekerjaanId): array
{
    $st = db()->prepare(
        'SELECT a.user_id, us.nama, COALESCE(SUM(a.hari),0) AS hari, COALESCE(SUM(a.hari * a.upah),0) AS upah
         FROM absensi a JOIN users us ON us.id = a.user_id
         WHERE a.pekerjaan_id = ? AND a.perusahaan_id = ?
         GROUP BY a.user_id ORDER BY us.nama'
    );
    $st->execute([$pekerjaanId, tenant_id()]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int) $r['user_id']] = $r;
    }
    return $out;
}

/** Ringkasan hari kerja & upah satu pekerja pada rentang tanggal */
function upah_user_periode(int $userId, string $dari, string $sampai, int $projectId = 0): array
{
    $sql = 'SELECT COALESCE(SUM(hari),0) AS hari, COALESCE(SUM(hari * upah),0) AS upah
            FROM absensi WHERE user_id = ? AND perusahaan_id = ?';
    $args = [$userId, tenant_id()];
    if ($dari !== '') {
        $sql .= ' AND tanggal >= ?';
        $args[] = $dari;
    }
    if ($sampai !== '') {
        $sql .= ' AND tanggal <= ?';
        $args[] = $sampai;
    }
    if ($projectId) {
        $sql .= ' AND project_id = ?';
        $args[] = $projectId;
    }
    $st = db()->prepare($sql);
    $st->execute($args);
    $r = $st->fetch() ?: [];
    $hari = (float) ($r['hari'] ?? 0);
    $upahAbsensi = (float) ($r['upah'] ?? 0);

    $st = db()->prepare('SELECT skema, upah_harian FROM users WHERE id = ? AND perusahaan_id = ?');
    $st->execute([$userId, tenant_id()]);
    $u = $st->fetch() ?: ['skema' => 'harian', 'upah_harian' => 0];

    $bor = upah_borongan_per_pekerja(['user_id' => $userId, 'project_id' => $projectId,
        'dari' => $dari, 'sampai' => $sampai]);
    $b = $bor[$userId] ?? ['cair' => 0.0, 'berjalan' => 0.0, 'jml' => 0, 'volume' => 0.0];

    $isBorongan = $u['skema'] === 'borongan';
    return [
        'skema' => $u['skema'],
        'hari' => $hari,
        'upah_harian' => $isBorongan ? 0.0 : $upahAbsensi,
        'upah_absensi' => $upahAbsensi,
        'borongan_selesai' => $isBorongan ? (float) $b['cair'] : 0.0,
        'borongan_berjalan' => $isBorongan ? (float) $b['berjalan'] : 0.0,
        'volume_lapor' => (float) $b['volume'],
        'jml_borongan' => (int) $b['jml'],
        'total' => ($isBorongan ? 0.0 : $upahAbsensi) + ($isBorongan ? (float) $b['cair'] : 0.0),
    ];
}

/** Ringkasan total hari kerja & upah seluruh pekerja pada rentang tanggal */
function upah_total_periode(string $dari, string $sampai, int $projectId = 0, int $userId = 0): array
{
    $w = ['1=1'];
    $a = [];
    $w[] = 'perusahaan_id = ?'; $a[] = tenant_id();
    if ($dari !== '') { $w[] = 'tanggal >= ?'; $a[] = $dari; }
    if ($sampai !== '') { $w[] = 'tanggal <= ?'; $a[] = $sampai; }
    if ($projectId) { $w[] = 'project_id = ?'; $a[] = $projectId; }
    if ($userId) { $w[] = 'user_id = ?'; $a[] = $userId; }
    $st = db()->prepare('SELECT COALESCE(SUM(hari),0) AS hari, COALESCE(SUM(hari*upah),0) AS upah,
                                COUNT(DISTINCT user_id) AS orang
                         FROM absensi WHERE ' . implode(' AND ', $w));
    $st->execute($a);
    $r = $st->fetch() ?: [];

    // Upah borongan: hanya pekerja ber-skema borongan
    $bor = upah_borongan_per_pekerja(['project_id' => $projectId, 'user_id' => $userId,
        'dari' => $dari, 'sampai' => $sampai]);
    $borongan = 0.0;
    foreach ($bor as $b) {
        $borongan += (float) $b['cair'];
    }

    // Absensi milik pekerja borongan tidak dihitung sebagai gaji (hanya kehadiran)
    $skemaBor = [];
    $stBor = db()->prepare("SELECT id FROM users WHERE skema = 'borongan' AND perusahaan_id = ?");
    $stBor->execute([tenant_id()]);
    foreach ($stBor->fetchAll(PDO::FETCH_ASSOC) as $r2) {
        $skemaBor[] = (int) $r2['id'];
    }
    $upahAbsen = (float) ($r['upah'] ?? 0);
    if ($userId && in_array($userId, $skemaBor, true)) {
        $upahAbsen = 0.0;
    } elseif (!$userId && $skemaBor) {
        $st = db()->prepare('SELECT COALESCE(SUM(hari * upah),0) FROM absensi
                             WHERE perusahaan_id = ? AND user_id IN (' . implode(',', array_fill(0, count($skemaBor), '?')) . ')
                             ' . ($projectId ? 'AND project_id = ?' : '')
                             . ($dari !== '' ? ' AND tanggal >= ?' : '')
                             . ($sampai !== '' ? ' AND tanggal <= ?' : ''));
        $argsAbs = array_merge([tenant_id()], $skemaBor);
        if ($projectId) { $argsAbs[] = $projectId; }
        if ($dari !== '') { $argsAbs[] = $dari; }
        if ($sampai !== '') { $argsAbs[] = $sampai; }
        $st->execute($argsAbs);
        $upahAbsen -= (float) $st->fetchColumn();
        if ($upahAbsen < 0) {
            $upahAbsen = 0.0;
        }
    }

    return [
        'hari' => (float) ($r['hari'] ?? 0),
        'upah_harian' => $upahAbsen,
        'orang' => (int) ($r['orang'] ?? 0),
        'borongan_selesai' => $borongan,
        'total' => $upahAbsen + $borongan,
    ];
}

/** Ringkasan nilai jasa (tagihan ke perusahaan) untuk dashboard */
function nilai_jasa_periode(int $projectId = 0): array
{
    $f = $projectId ? ['project_id' => $projectId] : [];
    $rows = fetch_pekerjaan($f);
    $diajukan = 0.0;
    $kontrak = 0.0;
    $selesai = 0;
    $total = 0;
    foreach ($rows as $pj) {
        $total++;
        $kontrak += nilai_kontrak_pekerjaan($pj);
        if ($pj['status'] === 'selesai') {
            $selesai++;
            $diajukan += nilai_jasa_pekerjaan($pj);
        }
    }
    return [
        'jml_item' => $total,
        'jml_selesai' => $selesai,
        'nilai_kontrak' => $kontrak,
        'nilai_diajukan' => $diajukan,
    ];
}

/* ==========================================================================
   HARGA SATUAN (MASTER), NILAI JASA & UPAH BORONGAN PER ITEM
   --------------------------------------------------------------------------
   Dua harga berbeda:
     harga_jasa = ditagihkan ke perusahaan pemilik pekerjaan (per satuan)
     harga_upah = dibayarkan ke pekerja (per satuan), boleh di-override per orang
   ========================================================================== */

function fetch_harga_satuan(array $f = []): array
{
    $a = [];
    $w = [tenant_where('harga_satuan', $a)];
    if (!empty($f['q'])) {
        $like = '%' . $f['q'] . '%';
        $w[] = '(nama LIKE ? OR kategori LIKE ?)';
        array_push($a, $like, $like);
    }
    if (isset($f['aktif']) && $f['aktif'] !== '') {
        $w[] = 'aktif = ?';
        $a[] = (int) $f['aktif'];
    }
    $sql = 'SELECT * FROM harga_satuan WHERE ' . implode(' AND ', $w) . ' ORDER BY kategori, nama';
    $st = db()->prepare($sql);
    $st->execute($a);
    return $st->fetchAll();
}

function get_harga_satuan(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM harga_satuan WHERE id = ? AND perusahaan_id = ?');
    $st->execute([$id, tenant_id()]);
    return $st->fetch() ?: null;
}

function harga_satuan_pilihan(): array
{
    return fetch_harga_satuan(['aktif' => 1]);
}

/** Volume yang dipakai untuk menghitung uang: volume akhir bila sudah dicatat,
 *  kalau belum pakai volume kontrak hanya bila pekerjaannya sudah selesai. */
function volume_efektif(array $pj): float
{
    $real = (float) ($pj['volume_realisasi'] ?? 0);
    if ($real > 0) {
        return $real;
    }
    return ($pj['status'] ?? '') === 'selesai' ? (float) ($pj['volume'] ?? 0) : 0.0;
}

/** Nilai jasa yang bisa ditagihkan ke perusahaan untuk sebuah pekerjaan */
function nilai_jasa_pekerjaan(array $pj): float
{
    return (float) ($pj['harga_jasa'] ?? 0) * volume_efektif($pj);
}

/** Nilai kontrak (memakai volume kontrak penuh) */
function nilai_kontrak_pekerjaan(array $pj): float
{
    return (float) ($pj['harga_jasa'] ?? 0) * (float) ($pj['volume'] ?? 0);
}

/**
 * Menghitung upah SEMUA pekerja pada satu pekerjaan, sesuai skema upah masing-masing.
 *
 * - pekerja skema 'harian'   → upah borongan 0 (gajinya dari absensi), tapi volume
 *                              yang dilaporkannya tetap dihitung untuk tagihan ke perusahaan.
 * - pekerja skema 'borongan' → upah = Σ(volume laporan × upah_satuan tercatat).
 *                              Kalau pekerja ini belum pernah melapor, dipakai aturan lama:
 *                              nominal tetap (upah_borongan) atau harga × volume efektif × bagian.
 *
 * @return array ['pekerjaan','rows','total','total_cair','total_berjalan','total_harian_ref']
 */
function hitung_upah_pekerjaan(int $pekerjaanId): array
{
    $st = db()->prepare('SELECT * FROM pekerjaan WHERE id = ? AND perusahaan_id = ?');
    $st->execute([$pekerjaanId, tenant_id()]);
    $pj = $st->fetch();
    if (!$pj) {
        return ['pekerjaan' => null, 'rows' => [], 'total' => 0.0, 'total_cair' => 0.0,
                'total_berjalan' => 0.0, 'volume_lapor' => 0.0, 'upah_harian_terkait' => 0.0];
    }

    $st = db()->prepare(
        'SELECT pp.user_id, pp.upah_borongan, pp.bagian_pct, pp.harga_upah_override,
                u.nama, u.jabatan, u.role, u.skema, u.upah_harian
         FROM pekerjaan_pekerja pp JOIN users u ON u.id = pp.user_id
         WHERE pp.pekerjaan_id = ?'
    );
    $st->execute([$pekerjaanId]);
    $asg = [];
    foreach ($st->fetchAll() as $r) {
        $asg[(int) $r['user_id']] = $r;
    }

    $lapor = laporan_of_pekerjaan($pekerjaanId);
    // Kalau item ini sudah dikerjakan dengan laporan harian, upah borongan HANYA
    // dari volume yang dilaporkan masing-masing pekerja (model lama tidak dipakai lagi).
    $pakaiLaporan = count($lapor) > 0;
    // pekerja yang melapor tapi belum ada di daftar penugasan tetap dihitung
    foreach ($lapor as $uid => $l) {
        if (!isset($asg[$uid])) {
            $st = db()->prepare('SELECT id AS user_id, 0 AS upah_borongan, 0 AS bagian_pct,
                                        0 AS harga_upah_override, nama, jabatan, role, skema, upah_harian
                                 FROM users WHERE id = ?');
            $st->execute([$uid]);
            $row = $st->fetch();
            if ($row) {
                $row['upah_borongan'] = 0.0;
                $row['bagian_pct'] = 0.0;
                $row['harga_upah_override'] = 0.0;
                $asg[$uid] = $row;
            }
        }
    }

    // bagian otomatis: dibagi rata antar pekerja BORONGAN yang belum melapor & tanpa bagian
    $perBagian = [];
    foreach ($asg as $uid => $r) {
        if ($pakaiLaporan || $r['skema'] !== 'borongan') {
            continue;
        }
        if (isset($lapor[$uid]) && (float) $lapor[$uid]['volume'] > 0) {
            continue; // sudah dihitung dari volume laporannya sendiri
        }
        if ((float) $r['upah_borongan'] > 0) {
            continue; // nominal tetap
        }
        $perBagian[$uid] = $r;
    }
    $pctTerpakai = 0.0;
    $auto = [];
    foreach ($perBagian as $uid => $r) {
        if ((float) $r['bagian_pct'] > 0) {
            $pctTerpakai += (float) $r['bagian_pct'];
        } else {
            $auto[] = $uid;
        }
    }
    $sisa = max(0.0, 100.0 - $pctTerpakai);
    $pctAuto = $auto ? $sisa / count($auto) : 0.0;

    $volEfektif = volume_efektif($pj);
    $rows = [];
    $total = 0.0;
    $cair = 0.0;
    foreach ($asg as $uid => $r) {
        $lap = $lapor[$uid] ?? null;
        $volLapor = $lap ? (float) $lap['volume'] : 0.0;
        $nilai = 0.0;
        $cara = 'harian';
        $rate = (float) $r['harga_upah_override'] > 0 ? (float) $r['harga_upah_override'] : (float) $pj['harga_upah'];
        $pct = 0.0;
        $dariLaporan = false;

        if ($r['skema'] === 'borongan') {
            if ($volLapor > 0) {
                $nilai = (float) $lap['nilai'];
                $cara = 'laporan';
                $dariLaporan = true;
                $rate = $volLapor > 0 ? round((float) $lap['nilai'] / $volLapor) : $rate;
                $pct = 100.0;
            } elseif ($pakaiLaporan) {
                $nilai = 0.0;
                $cara = 'menunggu_laporan';
            } elseif ((float) $r['upah_borongan'] > 0) {
                $nilai = (float) $r['upah_borongan'];
                $cara = 'nominal';
                $rate = 0.0;
            } else {
                $pct = (float) $r['bagian_pct'] > 0 ? (float) $r['bagian_pct'] : $pctAuto;
                $nilai = $rate * $volEfektif * ($pct / 100);
                $cara = 'bagian';
            }
        }

        $rows[] = [
            'user_id' => $uid,
            'nama' => $r['nama'],
            'jabatan' => $r['jabatan'],
            'role' => $r['role'],
            'skema' => $r['skema'],
            'upah_harian' => (float) $r['upah_harian'],
            'upah_borongan' => (float) $r['upah_borongan'],
            'bagian_pct' => (float) $r['bagian_pct'],
            'harga_upah_override' => (float) $r['harga_upah_override'],
            'volume_lapor' => $volLapor,
            'jml_laporan' => $lap ? (int) $lap['jml'] : 0,
            'nilai' => $nilai,
            'mode' => $cara,
            'rate' => $rate,
            'pct' => $pct,
            'dari_laporan' => $dariLaporan,
        ];
        $total += $nilai;
        // Upah dari laporan harian = pekerjaan sudah dikerjakan & dilaporkan -> langsung terhitung.
        // Upah model lama baru cair setelah pekerjaan berstatus selesai.
        if ($nilai > 0 && ($dariLaporan || $pj['status'] === 'selesai')) {
            $cair += $nilai;
        }
    }

    usort($rows, fn($a, $b) => $b['nilai'] <=> $a['nilai'] ?: strcmp($a['nama'], $b['nama']));

    $st = db()->prepare('SELECT COALESCE(SUM(lk.volume),0) FROM laporan_kerja lk WHERE lk.pekerjaan_id = ?');
    $st->execute([$pekerjaanId]);
    $volLaporTotal = (float) $st->fetchColumn();

    $st = db()->prepare('SELECT COALESCE(SUM(a.hari * a.upah),0) FROM absensi a WHERE a.pekerjaan_id = ?');
    $st->execute([$pekerjaanId]);
    $upahHarian = (float) $st->fetchColumn();

    return [
        'pekerjaan' => $pj,
        'rows' => $rows,
        'total' => $total,
        'total_cair' => $cair,
        'total_berjalan' => max(0.0, $total - $cair),
        'volume_lapor' => $volLaporTotal,
        'upah_harian_terkait' => $upahHarian,
    ];
}

/** Alias lama (dipakai halaman detail pekerjaan) */
function borongan_detail(int $pekerjaanId): array
{
    return hitung_upah_pekerjaan($pekerjaanId);
}

/**
 * Rekap upah borongan semua pekerja (bulk, ringan untuk daftar panjang).
 *
 * Hanya pekerja ber-skema 'borongan' yang mendapat upah borongan:
 *   1. dari laporan harian  -> Σ(volume × upah_satuan)  [cair, sudah dikerjakan]
 *   2. model lama (tanpa laporan) -> nominal tetap, atau harga × volume efektif × bagian
 *      [cair bila pekerjaan sudah 'selesai', selain itu berjalan]
 *
 * @param array $f project_id, user_id, dari, sampai
 * @return array [user_id => ['cair','berjalan','jml','volume','dari_laporan','items'=>[...]]]
 */
function upah_borongan_per_pekerja(array $f = []): array
{
    $projectId = (int) ($f['project_id'] ?? 0);
    $userId = (int) ($f['user_id'] ?? 0);
    $dari = (string) ($f['dari'] ?? '');
    $sampai = (string) ($f['sampai'] ?? '');

    $out = [];
    $pastikan = static function (array &$out, int $uid): void {
        if (!isset($out[$uid])) {
            $out[$uid] = ['cair' => 0.0, 'berjalan' => 0.0, 'jml' => 0, 'volume' => 0.0,
                          'dari_laporan' => 0.0, 'items' => []];
        }
    };

    /* ---------- 1. Upah dari laporan harian ---------- */
    $w = ["us.skema = 'borongan'"];
    $a = [];
    if ($projectId) { $w[] = 'lk.project_id = ?'; $a[] = $projectId; }
    if ($userId) { $w[] = 'lk.user_id = ?'; $a[] = $userId; }
    if ($dari !== '') { $w[] = 'lk.tanggal >= ?'; $a[] = $dari; }
    if ($sampai !== '') { $w[] = 'lk.tanggal <= ?'; $a[] = $sampai; }
    $w[] = 'lk.perusahaan_id = ?';
    $a[] = tenant_id();
    $st = db()->prepare(
        'SELECT lk.user_id, lk.pekerjaan_id, COUNT(*) AS jml, SUM(lk.volume) AS volume,
                SUM(lk.volume * lk.upah_satuan) AS nilai
         FROM laporan_kerja lk JOIN users us ON us.id = lk.user_id
         WHERE ' . implode(' AND ', $w) . '
         GROUP BY lk.user_id, lk.pekerjaan_id'
    );
    $st->execute($a);
    $adaLaporan = [];
    foreach ($st->fetchAll() as $r) {
        $uid = (int) $r['user_id'];
        $pastikan($out, $uid);
        $out[$uid]['cair'] += (float) $r['nilai'];
        $out[$uid]['dari_laporan'] += (float) $r['nilai'];
        $out[$uid]['volume'] += (float) $r['volume'];
        $out[$uid]['jml'] += (int) $r['jml'];
        $adaLaporan[$uid . '-' . (int) $r['pekerjaan_id']] = true;
        $out[$uid]['items'][] = ['pekerjaan_id' => (int) $r['pekerjaan_id'], 'mode' => 'laporan',
                                 'nilai' => (float) $r['nilai'], 'volume' => (float) $r['volume']];
    }

    /* ---------- 2. Model lama (khusus item yang belum pakai laporan harian) ---------- */
    $itemLaporan = [];
    $st = db()->prepare('SELECT DISTINCT pekerjaan_id FROM laporan_kerja WHERE perusahaan_id = ?'
        . ($projectId ? ' AND project_id = ?' : ''));
    $st->execute($projectId ? [tenant_id(), $projectId] : [tenant_id()]);
    foreach ($st->fetchAll() as $r3) {
        $itemLaporan[(int) $r3['pekerjaan_id']] = true;
    }

    $w = ["us.skema = 'borongan'"];
    $a = [];
    if ($projectId) { $w[] = 'pj.project_id = ?'; $a[] = $projectId; }
    if ($userId) { $w[] = 'pp.user_id = ?'; $a[] = $userId; }
    $w[] = 'pj.perusahaan_id = ?';
    $a[] = tenant_id();
    $st = db()->prepare(
        'SELECT pp.user_id, pp.pekerjaan_id, pp.upah_borongan, pp.bagian_pct, pp.harga_upah_override,
                pj.status, pj.volume, pj.volume_realisasi, pj.harga_upah, pj.satuan
         FROM pekerjaan_pekerja pp
         JOIN pekerjaan pj ON pj.id = pp.pekerjaan_id
         JOIN users us ON us.id = pp.user_id
         WHERE ' . implode(' AND ', $w)
    );
    $st->execute($a);
    $perItem = [];
    foreach ($st->fetchAll() as $r) {
        $perItem[(int) $r['pekerjaan_id']][] = $r;
    }
    foreach ($perItem as $wid => $anggota) {
        if (isset($itemLaporan[$wid])) {
            continue; // item ini pakai laporan harian -> murni dari volume laporan
        }
        $volEfektif = null;
        $pctTerpakai = 0.0;
        $auto = [];
        foreach ($anggota as $r) {
            if (isset($adaLaporan[(int) $r['user_id'] . '-' . $wid])) {
                continue;
            }
            if ((float) $r['upah_borongan'] > 0) {
                continue;
            }
            if ((float) $r['bagian_pct'] > 0) {
                $pctTerpakai += (float) $r['bagian_pct'];
            } else {
                $auto[] = (int) $r['user_id'];
            }
        }
        $sisa = max(0.0, 100.0 - $pctTerpakai);
        $pctAuto = $auto ? $sisa / count($auto) : 0.0;

        foreach ($anggota as $r) {
            $uid = (int) $r['user_id'];
            if (isset($adaLaporan[$uid . '-' . $wid])) {
                continue;
            }
            if ($volEfektif === null) {
                $volEfektif = volume_efektif($r);
            }
            $nilai = 0.0;
            $mode = 'bagian';
            if ((float) $r['upah_borongan'] > 0) {
                $nilai = (float) $r['upah_borongan'];
                $mode = 'nominal';
            } else {
                $rate = (float) $r['harga_upah_override'] > 0 ? (float) $r['harga_upah_override'] : (float) $r['harga_upah'];
                $pct = (float) $r['bagian_pct'] > 0 ? (float) $r['bagian_pct'] : $pctAuto;
                $nilai = $rate * $volEfektif * ($pct / 100);
            }
            if ($nilai <= 0) {
                continue;
            }
            $pastikan($out, $uid);
            if ($r['status'] === 'selesai') {
                $out[$uid]['cair'] += $nilai;
            } else {
                $out[$uid]['berjalan'] += $nilai;
            }
            $out[$uid]['jml']++;
            $out[$uid]['items'][] = ['pekerjaan_id' => $wid, 'mode' => $mode, 'nilai' => $nilai, 'volume' => 0.0];
        }
    }

    return $out;
}

/** Daftar item + nilai upah borongan seorang pekerja (untuk halaman rincian) */
function borongan_of_pekerja(int $userId, int $projectId = 0, bool $hanyaSelisih = false): array
{
    $sql = 'SELECT pp.user_id, pp.upah_borongan, pp.bagian_pct, pp.harga_upah_override,
                   pj.id AS pekerjaan_id, pj.nama AS pekerjaan_nama, pj.status, pj.progress,
                   pj.volume, pj.volume_realisasi, pj.harga_upah, pj.satuan, pj.deadline,
                   pr.nama AS project_nama, pr.kode AS project_kode, pr.id AS project_id
            FROM pekerjaan_pekerja pp
            JOIN pekerjaan pj ON pj.id = pp.pekerjaan_id
            JOIN projects pr ON pr.id = pj.project_id
            WHERE pp.user_id = ?';
    $args = [$userId];
    if ($projectId) {
        $sql .= ' AND pj.project_id = ?';
        $args[] = $projectId;
    }
    $st = db()->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();

    // sertakan item tempat dia melapor walau belum tercatat sebagai penugasan
    $st = db()->prepare(
        'SELECT 0 AS upah_borongan, 0 AS bagian_pct, 0 AS harga_upah_override,
                pj.id AS pekerjaan_id, pj.nama AS pekerjaan_nama, pj.status, pj.progress,
                pj.volume, pj.volume_realisasi, pj.harga_upah, pj.satuan, pj.deadline,
                pr.nama AS project_nama, pr.kode AS project_kode, pr.id AS project_id, lk.user_id
         FROM laporan_kerja lk
         JOIN pekerjaan pj ON pj.id = lk.pekerjaan_id
         JOIN projects pr ON pr.id = pj.project_id
         WHERE lk.user_id = ?' . ($projectId ? ' AND pj.project_id = ?' : '') . '
         GROUP BY pj.id'
    );
    $st->execute($projectId ? [$userId, $projectId] : [$userId]);
    $ada = array_map(fn($r) => (int) $r['pekerjaan_id'], $rows);
    foreach ($st->fetchAll() as $r) {
        if (!in_array((int) $r['pekerjaan_id'], $ada, true)) {
            $rows[] = $r;
        }
    }

    $hasil = [];
    foreach ($rows as $r) {
        $hit = hitung_upah_pekerjaan((int) $r['pekerjaan_id']);
        foreach ($hit['rows'] as $h) {
            if ((int) $h['user_id'] !== $userId) {
                continue;
            }
            $r['nilai'] = $h['nilai'];
            $r['mode'] = $h['mode'];
            $r['rate'] = $h['rate'];
            $r['pct'] = $h['pct'];
            $r['volume_lapor'] = $h['volume_lapor'];
            $r['skema'] = $h['skema'];
            $r['cair'] = $h['dari_laporan'] || ($r['status'] === 'selesai' && $h['nilai'] > 0);
            if ($r['nilai'] <= 0 && $hanyaSelisih) {
                continue;
            }
            $hasil[] = $r;
        }
    }
    return $hasil;
}

/** Ringkasan borongan seorang pekerja: selesai (cair) & berjalan */
function borongan_stats(int $userId, int $projectId = 0): array
{
    $rekap = upah_borongan_per_pekerja(['user_id' => $userId, 'project_id' => $projectId]);
    $r = $rekap[$userId] ?? null;
    if (!$r) {
        return ['selesai' => 0.0, 'berjalan' => 0.0, 'jml' => 0, 'volume' => 0.0];
    }
    return ['selesai' => $r['cair'], 'berjalan' => $r['berjalan'], 'jml' => $r['jml'], 'volume' => $r['volume']];
}

/** Ringkasan seluruh pekerja pada sebuah pekerjaan (untuk tabel) */
function nilai_pekerjaan_summary(array $pj): array
{
    $b = borongan_detail((int) $pj['id']);
    return [
        'volume_efektif' => volume_efektif($pj),
        'nilai_kontrak'  => nilai_kontrak_pekerjaan($pj),
        'nilai_jasa'     => nilai_jasa_pekerjaan($pj),
        'upah_pekerja'   => $b['total'],
        'upah_cair'      => $b['total_cair'],
        'upah_berjalan'  => $b['total_berjalan'],
        'jml_pekerja'    => count($b['rows']),
    ];
}

/**
 * Rekap pengajuan (nilai jasa yang bisa ditagihkan) per project.
 * $f: project_id (0 = semua), pelaksana_id, status
 */
function rekap_pengajuan(array $f = []): array
{
    $rows = fetch_pekerjaan($f);
    $per = [];
    foreach ($rows as $pj) {
        $pid = (int) $pj['project_id'];
        if (!isset($per[$pid])) {
            $per[$pid] = [
                'project_id' => $pid,
                'nama' => $pj['project_nama'],
                'kode' => $pj['project_kode'],
                'pelaksana' => $pj['pelaksana_nama'] ?? '',
                'jml_item' => 0,
                'jml_selesai' => 0,
                'nilai_kontrak' => 0.0,
                'nilai_diajukan' => 0.0,
                'upah_selesai' => 0.0,
                'upah_berjalan' => 0.0,
                'item' => [],
                'tagih' => [
                    'belum' => ['item' => 0, 'nilai' => 0.0],
                    'diajukan' => ['item' => 0, 'nilai' => 0.0],
                    'dibayar' => ['item' => 0, 'nilai' => 0.0],
                ],
                'nilai_diajukan_volume' => 0.0,
                'nilai_sisa_volume' => 0.0,
            ];
        }
        $s = nilai_pekerjaan_summary($pj);
        $selesai = $pj['status'] === 'selesai';
        $per[$pid]['jml_item']++;
        $per[$pid]['jml_selesai'] += $selesai ? 1 : 0;
        $per[$pid]['nilai_kontrak'] += $s['nilai_kontrak'];
        $per[$pid]['nilai_diajukan'] += $s['nilai_jasa'];
        $per[$pid]['upah_selesai'] += $s['upah_cair'];
        $per[$pid]['upah_berjalan'] += $s['upah_berjalan'];

        // Ringkasan penagihan: sudah diajukan (dari pengajuan) & sisa
        $per[$pid]['item'][] = $pj + $s;
    }

    // Status penagihan diambil dari data pengajuan (model sederhana)
    foreach ($per as $pid => &$r) {
        $st = db()->prepare(
            "SELECT peng.status, COALESCE(SUM(i.volume * i.harga_jasa),0) AS nilai, COUNT(DISTINCT peng.id) AS jml
             FROM pengajuan peng JOIN pengajuan_item i ON i.pengajuan_id = peng.id
             WHERE peng.project_id = ? AND peng.perusahaan_id = ?
             GROUP BY peng.status"
        );
        $st->execute([$pid, tenant_id()]);
        foreach ($st->fetchAll() as $row) {
            $kunci = $row['status'] === 'dibayar' ? 'dibayar' : 'diajukan';
            $r['tagih'][$kunci]['nilai'] += (float) $row['nilai'];
            $r['tagih'][$kunci]['item'] += (int) $row['jml'];
            $r['nilai_diajukan_volume'] += (float) $row['nilai'];
        }
        foreach ($r['item'] as $it) {
            $dasar = tagih_dasar($it);
            $sisa = max(0.0, $dasar - (float) ($it['tagih_volume'] ?? 0));
            $r['nilai_sisa_volume'] += $sisa * (float) $it['harga_jasa'];
        }
        $r['tagih']['belum']['nilai'] = $r['nilai_sisa_volume'];
        $r['tagih']['belum']['item'] = count(array_filter($r['item'], fn($it) => tagih_sisa($it) > 0));
    }
    unset($r);

    // upah harian (absensi) per project untuk hitung margin
    foreach ($per as $pid => &$r) {
        $st = db()->prepare('SELECT COALESCE(SUM(hari * upah),0) FROM absensi WHERE project_id = ?');
        $st->execute([$pid]);
        $r['upah_harian'] = (float) $st->fetchColumn();

        // upah harian yang absensinya dikaitkan ke item yang sudah selesai
        $st = db()->prepare(
            "SELECT COALESCE(SUM(a.hari * a.upah),0) FROM absensi a
             JOIN pekerjaan pj ON pj.id = a.pekerjaan_id
             WHERE a.project_id = ? AND pj.status = 'selesai'"
        );
        $st->execute([$pid]);
        $r['upah_harian_selesai'] = (float) $st->fetchColumn();

        $r['margin'] = $r['nilai_diajukan'] - $r['upah_selesai'] - $r['upah_harian_selesai'];
    }
    unset($r);

    uasort($per, fn($a, $b) => $b['nilai_diajukan'] <=> $a['nilai_diajukan']);
    return $per;
}

/** Baris item siap diexport / ditampilkan pada pengajuan satu project */
function pengajuan_item_rows(int $projectId): array
{
    $rows = fetch_pekerjaan(['project_id' => $projectId]);
    $out = [];
    foreach ($rows as $pj) {
        $b = borongan_detail((int) $pj['id']);
        $out[] = $pj + nilai_pekerjaan_summary($pj) + ['pekerja' => $b['rows']];
    }
    return $out;
}

/**
 * Menyimpan penugasan pekerja pada sebuah pekerjaan.
 *
 * Field POST yang dibaca (indeks user_id):
 *   pekerja[]              daftar user_id yang dicentang
 *   borongan[uid]          nominal tetap (Rp) — bila diisi, mengalahkan hitungan per satuan
 *   tarif[uid]             override tarif upah per satuan (kosong = pakai harga dasar item)
 *   bagian[uid]            bagian dalam persen (kosong = dibagi rata antar pekerja per satuan)
 */
function simpan_penugasan(int $pekerjaanId, array $userIds, array $post): void
{
    $validPekerja = array_map(fn($r) => (int) $r['id'], selectable_pekerja());
    $userIds = array_values(array_intersect(array_unique(array_map('intval', $userIds)), $validPekerja));

    $borongan = (array) ($post['borongan'] ?? []);
    $tarif = (array) ($post['tarif'] ?? []);
    $bagian = (array) ($post['bagian'] ?? []);

    $ambil = static function (array $arr, int $uid): string {
        $v = $arr[$uid] ?? $arr[(string) $uid] ?? '';
        return trim((string) $v);
    };

    db()->prepare('DELETE FROM pekerjaan_pekerja WHERE pekerjaan_id = ?')->execute([$pekerjaanId]);
    $ins = db()->prepare(
        'INSERT OR IGNORE INTO pekerjaan_pekerja (pekerjaan_id, user_id, upah_borongan, harga_upah_override, bagian_pct)
         VALUES (?,?,?,?,?)'
    );
    foreach ($userIds as $uid) {
        $nominal = max(0.0, parse_money($ambil($borongan, $uid)));
        $override = max(0.0, parse_money($ambil($tarif, $uid)));
        $pct = (float) str_replace(',', '.', $ambil($bagian, $uid));
        if ($pct < 0 || $pct > 100) {
            $pct = max(0.0, min(100.0, $pct));
        }
        $ins->execute([$pekerjaanId, $uid, $nominal, $override, $pct]);
    }
}

/* ==========================================================================
   JADWAL HARIAN & HASIL KERJA HARIAN
   --------------------------------------------------------------------------
   - jadwal        : rencana kerja admin/pelaksana (pekerja → project → item).
   - laporan_kerja : apa yang benar-benar dikerjakan pekerja (volume).
       * pekerja BORONGAN → volume × upah_satuan langsung jadi upahnya
       * pekerja HARIAN   → volumenya tetap tercatat sebagai dasar tagihan
                            ke perusahaan, upahnya dari absensi (auto dibuat)
     Volume laporan otomatis menjadi `pekerjaan.volume_realisasi`.
   ========================================================================== */

function skema_label(string $skema): string
{
    return $skema === 'borongan' ? 'Borongan' : 'Harian';
}

function skema_valid(string $skema): string
{
    return in_array($skema, ['harian', 'borongan'], true) ? $skema : 'harian';
}

/** Nomor WA internasional dari nomor lokal (0812... → 62812...) */
function wa_number(string $telepon): string
{
    $d = preg_replace('/\D+/', '', $telepon) ?? '';
    if ($d === '') {
        return '';
    }
    $d = ltrim($d, '0');
    if (str_starts_with($d, '62')) {
        return $d;
    }
    return '62' . $d;
}

/** Tautan wa.me dengan pesan siap kirim */
function wa_link(string $nomor, string $pesan, string $grup = ''): string
{
    $base = $nomor !== '' ? 'https://wa.me/' . wa_number($nomor) : 'https://wa.me/';
    $q = '?text=' . rawurlencode($pesan);
    if ($grup !== '') {
        $q .= '&type=' . rawurlencode($grup);
    }
    return $base . $q;
}

/* ---------- Jadwal ---------- */

function jadwal_filters(array $f): array
{
    $w = [];
    $a = [];
    $u = current_user();

    $w[] = tenant_where('j', $a);

    if ($u && $u['role'] === 'pekerja' && empty($f['semua_orang'])) {
        $w[] = 'j.user_id = ?';
        $a[] = (int) $u['id'];
    } elseif (!empty($f['user_id'])) {
        $w[] = 'j.user_id = ?';
        $a[] = (int) $f['user_id'];
    }
    if (!empty($f['tanggal'])) {
        $w[] = 'j.tanggal = ?';
        $a[] = $f['tanggal'];
    }
    if (!empty($f['dari'])) {
        $w[] = 'j.tanggal >= ?';
        $a[] = $f['dari'];
    }
    if (!empty($f['sampai'])) {
        $w[] = 'j.tanggal <= ?';
        $a[] = $f['sampai'];
    }
    if (!empty($f['project_id'])) {
        $w[] = 'j.project_id = ?';
        $a[] = (int) $f['project_id'];
    }
    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $a];
}

function fetch_jadwal(array $f, int $limit = 500): array
{
    [$where, $args] = jadwal_filters($f);
    $sql = "SELECT j.*, u.nama AS pekerja_nama, u.jabatan AS pekerja_jabatan, u.role AS pekerja_role,
                   u.telepon AS pekerja_telepon, u.skema AS pekerja_skema, u.upah_harian,
                   pr.nama AS project_nama, pr.kode AS project_kode,
                   pj.nama AS pekerjaan_nama, pj.satuan AS pekerjaan_satuan, pj.harga_upah AS pekerjaan_upah,
                   l.nama AS lokasi_nama,
                   (SELECT COALESCE(SUM(x.volume),0) FROM laporan_kerja x
                     WHERE x.jadwal_id = j.id OR (x.user_id = j.user_id AND x.tanggal = j.tanggal AND x.project_id = j.project_id)
                   ) AS volume_lapor,
                   (SELECT COUNT(*) FROM laporan_kerja x
                     WHERE x.jadwal_id = j.id OR (x.user_id = j.user_id AND x.tanggal = j.tanggal AND x.project_id = j.project_id)
                   ) AS jml_laporan
            FROM jadwal j
            JOIN users u ON u.id = j.user_id
            JOIN projects pr ON pr.id = j.project_id
            LEFT JOIN pekerjaan pj ON pj.id = j.pekerjaan_id
            LEFT JOIN lokasi l ON l.id = j.lokasi_id
            $where
            ORDER BY j.tanggal DESC, u.nama
            LIMIT " . (int) $limit;
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function get_jadwal(int $id): ?array
{
    $rows = fetch_jadwal(['id_tidak_dipakai' => 0]);
    foreach ($rows as $r) {
        if ((int) $r['id'] === $id) {
            return $r;
        }
    }
    // query langsung (tanpa filter peran)
    $st = db()->prepare(
        'SELECT j.*, u.nama AS pekerja_nama, u.telepon AS pekerja_telepon, u.skema AS pekerja_skema,
                u.upah_harian, pr.nama AS project_nama, pr.kode AS project_kode,
                pj.nama AS pekerjaan_nama, pj.satuan AS pekerjaan_satuan, pj.harga_upah AS pekerjaan_upah
         FROM jadwal j
         JOIN users u ON u.id = j.user_id
         JOIN projects pr ON pr.id = j.project_id
         LEFT JOIN pekerjaan pj ON pj.id = j.pekerjaan_id
         WHERE j.id = ? AND j.perusahaan_id = ?'
    );
    $st->execute([$id, tenant_id()]);
    return $st->fetch() ?: null;
}

function jadwal_stats(array $f): array
{
    [$where, $args] = jadwal_filters($f);
    $st = db()->prepare(
        "SELECT COUNT(*) AS baris,
                COUNT(DISTINCT j.user_id) AS orang,
                COUNT(DISTINCT j.project_id) AS project,
                SUM(CASE WHEN EXISTS (
                    SELECT 1 FROM laporan_kerja x
                     WHERE x.user_id = j.user_id AND x.tanggal = j.tanggal AND x.project_id = j.project_id
                ) THEN 1 ELSE 0 END) AS sudah_lapor
         FROM jadwal j $where"
    );
    $st->execute($args);
    $r = $st->fetch() ?: [];
    return [
        'baris' => (int) ($r['baris'] ?? 0),
        'orang' => (int) ($r['orang'] ?? 0),
        'project' => (int) ($r['project'] ?? 0),
        'sudah_lapor' => (int) ($r['sudah_lapor'] ?? 0),
    ];
}

/* ---------- Laporan hasil kerja ---------- */

function laporan_filters(array $f): array
{
    $w = [];
    $a = [];
    $u = current_user();

    $w[] = tenant_where('lk', $a);

    if ($u && $u['role'] === 'pekerja' && empty($f['semua_orang'])) {
        $w[] = 'lk.user_id = ?';
        $a[] = (int) $u['id'];
    } elseif (!empty($f['user_id'])) {
        $w[] = 'lk.user_id = ?';
        $a[] = (int) $f['user_id'];
    }
    if (!empty($f['pekerjaan_id'])) {
        $w[] = 'lk.pekerjaan_id = ?';
        $a[] = (int) $f['pekerjaan_id'];
    }
    if (!empty($f['project_id'])) {
        $w[] = 'lk.project_id = ?';
        $a[] = (int) $f['project_id'];
    }
    if (!empty($f['tanggal'])) {
        $w[] = 'lk.tanggal = ?';
        $a[] = $f['tanggal'];
    }
    if (!empty($f['dari'])) {
        $w[] = 'lk.tanggal >= ?';
        $a[] = $f['dari'];
    }
    if (!empty($f['sampai'])) {
        $w[] = 'lk.tanggal <= ?';
        $a[] = $f['sampai'];
    }
    if (!empty($f['skema'])) {
        $w[] = 'us.skema = ?';
        $a[] = $f['skema'];
    }
    if (!empty($f['q'])) {
        $like = '%' . $f['q'] . '%';
        $w[] = '(pj.nama LIKE ? OR us.nama LIKE ? OR lk.keterangan LIKE ?)';
        array_push($a, $like, $like, $like);
    }
    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $a];
}

function fetch_laporan(array $f, int $limit = 500): array
{
    [$where, $args] = laporan_filters($f);
    $sql = "SELECT lk.*, us.nama AS pekerja_nama, us.jabatan AS pekerja_jabatan, us.skema,
                   us.upah_harian, us.role AS pekerja_role,
                   pj.nama AS pekerjaan_nama, pj.satuan, pj.volume AS volume_kontrak, pj.status AS pekerjaan_status,
                   pj.harga_upah AS pekerjaan_upah,
                   pr.nama AS project_nama, pr.kode AS project_kode,
                   cb.nama AS pencatat_nama,
                   (lk.volume * lk.upah_satuan) AS nilai
            FROM laporan_kerja lk
            JOIN users us ON us.id = lk.user_id
            JOIN pekerjaan pj ON pj.id = lk.pekerjaan_id
            JOIN projects pr ON pr.id = lk.project_id
            LEFT JOIN users cb ON cb.id = lk.created_by
            $where
            ORDER BY lk.tanggal DESC, us.nama, pj.nama
            LIMIT " . (int) $limit;
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function laporan_summary(array $f): array
{
    [$where, $args] = laporan_filters($f);
    $sql = "SELECT COUNT(*) AS baris,
                   COALESCE(SUM(lk.volume), 0) AS volume,
                   COALESCE(SUM(CASE WHEN us.skema = 'borongan' THEN lk.volume * lk.upah_satuan ELSE 0 END), 0) AS upah_borongan,
                   COUNT(DISTINCT lk.user_id) AS orang,
                   COUNT(DISTINCT lk.pekerjaan_id) AS item
            FROM laporan_kerja lk
            JOIN users us ON us.id = lk.user_id
            JOIN pekerjaan pj ON pj.id = lk.pekerjaan_id
            JOIN projects pr ON pr.id = lk.project_id
            $where";
    $st = db()->prepare($sql);
    $st->execute($args);
    $r = $st->fetch() ?: [];
    return [
        'baris' => (int) ($r['baris'] ?? 0),
        'volume' => (float) ($r['volume'] ?? 0),
        'upah_borongan' => (float) ($r['upah_borongan'] ?? 0),
        'orang' => (int) ($r['orang'] ?? 0),
        'item' => (int) ($r['item'] ?? 0),
    ];
}

function get_laporan(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM laporan_kerja WHERE id = ? AND perusahaan_id = ?');
    $st->execute([$id, tenant_id()]);
    return $st->fetch() ?: null;
}

/** Total volume & nilai laporan per pekerja pada sebuah pekerjaan */
function laporan_of_pekerjaan(int $pekerjaanId): array
{
    $st = db()->prepare(
        'SELECT lk.user_id, us.nama, us.skema, COUNT(*) AS jml,
                COALESCE(SUM(lk.volume),0) AS volume,
                COALESCE(SUM(lk.volume * lk.upah_satuan),0) AS nilai
         FROM laporan_kerja lk JOIN users us ON us.id = lk.user_id
         WHERE lk.pekerjaan_id = ? AND lk.perusahaan_id = ?
         GROUP BY lk.user_id ORDER BY us.nama'
    );
    $st->execute([$pekerjaanId, tenant_id()]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int) $r['user_id']] = $r;
    }
    return $out;
}

/** Volume laporan seorang pekerja pada satu pekerjaan */
function volume_lapor(int $pekerjaanId, int $userId): float
{
    $st = db()->prepare('SELECT COALESCE(SUM(volume),0) FROM laporan_kerja WHERE pekerjaan_id = ? AND user_id = ? AND perusahaan_id = ?');
    $st->execute([$pekerjaanId, $userId, tenant_id()]);
    return (float) $st->fetchColumn();
}

/**
 * Samakan `pekerjaan.volume_realisasi` dengan total volume laporan harian.
 *
 * - Ada laporan  -> volume_realisasi = Σ volume laporan (ditandai volume_auto = 1)
 * - Tidak ada laporan & sebelumnya otomatis -> dikosongkan lagi (0)
 * - Tidak ada laporan & isian manual admin   -> dibiarkan apa adanya
 */
function sync_volume_realisasi(int $pekerjaanId): float
{
    $st = db()->prepare('SELECT COALESCE(SUM(volume),0) AS vol, MAX(tanggal) AS tgl, COUNT(*) AS n
                         FROM laporan_kerja WHERE pekerjaan_id = ?');
    $st->execute([$pekerjaanId]);
    $r = $st->fetch() ?: ['vol' => 0, 'tgl' => null, 'n' => 0];

    if ((int) ($r['n'] ?? 0) > 0) {
        $vol = (float) $r['vol'];
        db()->prepare('UPDATE pekerjaan SET volume_realisasi = ?, realisasi_tanggal = ?, volume_auto = 1 WHERE id = ?')
            ->execute([$vol, (string) ($r['tgl'] ?: date('Y-m-d')), $pekerjaanId]);
        return $vol;
    }

    // Tidak ada laporan lagi: kosongkan hanya kalau nilainya memang berasal dari laporan
    $st = db()->prepare('SELECT volume_auto FROM pekerjaan WHERE id = ?');
    $st->execute([$pekerjaanId]);
    if ((int) $st->fetchColumn() === 1) {
        db()->prepare("UPDATE pekerjaan SET volume_realisasi = 0, realisasi_tanggal = '', volume_auto = 0 WHERE id = ?")
            ->execute([$pekerjaanId]);
    }
    return 0.0;
}

/** Penanda keterangan absensi yang dibuat otomatis dari laporan harian */
const ABSENSI_AUTO_MARK = 'Otomatis dari laporan hasil kerja harian';

/** Hapus absensi otomatis (hanya yang bertanda & cocok item/tanggalnya) */
function auto_absensi_hapus(int $userId, string $tanggal, int $pekerjaanId): bool
{
    $st = db()->prepare('DELETE FROM absensi WHERE user_id = ? AND tanggal = ? AND pekerjaan_id = ? AND keterangan = ?');
    $st->execute([$userId, $tanggal, $pekerjaanId, ABSENSI_AUTO_MARK]);
    return $st->rowCount() > 0;
}

/** Absensi otomatis untuk pekerja harian yang melapor kerja */
function auto_absensi(int $userId, string $tanggal, int $projectId, int $pekerjaanId, int $olehUser): bool
{
    $st = db()->prepare('SELECT skema, upah_harian FROM users WHERE id = ?');
    $st->execute([$userId]);
    $u = $st->fetch();
    if (!$u || $u['skema'] !== 'harian') {
        return false;
    }
    $ins = db()->prepare(
        'INSERT OR IGNORE INTO absensi (user_id, project_id, pekerjaan_id, tanggal, hari, upah, keterangan, created_by)
         VALUES (?,?,?,?,1,?,?,?)'
    );
    $ins->execute([$userId, $projectId, $pekerjaanId, $tanggal, (float) $u['upah_harian'],
        ABSENSI_AUTO_MARK, $olehUser]);
    return $ins->rowCount() > 0;
}

/**
 * Tarif upah per satuan untuk seorang pekerja pada sebuah pekerjaan.
 *
 * Urutan prioritas:
 *   1. tarif khusus per pekerja pada pekerjaan itu (pekerjaan_pekerja.harga_upah_override)
 *   2. tarif khusus per pekerja dari MASTER harga satuan item tersebut
 *      (otomatis tersinkron: ubah di master -> ikut terpakai di semua pekerjaan)
 *   3. upah dasar item (pekerjaan.harga_upah)
 */
function tarif_upah(array $pekerjaan, int $userId): float
{
    $st = db()->prepare('SELECT harga_upah_override FROM pekerjaan_pekerja WHERE pekerjaan_id = ? AND user_id = ?');
    $st->execute([(int) $pekerjaan['id'], $userId]);
    $override = (float) $st->fetchColumn();
    if ($override > 0) {
        return $override;
    }

    $masterId = (int) ($pekerjaan['harga_satuan_id'] ?? 0);
    if ($masterId > 0) {
        $khusus = tarif_khusus_master($masterId, $userId);
        if ($khusus > 0) {
            return $khusus;
        }
    }

    return (float) ($pekerjaan['harga_upah'] ?? 0);
}

/** Tarif khusus seorang pekerja pada satu item master (0 = tidak ada, pakai upah dasar) */
function tarif_khusus_master(int $hargaSatuanId, int $userId): float
{
    static $cache = [];
    $kunci = $hargaSatuanId . '-' . $userId;
    if (array_key_exists($kunci, $cache)) {
        return $cache[$kunci];
    }
    $st = db()->prepare('SELECT harga_upah FROM harga_satuan_pekerja WHERE harga_satuan_id = ? AND user_id = ? AND perusahaan_id = ?');
    $st->execute([$hargaSatuanId, $userId, tenant_id()]);
    return $cache[$kunci] = (float) $st->fetchColumn();
}

/** Semua tarif khusus sebuah item master: [user_id => harga] */
function tarif_khusus_daftar(int $hargaSatuanId): array
{
    $st = db()->prepare('SELECT user_id, harga_upah FROM harga_satuan_pekerja WHERE harga_satuan_id = ? AND perusahaan_id = ?');
    $st->execute([$hargaSatuanId, tenant_id()]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int) $r['user_id']] = (float) $r['harga_upah'];
    }
    return $out;
}

/** Menyimpan tarif khusus per pekerja untuk sebuah item master (0 = hapus/ikut dasar) */
function simpan_tarif_khusus(int $hargaSatuanId, array $tarifPerUser): void
{
    $valid = [];
    foreach (all_users() as $p) {
        if ($p['role'] !== 'admin') {
            $valid[(int) $p['id']] = true;
        }
    }

    db()->prepare('DELETE FROM harga_satuan_pekerja WHERE harga_satuan_id = ? AND perusahaan_id = ?')
        ->execute([$hargaSatuanId, tenant_id()]);
    $ins = db()->prepare('INSERT OR IGNORE INTO harga_satuan_pekerja (harga_satuan_id, user_id, harga_upah, perusahaan_id) VALUES (?,?,?,?)');
    foreach ($tarifPerUser as $uid => $nominal) {
        $uid = (int) $uid;
        $nominal = parse_money((string) $nominal);
        if ($uid <= 0 || $nominal <= 0 || !isset($valid[$uid])) {
            continue;
        }
        $ins->execute([$hargaSatuanId, $uid, $nominal, tenant_id()]);
    }
}

/** Pastikan pekerja punya baris penugasan pada pekerjaan (untuk perhitungan upah) */
function pastikan_penugasan(int $pekerjaanId, int $userId): void
{
    $st = db()->prepare('SELECT 1 FROM pekerjaan_pekerja WHERE pekerjaan_id = ? AND user_id = ?');
    $st->execute([$pekerjaanId, $userId]);
    if (!$st->fetchColumn()) {
        db()->prepare('INSERT OR IGNORE INTO pekerjaan_pekerja (pekerjaan_id, user_id) VALUES (?,?)')
            ->execute([$pekerjaanId, $userId]);
    }
}

/* ---------- Pesan WhatsApp ---------- */

function jadwal_pesan(array $row): string
{
    $baris = [
        '*JADWAL KERJA HARI INI*',
        'Tanggal: ' . tgl($row['tanggal']),
        'Nama: ' . $row['pekerja_nama'],
        'Project: ' . $row['project_kode'] . ' — ' . $row['project_nama'],
    ];
    if (!empty($row['pekerjaan_nama'])) {
        $baris[] = 'Pekerjaan: ' . $row['pekerjaan_nama'];
    }
    if (!empty($row['lokasi_nama'])) {
        $baris[] = 'Lokasi: ' . $row['lokasi_nama'];
    }
    $baris[] = 'Skema upah: ' . skema_label((string) $row['pekerja_skema'])
        . ((string) $row['pekerja_skema'] === 'borongan' ? ' (upah dari volume yang dilaporkan)' : ' (upah harian ' . rupiah($row['upah_harian']) . ')');
    if (!empty($row['catatan'])) {
        $baris[] = 'Catatan: ' . $row['catatan'];
    }
    $baris[] = '';
    $baris[] = 'Mohon setelah selesai bekerja, input di aplikasi: pekerjaan yang dikerjakan + volume hasilnya.';
    return implode("\n", $baris);
}

function jadwal_rekap_pesan(string $tanggal, array $rows): string
{
    $perProject = [];
    foreach ($rows as $r) {
        $perProject[$r['project_kode'] . ' — ' . $r['project_nama']][] = $r;
    }
    $out = ['*JADWAL KERJA TIM*', tgl($tanggal), ''];
    $no = 1;
    foreach ($perProject as $label => $items) {
        $out[] = '*' . $label . '*';
        foreach ($items as $r) {
            $out[] = $no . '. ' . $r['pekerja_nama']
                . ($r['pekerjaan_nama'] ? ' — ' . $r['pekerjaan_nama'] : '')
                . ' (' . strtolower(skema_label((string) $r['pekerja_skema'])) . ')';
            $no++;
        }
        $out[] = '';
    }
    $out[] = 'Mohon input hasil kerja (pekerjaan + volume) di aplikasi ya. Terima kasih.';
    return implode("\n", $out);
}

/**
 * Memastikan ada item pekerjaan di project untuk sebuah item master harga satuan.
 *
 * Dipakai saat pekerja melaporkan hasil kerja atas item yang belum ada di project
 * (mis. hari itu ternyata juga memasang unit / terminasi / panel): itemnya dibuat
 * otomatis memakai harga dari master, jadi laporannya tetap tercatat & terhitung.
 *
 * Kalau di project sudah ada item dengan nama sama, item itu yang dipakai.
 */
function pastikan_pekerjaan_dari_master(int $projectId, int $masterId, int $olehUser): ?array
{
    $hs = get_harga_satuan($masterId);
    $project = get_project($projectId);
    if (!$hs || !$project) {
        return null;
    }

    // sudah ada item dengan nama sama di project ini?
    $st = db()->prepare('SELECT * FROM pekerjaan WHERE project_id = ? AND lower(nama) = lower(?) LIMIT 1');
    $st->execute([$projectId, (string) $hs['nama']]);
    $ada = $st->fetch();
    if ($ada) {
        return $ada;
    }

    db()->prepare(
        'INSERT INTO pekerjaan (project_id, lokasi_id, nama, kategori, volume, satuan, status, progress,
                mulai, deadline, prioritas, pelaksana_id, keterangan, harga_satuan_id, harga_jasa, harga_upah)
         VALUES (?,NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $projectId,
        (string) $hs['nama'],
        (string) $hs['kategori'],
        0,
        (string) $hs['satuan'],
        'belum',
        0,
        date('Y-m-d'),
        '',
        'normal',
        (int) ($project['pelaksana_id'] ?? 0) ?: null,
        'Dibuat otomatis dari laporan hasil kerja harian.',
        (int) $hs['id'],
        (float) $hs['harga_jasa'],
        (float) $hs['harga_upah'],
    ]);
    $wid = (int) db()->lastInsertId();
    db()->prepare('INSERT INTO progress_log (pekerjaan_id, user_id, tanggal, progress, catatan) VALUES (?,?,?,?,?)')
        ->execute([$wid, $olehUser, date('Y-m-d'), 0, 'Item dibuat otomatis dari laporan hasil kerja harian.']);

    $st = db()->prepare('SELECT * FROM pekerjaan WHERE id = ?');
    $st->execute([$wid]);
    return $st->fetch() ?: null;
}

/** Item project + item master yang belum ada di project (untuk pilihan di form laporan) */
function opsi_pekerjaan_laporan(int $projectId, int $userId): array
{
    $items = fetch_pekerjaan(['project_id' => $projectId, 'semua_item' => true]);
    $ada = [];
    foreach ($items as $pj) {
        $ada[mb_strtolower((string) $pj['nama'])] = true;
    }

    $master = [];
    foreach (harga_satuan_pilihan() as $hs) {
        if (isset($ada[mb_strtolower((string) $hs['nama'])])) {
            continue;
        }
        $master[] = $hs;
    }

    return ['items' => $items, 'master' => $master];
}

/* ==========================================================================
   PENGAJUAN TAGIHAN (SEDERHANA)
   --------------------------------------------------------------------------
   Satu pengajuan = satu project, berisi beberapa sub pekerjaan dengan volume
   yang diajukan. Volume boleh sebagian: sisa = dasar − tagih_volume, sehingga
   pengajuan berikutnya tinggal mengisi sisanya sampai kontrak habis.
   ========================================================================== */

/** Volume yang jadi dasar tagihan: volume akhir bila ada, kalau tidak volume kontrak */
function tagih_dasar(array $pj): float
{
    $real = (float) ($pj['volume_realisasi'] ?? 0);
    if ($real > 0) {
        return $real;
    }
    return (float) ($pj['volume'] ?? 0);
}

/** Sisa volume yang belum diajukan */
function tagih_sisa(array $pj): float
{
    return max(0.0, tagih_dasar($pj) - (float) ($pj['tagih_volume'] ?? 0));
}

/** Sumber dasar tagihan dalam teks: 'volume akhir' atau 'volume kontrak' */
function tagih_dasar_dari(array $pj): string
{
    return (float) ($pj['volume_realisasi'] ?? 0) > 0 ? 'volume akhir' : 'volume kontrak';
}

/** Sub pekerjaan sebuah project + info tagihan (untuk form pengajuan) */
function item_untuk_pengajuan(int $projectId): array
{
    $rows = fetch_pekerjaan(['project_id' => $projectId, 'semua_item' => true]);
    $out = [];
    foreach ($rows as $pj) {
        $pj['dasar'] = tagih_dasar($pj);
        $pj['sisa'] = tagih_sisa($pj);
        $pj['nilai_sisa'] = $pj['sisa'] * (float) $pj['harga_jasa'];
        $pj['dasar_dari'] = tagih_dasar_dari($pj);
        $out[] = $pj;
    }
    usort($out, fn($a, $b) => strcmp($a['nama'], $b['nama']));
    return $out;
}

/** Hitung ulang total volume yang sudah diajukan pada sebuah pekerjaan */
function tagih_sync_volume(int $pekerjaanId): float
{
    $st = db()->prepare('SELECT COALESCE(SUM(volume),0) FROM pengajuan_item WHERE pekerjaan_id = ? AND perusahaan_id = ?');
    $st->execute([$pekerjaanId, tenant_id()]);
    $vol = (float) $st->fetchColumn();
    db()->prepare('UPDATE pekerjaan SET tagih_volume = ? WHERE id = ? AND perusahaan_id = ?')
        ->execute([$vol, $pekerjaanId, tenant_id()]);
    return $vol;
}

/** Daftar pengajuan (dengan nilai total & jumlah item) */
function fetch_pengajuan(array $f = []): array
{
    $a = [];
    $w = [tenant_where('peng', $a)];
    if (!empty($f['project_id'])) {
        $w[] = 'peng.project_id = ?';
        $a[] = (int) $f['project_id'];
    }
    if (!empty($f['status'])) {
        $w[] = 'peng.status = ?';
        $a[] = (string) $f['status'];
    }
    if (!empty($f['dari'])) {
        $w[] = 'peng.tanggal >= ?';
        $a[] = (string) $f['dari'];
    }
    if (!empty($f['sampai'])) {
        $w[] = 'peng.tanggal <= ?';
        $a[] = (string) $f['sampai'];
    }
    $sql = 'SELECT peng.*, pr.nama AS project_nama, pr.kode AS project_kode,
                   (SELECT COUNT(*) FROM pengajuan_item i WHERE i.pengajuan_id = peng.id) AS jml_item,
                   (SELECT COALESCE(SUM(i.volume * i.harga_jasa),0) FROM pengajuan_item i WHERE i.pengajuan_id = peng.id) AS nilai,
                   (SELECT COALESCE(SUM(i.volume),0) FROM pengajuan_item i WHERE i.pengajuan_id = peng.id) AS volume
            FROM pengajuan peng
            JOIN projects pr ON pr.id = peng.project_id
            WHERE ' . implode(' AND ', $w) . '
            ORDER BY peng.tanggal DESC, peng.id DESC';
    $st = db()->prepare($sql);
    $st->execute($a);
    return $st->fetchAll();
}

function get_pengajuan(int $id): ?array
{
    $st = db()->prepare(
        'SELECT peng.*, pr.nama AS project_nama, pr.kode AS project_kode, pr.pelaksana_id AS project_pelaksana,
                pr.mulai AS project_mulai, pr.target_selesai AS project_target, u.nama AS pembuat_nama
         FROM pengajuan peng
         JOIN projects pr ON pr.id = peng.project_id
         LEFT JOIN users u ON u.id = peng.created_by
         WHERE peng.id = ? AND peng.perusahaan_id = ?'
    );
    $st->execute([$id, tenant_id()]);
    return $st->fetch() ?: null;
}

/** Item di dalam sebuah pengajuan */
function item_pengajuan(int $pengajuanId): array
{
    $st = db()->prepare(
        'SELECT i.*, pj.satuan AS satuan_pekerjaan
         FROM pengajuan_item i
         LEFT JOIN pekerjaan pj ON pj.id = i.pekerjaan_id
         WHERE i.pengajuan_id = ? AND i.perusahaan_id = ?
         ORDER BY i.nama_item'
    );
    $st->execute([$pengajuanId, tenant_id()]);
    return $st->fetchAll();
}

/** Ringkasan tagihan per project: sudah diajukan (per status) & masih sisa */
function ringkasan_tagihan_project(int $projectId): array
{
    $items = item_untuk_pengajuan($projectId);
    $out = [
        'item' => count($items),
        'nilai_kontrak' => 0.0,
        'nilai_dasar' => 0.0,
        'nilai_diajukan' => 0.0,
        'nilai_sisa' => 0.0,
        'nilai_dibayar' => 0.0,
        'nilai_menunggu' => 0.0,
    ];
    foreach ($items as $pj) {
        $out['nilai_kontrak'] += (float) $pj['volume'] * (float) $pj['harga_jasa'];
        $out['nilai_dasar'] += $pj['dasar'] * (float) $pj['harga_jasa'];
        $out['nilai_diajukan'] += (float) $pj['tagih_volume'] * (float) $pj['harga_jasa'];
        $out['nilai_sisa'] += $pj['nilai_sisa'];
    }
    $st = db()->prepare(
        "SELECT peng.status, COALESCE(SUM(i.volume * i.harga_jasa),0) AS nilai
         FROM pengajuan peng JOIN pengajuan_item i ON i.pengajuan_id = peng.id
         WHERE peng.project_id = ? AND peng.perusahaan_id = ?
         GROUP BY peng.status"
    );
    $st->execute([$projectId, tenant_id()]);
    foreach ($st->fetchAll() as $r) {
        if ($r['status'] === 'dibayar') {
            $out['nilai_dibayar'] += (float) $r['nilai'];
        } else {
            $out['nilai_menunggu'] += (float) $r['nilai'];
        }
    }
    return $out;
}

/** Daftar project yang punya pengajuan (untuk export per sheet) */
function project_berpengajuan(): array
{
    $st = db()->prepare(
        'SELECT DISTINCT pr.* FROM pengajuan peng JOIN projects pr ON pr.id = peng.project_id
         WHERE peng.perusahaan_id = ? ORDER BY pr.nama'
    );
    $st->execute([tenant_id()]);
    return $st->fetchAll();
}

/* ==========================================================================
   GAJI, KASBON & TUTUP BUKU
   --------------------------------------------------------------------------
   - Periode gaji mengikuti "tanggal tutup buku" perusahaan (mis. 25):
       26 <bulan lalu>  s/d  25 <bulan ini>
   - Gaji seharusnya = (hari kerja harian × tarif) + upah borongan (volume × tarif)
     — memakai mesin hitung upah yang sudah ada agar konsisten dengan rekap upah.
   - Kasbon dipotong dari gaji: kasbon yang belum dipotong & tanggalnya <= periode_sampai.
   - Gaji diterima = gaji seharusnya − kasbon.
   ========================================================================== */

/** Tanggal tutup buku perusahaan aktif (0 = tidak dipakai) */
function tutup_buku_tgl(): int
{
    $p = tenant();
    $tgl = (int) ($p['tutup_buku_tgl'] ?? 0);
    return ($tgl >= 1 && $tgl <= 28) ? $tgl : 0;
}

/**
 * Periode gaji berdasarkan tanggal tutup buku.
 *
 * @param string $acuan  tanggal acuan (default hari ini)
 * @param int    $geser  -1 = periode sebelumnya
 * @return array ['dari' => 'YYYY-MM-DD', 'sampai' => 'YYYY-MM-DD', 'label' => string, 'tutup' => int]
 */
function periode_gaji(string $acuan = '', int $geser = 0): array
{
    $acuan = valid_tanggal($acuan) ?: date('Y-m-d');
    $tutup = tutup_buku_tgl();
    $ts = strtotime($acuan);

    if ($tutup === 0) {
        // Tanpa tutup buku: periode = bulan kalender penuh
        $mulai = date('Y-m-01', strtotime(($geser >= 0 ? '+' : '') . $geser . ' month', $ts));
        $sampai = date('Y-m-t', strtotime($mulai));
        return [
            'dari' => $mulai,
            'sampai' => $sampai,
            'tutup' => 0,
            'label' => tgl($mulai) . ' – ' . tgl($sampai),
        ];
    }

    if ((int) date('j', $ts) > $tutup) {
        $sampai = date('Y-m-' . str_pad((string) $tutup, 2, '0', STR_PAD_LEFT), strtotime('+1 month', $ts));
    } else {
        $sampai = date('Y-m-' . str_pad((string) $tutup, 2, '0', STR_PAD_LEFT), $ts);
    }
    $dari = date('Y-m-d', strtotime($sampai . ' -1 month +1 day'));

    if ($geser !== 0) {
        // geser negatif = periode sebelumnya (jangan dibalik tandanya!)
        $sampai = date('Y-m-d', strtotime($sampai . ' ' . $geser . ' month'));
        $dari = date('Y-m-d', strtotime($sampai . ' -1 month +1 day'));
    }

    return [
        'dari' => $dari,
        'sampai' => $sampai,
        'tutup' => $tutup,
        'label' => tgl($dari) . ' – ' . tgl($sampai) . ' (tutup buku tgl ' . $tutup . ')',
    ];
}

/** Kasbon yang belum dipotong dari gaji seorang pekerja */
function kasbon_aktif(int $userId, string $sampai = ''): array
{
    $sql = 'SELECT * FROM kasbon WHERE user_id = ? AND perusahaan_id = ? AND penggajian_id IS NULL';
    $args = [$userId, tenant_id()];
    if ($sampai !== '') {
        $sql .= ' AND tanggal <= ?';
        $args[] = $sampai;
    }
    $sql .= ' ORDER BY tanggal, id';
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function kasbon_total_aktif(int $userId, string $sampai = ''): float
{
    $total = 0.0;
    foreach (kasbon_aktif($userId, $sampai) as $k) {
        $total += (float) $k['nominal'];
    }
    return $total;
}

/**
 * Hitung gaji seharusnya + kasbon + gaji diterima seorang pekerja pada sebuah periode.
 *
 * @return array [
 *   'hari' => float (hari kerja yang digaji),
 *   'upah_harian' => float,   'rincian_harian' => array,
 *   'upah_borongan' => float, 'rincian_borongan' => array,
 *   'total_gaji' => float, 'kasbon' => array, 'total_kasbon' => float, 'diterima' => float,
 *   'skema' => string, 'sudah' => ?array (penggajian yang sudah ada untuk periode itu)
 * ]
 */
function hitung_gaji(int $userId, string $dari, string $sampai): array
{
    $st = db()->prepare('SELECT * FROM users WHERE id = ? AND perusahaan_id = ?');
    $st->execute([$userId, tenant_id()]);
    $orang = $st->fetch() ?: [];

    // 1. Hari kerja harian (dari absensi) — hanya untuk skema harian
    $rincianHarian = [];
    if (($orang['skema'] ?? 'harian') === 'harian') {
        $st = db()->prepare(
            'SELECT a.tanggal, a.hari, a.upah, pr.nama AS project_nama
             FROM absensi a LEFT JOIN projects pr ON pr.id = a.project_id
             WHERE a.user_id = ? AND a.perusahaan_id = ? AND a.tanggal >= ? AND a.tanggal <= ?
             ORDER BY a.tanggal'
        );
        $st->execute([$userId, tenant_id(), $dari, $sampai]);
        foreach ($st->fetchAll() as $r) {
            $rincianHarian[] = $r;
        }
    }
    $hari = array_sum(array_map(fn($r) => (float) $r['hari'], $rincianHarian));
    $upahHarian = array_sum(array_map(fn($r) => (float) $r['hari'] * (float) $r['upah'], $rincianHarian));

    // 2. Upah borongan (dari laporan hasil kerja) — hanya untuk skema borongan
    $rincianBorongan = [];
    $upahBorongan = 0.0;
    if (($orang['skema'] ?? '') === 'borongan') {
        $st = db()->prepare(
            'SELECT lk.tanggal, lk.volume, lk.upah_satuan, pj.nama AS pekerjaan_nama, pj.satuan
             FROM laporan_kerja lk JOIN pekerjaan pj ON pj.id = lk.pekerjaan_id
             WHERE lk.user_id = ? AND lk.perusahaan_id = ? AND lk.tanggal >= ? AND lk.tanggal <= ?
             ORDER BY lk.tanggal, pj.nama'
        );
        $st->execute([$userId, tenant_id(), $dari, $sampai]);
        foreach ($st->fetchAll() as $r) {
            $r['nilai'] = (float) $r['volume'] * (float) $r['upah_satuan'];
            $upahBorongan += $r['nilai'];
            $rincianBorongan[] = $r;
        }
    }

    $totalGaji = $upahHarian + $upahBorongan;

    /* Kasbon dipotong HANYA sebatas upah yang tersedia (urut dari yang paling lama).
       Kasbon yang tidak muat tetap AKTIF dan akan dipotong pada periode berikutnya —
       ini penting supaya kasbon tidak "hilang" saat upah pekerja kecil/kosong. */
    $kasbonSemua = kasbon_aktif($userId, $sampai);
    $kasbon = [];          // kasbon yang benar-benar dipotong (potong penuh / sebagian)
    $totalKasbon = 0.0;
    $sisaUpah = $totalGaji;
    foreach ($kasbonSemua as $k) {
        $nominal = (float) $k['nominal'];
        if ($nominal <= 0 || $sisaUpah <= 0) {
            continue;
        }
        // Potong sebesar yang muat. Bila tidak cukup, sisanya dibuat baris kasbon baru
        // (kasbon asli ditandai selesai dipotong) supaya pembayarannya bertahap & jelas.
        $potong = min($nominal, $sisaUpah);
        $kasbon[] = ['kasbon' => $k, 'potong' => $potong, 'sisa' => max(0.0, $nominal - $potong)];
        $totalKasbon += $potong;
        $sisaUpah -= $potong;
    }
    $totalKasbonSemua = array_sum(array_map(fn($k) => (float) $k['nominal'], $kasbonSemua));

    // Sudah pernah digaji untuk periode ini?
    $st = db()->prepare('SELECT * FROM penggajian WHERE user_id = ? AND periode_dari = ? AND periode_sampai = ? AND perusahaan_id = ?');
    $st->execute([$userId, $dari, $sampai, tenant_id()]);
    $sudah = $st->fetch() ?: null;

    return [
        'orang' => $orang,
        'skema' => (string) ($orang['skema'] ?? 'harian'),
        'hari' => $hari,
        'upah_harian' => $upahHarian,
        'rincian_harian' => $rincianHarian,
        'upah_borongan' => $upahBorongan,
        'rincian_borongan' => $rincianBorongan,
        'total_gaji' => $totalGaji,
        'kasbon' => $kasbon,
        'kasbon_semua' => $kasbonSemua,
        'total_kasbon' => $totalKasbon,
        'total_kasbon_semua' => $totalKasbonSemua,
        'kasbon_tertahan' => max(0.0, $totalKasbonSemua - $totalKasbon),
        'diterima' => max(0.0, $totalGaji - $totalKasbon),
        'sudah' => $sudah,
    ];
}

/** Buat penggajian (snapshot) untuk seorang pekerja; kasbon yang dipotong ikut ditandai */
function buat_penggajian(int $userId, string $dari, string $sampai, int $olehUser): ?int
{
    $g = hitung_gaji($userId, $dari, $sampai);
    if (!$g['orang']) {
        return null;
    }
    if ($g['sudah']) {
        return (int) $g['sudah']['id']; // sudah ada, jangan dobel
    }
    // Tanpa upah pada periode ini tidak ada yang dibayarkan (kasbon tetap aktif)
    if ($g['total_gaji'] <= 0) {
        return null;
    }

    $st = db()->prepare('SELECT COUNT(*) FROM penggajian WHERE perusahaan_id = ?');
    $st->execute([tenant_id()]);
    $urut = (int) $st->fetchColumn() + 1;
    $nomor = 'GJ-' . date('ym', strtotime($sampai)) . '-' . str_pad((string) $urut, 3, '0', STR_PAD_LEFT);

    db()->prepare(
        "INSERT INTO penggajian (nomor, user_id, periode_dari, periode_sampai, hari_kerja, upah_harian,
             upah_borongan, total_gaji, total_kasbon, total_dibayar, status, catatan, created_by, perusahaan_id)
         VALUES (?,?,?,?,?,?,?,?,?,?,'belum','',?,?)"
    )->execute([
        $nomor, $userId, $dari, $sampai,
        $g['hari'], $g['upah_harian'], $g['upah_borongan'], $g['total_gaji'], $g['total_kasbon'], $g['diterima'],
        $olehUser, tenant_id(),
    ]);
    $id = (int) db()->lastInsertId();

    $ins = db()->prepare(
        'INSERT INTO penggajian_item (penggajian_id, jenis, keterangan, volume, satuan, nilai, perusahaan_id)
         VALUES (?,?,?,?,?,?,?)'
    );
    foreach ($g['rincian_harian'] as $r) {
        $ins->execute([$id, 'harian', 'Kerja ' . tgl($r['tanggal']) . ($r['project_nama'] ? ' — ' . $r['project_nama'] : ''),
            (float) $r['hari'], 'hari', (float) $r['hari'] * (float) $r['upah'], tenant_id()]);
    }
    foreach ($g['rincian_borongan'] as $r) {
        $ins->execute([$id, 'borongan', $r['pekerjaan_nama'] . ' (' . tgl($r['tanggal']) . ')',
            (float) $r['volume'], (string) $r['satuan'], (float) $r['nilai'], tenant_id()]);
    }
    $updKasbon = db()->prepare('UPDATE kasbon SET penggajian_id = ? WHERE id = ? AND perusahaan_id = ?');
    $insKasbon = db()->prepare(
        'INSERT INTO kasbon (user_id, tanggal, nominal, keterangan, created_by, perusahaan_id)
         VALUES (?,?,?,?,?,?)'
    );
    foreach ($g['kasbon'] as $p) {
        $k = $p['kasbon'];
        $ins->execute([$id, 'kasbon',
            'Kasbon ' . tgl($k['tanggal']) . ($k['keterangan'] !== '' ? ' — ' . $k['keterangan'] : '')
                . ((float) $k['nominal'] > $p['potong'] ? ' (dipotong sebagian)' : ''),
            0, '', -1 * $p['potong'], tenant_id()]);
        $updKasbon->execute([$id, (int) $k['id'], tenant_id()]);
        if ($p['sisa'] > 0) {
            // sisa kasbon yang belum terpotong -> jadi kasbon aktif untuk periode berikutnya
            $insKasbon->execute([
                $userId, $k['tanggal'], $p['sisa'],
                ($k['keterangan'] !== '' ? $k['keterangan'] . ' — ' : '') . 'sisa belum terpotong',
                $olehUser, tenant_id(),
            ]);
        }
    }
    return $id;
}

function fetch_penggajian(array $f = [], int $limit = 300): array
{
    $w = [];
    $a = [];
    $u = current_user();
    $w[] = tenant_where('g', $a);
    if ($u && $u['role'] === 'pekerja' && empty($f['semua_orang'])) {
        $w[] = 'g.user_id = ?';
        $a[] = (int) $u['id'];
    } elseif (!empty($f['user_id'])) {
        $w[] = 'g.user_id = ?';
        $a[] = (int) $f['user_id'];
    }
    if (!empty($f['status'])) {
        $w[] = 'g.status = ?';
        $a[] = (string) $f['status'];
    }
    if (!empty($f['dari'])) {
        $w[] = 'g.periode_sampai >= ?';
        $a[] = (string) $f['dari'];
    }
    if (!empty($f['sampai'])) {
        $w[] = 'g.periode_sampai <= ?';
        $a[] = (string) $f['sampai'];
    }
    $sql = 'SELECT g.*, us.nama AS pekerja_nama, us.jabatan, us.skema
            FROM penggajian g JOIN users us ON us.id = g.user_id
            WHERE ' . implode(' AND ', $w) . '
            ORDER BY g.periode_sampai DESC, us.nama LIMIT ' . (int) $limit;
    $st = db()->prepare($sql);
    $st->execute($a);
    return $st->fetchAll();
}

function get_penggajian(int $id): ?array
{
    $st = db()->prepare(
        'SELECT g.*, us.nama AS pekerja_nama, us.jabatan, us.skema, us.upah_harian AS tarif_harian, cb.nama AS pembuat_nama
         FROM penggajian g JOIN users us ON us.id = g.user_id
         LEFT JOIN users cb ON cb.id = g.created_by
         WHERE g.id = ? AND g.perusahaan_id = ?'
    );
    $st->execute([$id, tenant_id()]);
    return $st->fetch() ?: null;
}

function item_penggajian(int $id): array
{
    $st = db()->prepare('SELECT * FROM penggajian_item WHERE penggajian_id = ? AND perusahaan_id = ? ORDER BY jenis, id');
    $st->execute([$id, tenant_id()]);
    return $st->fetchAll();
}

/** Ringkasan gaji sebuah periode (untuk kartu & daftar) */
function ringkasan_gaji(array $f = []): array
{
    $daftar = fetch_penggajian($f, 9999);
    $out = ['jumlah' => count($daftar), 'belum' => 0.0, 'dibayar' => 0.0, 'kasbon' => 0.0, 'belum_orang' => 0, 'dibayar_orang' => 0];
    foreach ($daftar as $g) {
        if ($g['status'] === 'dibayar') {
            $out['dibayar'] += (float) $g['total_dibayar'];
            $out['dibayar_orang']++;
        } else {
            $out['belum'] += (float) $g['total_dibayar'];
            $out['belum_orang']++;
        }
        $out['kasbon'] += (float) $g['total_kasbon'];
    }
    return $out;
}

function fetch_kasbon(array $f = [], int $limit = 300): array
{
    $w = [];
    $a = [];
    $u = current_user();
    $w[] = tenant_where('k', $a);
    if ($u && $u['role'] === 'pekerja' && empty($f['semua_orang'])) {
        $w[] = 'k.user_id = ?';
        $a[] = (int) $u['id'];
    } elseif (!empty($f['user_id'])) {
        $w[] = 'k.user_id = ?';
        $a[] = (int) $f['user_id'];
    }
    if (!empty($f['dari'])) {
        $w[] = 'k.tanggal >= ?';
        $a[] = (string) $f['dari'];
    }
    if (!empty($f['sampai'])) {
        $w[] = 'k.tanggal <= ?';
        $a[] = (string) $f['sampai'];
    }
    $status = (string) ($f['status'] ?? '');
    if ($status === 'aktif') {
        $w[] = 'k.penggajian_id IS NULL';
    } elseif ($status === 'dipotong') {
        $w[] = 'k.penggajian_id IS NOT NULL';
    }
    $sql = 'SELECT k.*, us.nama AS pekerja_nama, us.jabatan, g.nomor AS gaji_nomor
            FROM kasbon k JOIN users us ON us.id = k.user_id
            LEFT JOIN penggajian g ON g.id = k.penggajian_id
            WHERE ' . implode(' AND ', $w) . '
            ORDER BY k.tanggal DESC, us.nama LIMIT ' . (int) $limit;
    $st = db()->prepare($sql);
    $st->execute($a);
    return $st->fetchAll();
}

function get_kasbon(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM kasbon WHERE id = ? AND perusahaan_id = ?');
    $st->execute([$id, tenant_id()]);
    return $st->fetch() ?: null;
}

/** Ringkasan kasbon untuk kartu halaman kasbon */
function ringkasan_kasbon(array $f = []): array
{
    $daftar = fetch_kasbon($f, 9999);
    $out = ['jumlah' => count($daftar), 'aktif' => 0.0, 'dipotong' => 0.0, 'orang' => []];
    foreach ($daftar as $k) {
        if ($k['penggajian_id'] === null) {
            $out['aktif'] += (float) $k['nominal'];
        } else {
            $out['dipotong'] += (float) $k['nominal'];
        }
        $out['orang'][(int) $k['user_id']] = ($out['orang'][(int) $k['user_id']] ?? 0) + (float) $k['nominal'];
    }
    return $out;
}
