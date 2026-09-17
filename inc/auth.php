<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/tenant.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Simpan session di folder aplikasi sendiri agar tidak bergantung pada
    // session.save_path bawaan server (yang bisa saja tidak writable).
    $sessionDir = APP_ROOT . '/data/sessions';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0775, true);
    }
    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        session_save_path($sessionDir);
    }
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function current_user(): ?array
{
    static $cached = false;
    static $user = null;
    if ($cached) {
        return $user;
    }
    $cached = true;
    if (empty($_SESSION['uid'])) {
        return $user = null;
    }
    $st = db()->prepare('SELECT * FROM users WHERE id = ? AND aktif = 1');
    $st->execute([$_SESSION['uid']]);
    $row = $st->fetch();
    if (!$row) {
        unset($_SESSION['uid']);
        return $user = null;
    }
    return $user = $row;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect('login.php');
    }
    return $u;
}

function require_role(array $roles): array
{
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        render_forbidden();
        exit;
    }
    return $u;
}

function is_admin(): bool
{
    $u = current_user();
    return $u && $u['role'] === 'admin';
}

function is_pelaksana(): bool
{
    $u = current_user();
    return $u && $u['role'] === 'pelaksana';
}

/* ---------- Aturan akses ---------- */

function can_manage_project(?array $project): bool
{
    $u = current_user();
    if (!$u || !$project) {
        return false;
    }
    if ($u['role'] === 'admin') {
        return true;
    }
    return $u['role'] === 'pelaksana' && (int) $project['pelaksana_id'] === (int) $u['id'];
}

function can_manage_pekerjaan(?array $pekerjaan): bool
{
    if (!$pekerjaan) {
        return false;
    }
    $project = get_project((int) $pekerjaan['project_id']);
    return can_manage_project($project);
}

function can_update_progress(?array $pekerjaan): bool
{
    $u = current_user();
    if (!$u || !$pekerjaan) {
        return false;
    }
    if (can_manage_pekerjaan($pekerjaan) || (int) $pekerjaan['pelaksana_id'] === (int) $u['id']) {
        return true;
    }
    $st = db()->prepare('SELECT 1 FROM pekerjaan_pekerja WHERE pekerjaan_id = ? AND user_id = ?');
    $st->execute([$pekerjaan['id'], $u['id']]);
    return (bool) $st->fetchColumn();
}

function can_view_pekerjaan(?array $pekerjaan): bool
{
    $u = current_user();
    if (!$u || !$pekerjaan) {
        return false;
    }
    if ($u['role'] !== 'pekerja') {
        return true;
    }
    return can_update_progress($pekerjaan);
}

function can_manage_users(): bool
{
    return is_admin();
}

/**
 * Boleh melihat nominal upah/gaji SEMUA orang?
 * (admin & pelaksana ya; pekerja hanya boleh melihat miliknya sendiri)
 */
function can_view_all_wages(): bool
{
    $u = current_user();
    return $u !== null && in_array($u['role'], ['admin', 'pelaksana'], true);
}

/** Boleh melihat nominal upah milik user tertentu? */
function can_view_wages_of(int $userId): bool
{
    $u = current_user();
    if ($u === null) {
        return false;
    }
    return can_view_all_wages() || (int) $u['id'] === $userId;
}

/** Boleh mencatat absensi (hari kerja) untuk project tertentu? */
function can_record_absensi(?array $project = null): bool
{
    $u = current_user();
    if ($u === null) {
        return false;
    }
    if ($u['role'] === 'admin') {
        return true;
    }
    if ($u['role'] !== 'pelaksana') {
        return false;
    }
    // pelaksana hanya untuk project yang dia pimpin
    return $project === null ? true : can_manage_project($project);
}

/* ---------- Query singkat ---------- */

function get_project(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM projects WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch() ?: null;
    // Lindungi dari tebak ID: hanya project milik perusahaan aktif
    return tenant_ok($row) ? $row : null;
}

function get_pekerjaan(int $id): ?array
{
    $st = db()->prepare(
        'SELECT pj.*, pr.nama AS project_nama, pr.kode AS project_kode, pr.pelaksana_id AS project_pelaksana,
                l.nama AS lokasi_nama, u.nama AS pelaksana_nama
         FROM pekerjaan pj
         JOIN projects pr ON pr.id = pj.project_id
         LEFT JOIN lokasi l ON l.id = pj.lokasi_id
         LEFT JOIN users u ON u.id = pj.pelaksana_id
         WHERE pj.id = ?'
    );
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row || !tenant_ok($row)) {
        return null;
    }
    // can_manage_pekerjaan() butuh field pelaksana_id milik project
    $row['project_pelaksana_id'] = $row['project_pelaksana'];
    return $row;
}

function get_lokasi(int $id): ?array
{
    $st = db()->prepare(
        'SELECT l.* FROM lokasi l JOIN projects pr ON pr.id = l.project_id
         WHERE l.id = ? AND pr.perusahaan_id = ?'
    );
    $st->execute([$id, tenant_id()]);
    return $st->fetch() ?: null;
}

function all_users(string $role = ''): array
{
    $tid = tenant_id();
    if ($role !== '') {
        $st = db()->prepare('SELECT * FROM users WHERE perusahaan_id = ? AND role = ? ORDER BY nama');
        $st->execute([$tid, $role]);
        return $st->fetchAll();
    }
    $st = db()->prepare(
        "SELECT * FROM users WHERE perusahaan_id = ?
         ORDER BY CASE role WHEN 'admin' THEN 1 WHEN 'pelaksana' THEN 2 ELSE 3 END, nama"
    );
    $st->execute([$tid]);
    return $st->fetchAll();
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $ok = isset($_POST['_csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string) $_POST['_csrf']);
    if (!$ok) {
        http_response_code(419);
        exit('Sesi form tidak valid. Silakan muat ulang halaman.');
    }
}

/* ---------- Flash + redirect ---------- */

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function take_flash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function render_forbidden(): void
{
    if (function_exists('render_header')) {
        render_header('Akses Ditolak');
        echo '<div class="card"><h2 class="card-title">Akses ditolak</h2>'
            . '<p class="muted">Peran akun kamu tidak punya hak untuk membuka halaman ini.</p>'
            . '<a class="btn btn-primary" href="dashboard.php">Kembali ke Dashboard</a></div>';
        render_footer();
    } else {
        echo 'Akses ditolak.';
    }
}
