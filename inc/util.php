<?php
declare(strict_types=1);

/** Escape untuk output HTML */
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

const ROLES = [
    'admin'     => 'Admin',
    'pelaksana' => 'Pelaksana',
    'pekerja'   => 'Pekerja',
];

const STATUS_PEKERJAAN = [
    'belum'    => 'Belum Mulai',
    'proses'   => 'Berjalan',
    'selesai'  => 'Selesai',
    'tertunda' => 'Tertunda',
];

const STATUS_PROJECT = [
    'perencanaan' => 'Perencanaan',
    'berjalan'    => 'Berjalan',
    'selesai'     => 'Selesai',
    'ditunda'     => 'Ditunda',
];

const PRIORITAS = [
    'rendah' => 'Rendah',
    'normal' => 'Normal',
    'tinggi' => 'Tinggi',
];

function role_label(string $r): string
{
    return ROLES[$r] ?? ucfirst($r);
}

function status_label(string $s): string
{
    return STATUS_PEKERJAAN[$s] ?? ucfirst($s);
}

/** Pekerjaan lewat deadline dan belum selesai */
function is_late(array $p): bool
{
    if (empty($p['deadline']) || $p['status'] === 'selesai') {
        return false;
    }
    return $p['deadline'] < date('Y-m-d');
}

function status_pill(array $p): string
{
    $status = $p['status'];
    if (is_late($p)) {
        return '<span class="pill pill-late">Terlambat</span>';
    }
    return '<span class="pill pill-' . e($status) . '">' . e(status_label($status)) . '</span>';
}

function project_status_pill(string $s): string
{
    return '<span class="pill pill-p' . e($s) . '">' . e(STATUS_PROJECT[$s] ?? $s) . '</span>';
}

function prioritas_pill(string $p): string
{
    return '<span class="tag tag-' . e($p) . '">' . e(PRIORITAS[$p] ?? $p) . '</span>';
}

function progress_bar(int $v, string $extra = ''): string
{
    $v = max(0, min(100, $v));
    $cls = $v >= 100 ? 'bar-full' : ($v > 0 ? 'bar-run' : 'bar-zero');
    return '<div class="bar ' . $cls . $extra . '"><span style="width:' . $v . '%"></span></div>';
}

function tgl(?string $d, string $fallback = '—'): string
{
    if (!$d) {
        return $fallback;
    }
    $ts = strtotime($d);
    if (!$ts) {
        return e($d);
    }
    $bulan = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    return date('j', $ts) . ' ' . $bulan[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

/** Selisih hari dari hari ini (bisa negatif) */
function hari_selisih(string $d): int
{
    $a = new DateTime(date('Y-m-d'));
    $b = new DateTime($d);
    return (int) $a->diff($b)->format('%r%a');
}

function deadline_note(?string $d, string $status): string
{
    if (!$d) {
        return '<span class="muted">Tanpa deadline</span>';
    }
    $selisih = hari_selisih($d);
    if ($status === 'selesai') {
        return '<span class="muted">' . e(tgl($d)) . '</span>';
    }
    if ($selisih < 0) {
        return '<span class="deadline-late">' . e(tgl($d)) . ' · lewat ' . abs($selisih) . ' hari</span>';
    }
    if ($selisih === 0) {
        return '<span class="deadline-soon">' . e(tgl($d)) . ' · hari ini</span>';
    }
    if ($selisih <= 7) {
        return '<span class="deadline-soon">' . e(tgl($d)) . ' · ' . $selisih . ' hari lagi</span>';
    }
    return '<span class="muted">' . e(tgl($d)) . '</span>';
}

function rupiah(float|int|null $n): string
{
    return 'Rp ' . number_format((float) $n, 0, ',', '.');
}

function num(float|int|null $n): string
{
    $n = (float) $n;
    if (floor($n) == $n) {
        return number_format($n, 0, ',', '.');
    }
    return number_format($n, 2, ',', '.');
}

function initials(string $nama): string
{
    $parts = preg_split('/\s+/', trim($nama)) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        if ($p === '') {
            continue;
        }
        if (function_exists('mb_substr')) {
            $out .= mb_strtoupper(mb_substr($p, 0, 1), 'UTF-8');
        } else {
            preg_match('/./u', $p, $m);
            $out .= strtoupper($m[0] ?? '');
        }
    }
    return $out !== '' ? $out : '?';
}

/** Warna avatar konsisten dari nama */
function avatar_hue(string $s): int
{
    return (int) (crc32($s) % 360);
}

function badge_avatar(string $nama, string $size = ''): string
{
    return '<span class="avatar ' . $size . '" style="--h:' . avatar_hue($nama) . '">' . e(initials($nama)) . '</span>';
}

/** Daftar pekerja yang ditugaskan ke sebuah pekerjaan */
function pekerja_of(int $pekerjaanId): array
{
    $sql = 'SELECT u.* FROM pekerjaan_pekerja pp JOIN users u ON u.id = pp.user_id
            WHERE pp.pekerjaan_id = ? ORDER BY u.nama';
    $st = db()->prepare($sql);
    $st->execute([$pekerjaanId]);
    return $st->fetchAll();
}

function pekerja_names(int $pekerjaanId): string
{
    $rows = pekerja_of($pekerjaanId);
    if (!$rows) {
        return '<span class="muted">Belum ada pekerja</span>';
    }
    $names = array_map(fn($r) => e($r['nama']), $rows);
    return implode(', ', $names);
}

function pekerjaan_count_for_user(int $userId, ?string $status = null): int
{
    $sql = 'SELECT COUNT(*) FROM pekerjaan_pekerja pp JOIN pekerjaan p ON p.id = pp.pekerjaan_id WHERE pp.user_id = ?';
    $args = [$userId];
    if ($status) {
        $sql .= ' AND p.status = ?';
        $args[] = $status;
    }
    $st = db()->prepare($sql);
    $st->execute($args);
    return (int) $st->fetchColumn();
}

/** Ubah input "1.250.000" / "1250000,50" / "175.5" menjadi float */
function parse_money(string $v): float
{
    $v = trim($v);
    if ($v === '') {
        return 0.0;
    }
    $v = preg_replace('/[^0-9,.]/', '', $v) ?? '';
    if ($v === '') {
        return 0.0;
    }
    // Ada koma → format Indonesia: titik = ribuan, koma = desimal
    if (str_contains($v, ',')) {
        $v = str_replace('.', '', $v);
        return (float) str_replace(',', '.', $v);
    }
    // "175.000" / "1.250.000" → titik sebagai pemisah ribuan
    if (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) {
        return (float) str_replace('.', '', $v);
    }
    // Lebih dari satu titik tanpa koma → anggap pemisah ribuan
    if (substr_count($v, '.') > 1) {
        return (float) str_replace('.', '', $v);
    }
    // "175000" atau "175.5" (desimal)
    return (float) $v;
}

/** Angka hari untuk CSV: 15 / 13,5 / 1.234,5 */
function num_hari(float|int|null $n): string
{
    $n = (float) $n;
    if (floor($n) == $n) {
        return number_format($n, 0, ',', '.');
    }
    return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
}

/** Angka untuk ekspor XML/Excel: desimal pakai titik, tanpa pemisah ribuan */
function num_xml(float|int|null $n): string
{
    $n = (float) $n;
    $s = rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    return $s === '' || $s === '-' ? '0' : $s;
}

/** Jumlah hari ditulis rapi: 1 hari, 0,5 hari, 2 hari */
function hari_format(float|int|null $n): string
{
    $n = (float) $n;
    $s = ($n == floor($n)) ? number_format($n, 0, ',', '.') : number_format($n, 1, ',', '.');
    return $s . ' hari';
}

/** Nilai nominal yang aman ditampilkan sesuai hak akses */
function upah_or_hidden(bool $bolehLihat, float|int|null $nominal, string $fallback = '—'): string
{
    if (!$bolehLihat) {
        return '<span class="muted">' . e($fallback) . '</span>';
    }
    return e(rupiah($nominal));
}

/** Rentang tanggal default: bulan berjalan */
function default_periode(): array
{
    return [date('Y-m-01'), date('Y-m-t')];
}

function valid_tanggal(string $d): string
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 ? $d : '';
}

/** Username/nama file yang aman */
function slugify(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-') ?: 'data';
}
