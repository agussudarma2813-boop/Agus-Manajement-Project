<?php
declare(strict_types=1);

function nav_icon(string $name): string
{
    $paths = [
        'dashboard' => '<path d="M3 13h8V3H3v10Zm10 8h8V11h-8v10ZM3 21h8v-6H3v6Zm10-12h8V3h-8v6Z"/>',
        'project'   => '<path d="M3 7a2 2 0 0 1 2-2h3.6l1.7 2H19a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/>',
        'work'      => '<path d="M9 3h6a1 1 0 0 1 1 1v1h2.5A1.5 1.5 0 0 1 20 6.5V19a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6.5A1.5 1.5 0 0 1 5.5 5H8V4a1 1 0 0 1 1-1Zm1 2h4V4h-4v1Z"/>',
        'team'      => '<path d="M8 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm8 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM2 20c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5H2Zm14.5 0c0-2.2-.8-4-2-5.2.5-.1 1-.2 1.5-.2 2.8 0 5 1.9 5 5.4h-4.5Z"/>',
        'report'    => '<path d="M5 3h9l5 5v13H5V3Zm8 1.5V9h4.5L13 4.5ZM7 12h10v1.6H7V12Zm0 4h7v1.6H7V16Z"/>',
        'user'      => '<path d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Zm0 2c-4.4 0-8 2.6-8 6h16c0-3.4-3.6-6-8-6Z"/>',
        'location'  => '<path d="M12 22s7-6.3 7-12A7 7 0 0 0 5 10c0 5.7 7 12 7 12Zm0-9.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5Z"/>',
        'clock'     => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 5h-2v6l5 3 1-1.7-4-2.3V7Z"/>',
        'money'     => '<path d="M3 6h18a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Zm9 3a3 3 0 1 0 0 6 3 3 0 0 0 0-6ZM5 9a2 2 0 0 1-2 2v2a2 2 0 0 1 2 2h14a2 2 0 0 1 2-2v-2a2 2 0 0 1-2-2H5Z"/>',
        'price'     => '<path d="M4 3h10l6 6v12H4V3Zm2 2v14h12V10h-5V5H6Zm7 1.4V8h1.6L13 6.4ZM7 12h10v1.6H7V12Zm0 4h7v1.6H7V16Z"/>',
        'wallet'    => '<path d="M3 6h15a3 3 0 0 1 3 3v9H6a3 3 0 0 1-3-3V6Zm2 1.2V15a1 1 0 0 0 1 1h13V9a1 1 0 0 0-1-1H5Zm11 5.8a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z"/>',
        'cash'      => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 4h1.4l.3 1.6c1.4.3 2.3 1.1 2.5 2.3l-1.8.3c-.1-.6-.7-1-1.6-1-.8 0-1.2.3-1.2.7 0 .5.5.7 1.8 1 1.7.4 2.9 1 2.9 2.5 0 1.2-1 2-2.4 2.3l-.2 1.5H12l-.2-1.5c-1.6-.3-2.6-1.2-2.8-2.5l1.8-.3c.2.7.9 1.1 1.9 1.1 1 0 1.4-.3 1.4-.8 0-.5-.5-.7-1.9-1-1.7-.4-2.8-1-2.8-2.5 0-1.2.9-2 2.3-2.3L12 6Z"/>',
        'company'   => '<path d="M3 21V7l6-3v3l6-3v4h6v13H3Zm2-2h14V10h-4V7.6l-6 3V9L5 8.1V19Zm2-2h2v-2H7v2Zm4 0h2v-2h-2v2Zm4 0h2v-2h-2v2ZM7 13h2v-2H7v2Zm4 0h2v-2h-2v2Zm4 0h2v-2h-2v2Z"/>',
        'calendar'  => '<path d="M7 2h2v2h6V2h2v2h3v18H4V4h3V2Zm11 8H6v10h12V10ZM6 8h12V6H6v2Z"/>',
        'check'     => '<path d="M9.6 16.2 5.4 12l-1.4 1.4 5.6 5.6L20.4 7.8 19 6.4 9.6 16.2Z"/>',
        'wa'        => '<path d="M12 2a9.9 9.9 0 0 0-8.5 15L2 22l5.2-1.4A9.94 9.94 0 1 0 12 2Zm0 2a7.94 7.94 0 0 1 0 15.9 7.9 7.9 0 0 1-4-1.1l-.4-.2-2.5.7.7-2.4-.2-.4A7.94 7.94 0 0 1 12 4Zm-3 4c-.3 0-.7.1-.9.5-.2.3-.7 1-.7 1.8 0 .9.6 1.7 1.4 2.8.9 1.2 2 1.9 3.1 2.2 1.1.3 1.6.2 2.1.1.5-.1 1.2-.6 1.4-1.1.2-.5.2-.9.1-1-.1-.1-.3-.2-.6-.4l-1.2-.6c-.2-.1-.4-.1-.6.1l-.6.7c-.1.2-.3.2-.5.1-.3-.1-.9-.4-1.5-1-.5-.5-.8-1.1-.9-1.3-.1-.2 0-.4.1-.5l.4-.5c.1-.2.1-.3 0-.5l-.6-1.3c-.1-.3-.3-.3-.5-.3H9Z"/>',
        'invoice'   => '<path d="M6 2h9l4 4v16H6V2Zm2 2v16h9V7h-4V5H8Zm2 6h7v1.6h-7V10Zm0 4h7v1.6h-7V14Zm0 4h5v1.6h-5V18Z"/>',
    ];
    $p = $paths[$name] ?? $paths['dashboard'];
    return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true">' . $p . '</svg>';
}

function render_header(string $title, string $subtitle = '', string $actions = ''): void
{
    require_login();
    $u = current_user();
    $page = basename($_SERVER['PHP_SELF'] ?? '');
    $current = $page . (isset($_GET['role']) && $page === 'tim.php' ? '?role=' . e((string) $_GET['role']) : '');

    $isPekerja = $u['role'] === 'pekerja';
    $merek = brand();
    $bantu = mode_bantu();
    $perusahaanBantu = $bantu ? tenant() : null;

    $nav = [
        ['label' => 'Dashboard',  'href' => 'dashboard.php', 'icon' => 'dashboard'],
        ['label' => 'Project',    'href' => 'projects.php',  'icon' => 'project'],
        ['label' => $isPekerja ? 'Tugas Saya' : 'Pekerjaan', 'href' => 'pekerjaan.php', 'icon' => 'work'],
        ['sep' => 'Kerja Harian'],
        ['label' => $isPekerja ? 'Jadwal Saya' : 'Jadwal Harian', 'href' => 'jadwal_harian.php', 'icon' => 'calendar'],
        ['label' => $isPekerja ? 'Hasil Kerja Saya' : 'Hasil Kerja Harian', 'href' => 'laporan_kerja.php', 'icon' => 'check'],
        ['sep' => 'Absensi & Upah'],
        ['label' => $isPekerja ? 'Absensi Saya' : 'Absensi Hari Kerja', 'href' => 'absensi.php', 'icon' => 'clock'],
        ['label' => $isPekerja ? 'Upah Saya' : 'Upah & Gaji', 'href' => 'upah.php', 'icon' => 'money'],
        ['sep' => 'Gaji & Kasbon'],
        ['label' => $isPekerja ? 'Gaji Saya' : 'Gaji Pekerja', 'href' => 'gaji.php', 'icon' => 'wallet'],
        ['label' => $isPekerja ? 'Kasbon Saya' : 'Kasbon', 'href' => 'kasbon.php', 'icon' => 'cash'],
    ];
    if (!$isPekerja) {
        $nav[] = ['sep' => 'Harga & Tagihan'];
        $nav[] = ['label' => 'Harga Satuan', 'href' => 'harga_satuan.php', 'icon' => 'price'];
        $nav[] = ['label' => 'Pengajuan', 'href' => 'pengajuan.php', 'icon' => 'invoice'];
    }
    $nav[] = ['sep' => 'Tim & Organisasi'];
    $nav = array_merge($nav, [
        ['label' => 'Pelaksana', 'href' => 'tim.php?role=pelaksana', 'icon' => 'team'],
        ['label' => 'Pekerja',   'href' => 'tim.php?role=pekerja',   'icon' => 'user'],
    ]);
    if ($u['role'] !== 'pekerja') {
        $nav[] = ['label' => 'Laporan', 'href' => 'laporan.php', 'icon' => 'report'];
    }
    if (can_manage_users()) {
        $nav[] = ['sep' => 'Administrasi'];
        $nav[] = ['label' => 'Pengguna & Akun', 'href' => 'users.php', 'icon' => 'user'];
    }
    if (is_owner()) {
        $nav[] = ['sep' => 'Pengelola Aplikasi'];
        $nav[] = ['label' => 'Perusahaan & Lisensi', 'href' => 'perusahaan.php', 'icon' => 'company'];
    }
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · Manajemen Project</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <?php if ($merek['logo'] !== ''): ?>
        <img class="brand-logo" src="<?= e($merek['logo']) ?>" alt="Logo <?= e($merek['nama']) ?>">
      <?php else: ?>
        <span class="brand-mark"><?= nav_icon('project') ?></span>
      <?php endif; ?>
      <span class="brand-text">
        <strong><?= e($merek['nama']) ?></strong>
        <small>Manajemen Project</small>
      </span>
    </div>

    <nav class="nav">
      <?php foreach ($nav as $item): ?>
        <?php if (isset($item['sep'])): ?>
          <div class="nav-sep"><?= e($item['sep']) ?></div>
        <?php else: ?>
          <a class="nav-link <?= $current === $item['href'] ? 'is-active' : '' ?>" href="<?= e($item['href']) ?>">
            <?= nav_icon($item['icon']) ?><span><?= e($item['label']) ?></span>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>

    <div class="side-foot">
      <div class="side-user">
        <?= badge_avatar($u['nama']) ?>
        <div class="side-user-info">
          <strong><?= e($u['nama']) ?></strong>
          <small><?= e(role_label($u['role'])) ?><?= $u['jabatan'] ? ' · ' . e($u['jabatan']) : '' ?></small>
        </div>
      </div>
      <a class="side-logout" href="logout.php">Keluar</a>
    </div>
  </aside>

  <main class="main">
    <header class="topbar">
      <button class="burger" type="button" data-toggle-sidebar aria-label="Menu">☰</button>
      <div class="topbar-title">
        <h1><?= e($title) ?></h1>
        <?php if ($subtitle !== ''): ?><p><?= $subtitle ?></p><?php endif; ?>
      </div>
      <div class="topbar-actions">
        <?= $actions ?>
        <details class="user-menu">
          <summary>
            <?= badge_avatar($u['nama']) ?>
            <span class="user-menu-name"><?= e($u['nama']) ?></span>
            <span class="caret">▾</span>
          </summary>
          <div class="user-menu-list">
            <div class="user-menu-head">
              <strong><?= e($u['nama']) ?></strong>
              <small><?= e($u['username']) ?> · <?= e(role_label($u['role'])) ?></small>
            </div>
            <a href="profile.php">Profil &amp; Kata Sandi</a>
            <a href="logout.php">Keluar</a>
          </div>
        </details>
      </div>
    </header>

    <div class="content">
      <?php if ($bantu): ?>
        <div class="alert alert-info banner-bantu">
          <strong>Mode bantu</strong> — Anda sedang mengelola data perusahaan
          <strong><?= e((string) ($perusahaanBantu['nama'] ?? '-')) ?></strong> sebagai pengelola aplikasi.
          <a class="btn btn-sm" href="perusahaan_keluar.php">Keluar dari mode bantu</a>
        </div>
      <?php endif; ?>
      <?php foreach (take_flash() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>"><?= $f['msg'] ?></div>
      <?php endforeach; ?>
<?php
}

function render_footer(): void
{
    ?>
    </div>
  </main>
</div>
<script src="assets/app.js"></script>
</body>
</html>
<?php
}

/** Kartu statistik */
function stat_card(string $label, string $value, string $sub = '', string $tone = ''): void
{
    echo '<div class="stat ' . ($tone ? 'stat-' . e($tone) : '') . '">'
        . '<span class="stat-label">' . e($label) . '</span>'
        . '<span class="stat-value">' . $value . '</span>'
        . ($sub !== '' ? '<span class="stat-sub">' . $sub . '</span>' : '')
        . '</div>';
}

function page_url(array $params): string
{
    $q = array_merge($_GET, $params);
    $q = array_filter($q, fn($v) => $v !== '' && $v !== null);
    return basename($_SERVER['PHP_SELF']) . ($q ? '?' . http_build_query($q) : '');
}
