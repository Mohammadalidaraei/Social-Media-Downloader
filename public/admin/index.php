<?php
declare(strict_types=1);

/**
 * MediaBot Admin Panel
 * Secure web-based dashboard for monitoring and management.
 * Access: https://yourdomain.com/public/admin/index.php
 */

define('ADMIN_ROOT', dirname(dirname(__DIR__)));
require_once ADMIN_ROOT . '/src/autoload.php';

use MediaBot\Database\DatabaseManager;
use MediaBot\Queue\QueueManager;
use MediaBot\Cache\CacheManager;
use MediaBot\Bot\Logger;

$config = require ADMIN_ROOT . '/config/config.php';
$db     = DatabaseManager::getInstance($config['database']);

session_start();

// ─── Auth ────────────────────────────────────────────────────────────────────
function isLoggedIn(): bool { return !empty($_SESSION['admin_logged_in']); }

function requireAuth(): void
{
    if (!isLoggedIn()) {
        header('Location: index.php?page=login');
        exit;
    }
}

// ─── Handle actions ──────────────────────────────────────────────────────────
$page   = $_GET['page']   ?? 'dashboard';
$action = $_POST['action'] ?? '';

if ($action === 'login') {
    $password = $_POST['password'] ?? '';
    if ($password === $config['telegram']['admin_password']) {
        $_SESSION['admin_logged_in']   = true;
        $_SESSION['admin_login_time']  = time();
        $_SESSION['admin_ip']          = $_SERVER['REMOTE_ADDR'];
        header('Location: index.php');
        exit;
    }
    $loginError = 'Invalid password';
}

if ($action === 'logout') {
    session_destroy();
    header('Location: index.php?page=login');
    exit;
}

if ($action === 'ban_user' && isLoggedIn()) {
    $targetId = (int)($_POST['user_id'] ?? 0);
    $reason   = htmlspecialchars($_POST['reason'] ?? 'Admin ban');
    if ($targetId) {
        $db->update('users', ['is_banned' => 1, 'ban_reason' => $reason], 'telegram_id=?', [$targetId]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => "User #{$targetId} banned."];
    }
    header('Location: index.php?page=users');
    exit;
}

if ($action === 'unban_user' && isLoggedIn()) {
    $targetId = (int)($_POST['user_id'] ?? 0);
    if ($targetId) {
        $db->update('users', ['is_banned' => 0, 'ban_reason' => null], 'telegram_id=?', [$targetId]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => "User #{$targetId} unbanned."];
    }
    header('Location: index.php?page=users');
    exit;
}

if ($action === 'toggle_maintenance' && isLoggedIn()) {
    $current = $db->fetchColumn("SELECT value FROM settings WHERE key='maintenance_mode'");
    $new     = $current === '1' ? '0' : '1';
    $db->update('settings', ['value' => $new], "key='maintenance_mode'");
    header('Location: index.php');
    exit;
}

if ($action === 'cleanup_queue' && isLoggedIn()) {
    $queue   = new QueueManager($db, $config['queue']);
    $deleted = $queue->cleanup();
    $_SESSION['flash'] = ['type' => 'success', 'msg' => "Cleaned {$deleted} old queue entries."];
    header('Location: index.php?page=queue');
    exit;
}

if ($action === 'cleanup_cache' && isLoggedIn()) {
    $cache   = new CacheManager($db, $config['cache']);
    $deleted = $cache->cleanup();
    $_SESSION['flash'] = ['type' => 'success', 'msg' => "Cleaned {$deleted} expired cache entries."];
    header('Location: index.php?page=cache');
    exit;
}

// Require auth for all non-login pages
if ($page !== 'login') requireAuth();

// ─── Fetch Data ───────────────────────────────────────────────────────────────
$maintenance = (bool)(int)($db->fetchColumn("SELECT value FROM settings WHERE key='maintenance_mode'") ?: '0');
$flash       = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$stats = [
    'users'            => (int)$db->fetchColumn("SELECT COUNT(*) FROM users"),
    'downloads'        => (int)$db->fetchColumn("SELECT COUNT(*) FROM download_history"),
    'active_users_day' => (int)$db->fetchColumn("SELECT COUNT(DISTINCT user_id) FROM download_history WHERE created_at > datetime('now','-1 day')"),
    'queue_pending'    => (int)$db->fetchColumn("SELECT COUNT(*) FROM download_queue WHERE status='pending'"),
    'queue_processing' => (int)$db->fetchColumn("SELECT COUNT(*) FROM download_queue WHERE status='processing'"),
    'cache_entries'    => (int)$db->fetchColumn("SELECT COUNT(*) FROM file_cache WHERE expires_at > datetime('now')"),
    'cache_hits'       => (int)$db->fetchColumn("SELECT SUM(hit_count) FROM file_cache") ?: 0,
    'today_downloads'  => (int)$db->fetchColumn("SELECT total_downloads FROM stats_daily WHERE date=?", [date('Y-m-d')]) ?: 0,
    'today_users'      => (int)$db->fetchColumn("SELECT new_users FROM stats_daily WHERE date=?", [date('Y-m-d')]) ?: 0,
    'failed_today'     => (int)$db->fetchColumn("SELECT failed_downloads FROM stats_daily WHERE date=?", [date('Y-m-d')]) ?: 0,
];

$cacheHitRate = $stats['downloads'] > 0
    ? round(($db->fetchColumn("SELECT COUNT(*) FROM download_history WHERE from_cache=1") / $stats['downloads']) * 100, 1)
    : 0;

// ─── Page-specific data ──────────────────────────────────────────────────────
$perPage = 25;
$p       = max(1, (int)($_GET['p'] ?? 1));
$offset  = ($p - 1) * $perPage;

$pageData = [];

if ($page === 'users') {
    $search       = $_GET['q'] ?? '';
    $where        = $search ? "WHERE telegram_id LIKE ? OR username LIKE ? OR first_name LIKE ?" : '';
    $searchParams = $search ? ["%{$search}%", "%{$search}%", "%{$search}%"] : [];

    $pageData['total'] = (int)$db->fetchColumn("SELECT COUNT(*) FROM users {$where}", $searchParams);
    $pageData['users'] = $db->fetchAll(
        "SELECT * FROM users {$where} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}",
        $searchParams
    );
}

if ($page === 'queue') {
    $statusFilter = $_GET['status'] ?? '';
    $where        = $statusFilter ? "WHERE q.status = ?" : '';
    $params       = $statusFilter ? [$statusFilter] : [];

    $pageData['total'] = (int)$db->fetchColumn("SELECT COUNT(*) FROM download_queue q {$where}", $params);
    $pageData['jobs']  = $db->fetchAll(
        "SELECT q.*, u.username, u.first_name
         FROM download_queue q
         LEFT JOIN users u ON q.user_id = u.telegram_id
         {$where}
         ORDER BY q.created_at DESC LIMIT {$perPage} OFFSET {$offset}",
        $params
    );
}

if ($page === 'cache') {
    $pageData['total']   = (int)$db->fetchColumn("SELECT COUNT(*) FROM file_cache");
    $pageData['entries'] = $db->fetchAll(
        "SELECT * FROM file_cache ORDER BY hit_count DESC LIMIT {$perPage} OFFSET {$offset}"
    );
}

if ($page === 'history') {
    $pageData['total']   = (int)$db->fetchColumn("SELECT COUNT(*) FROM download_history");
    $pageData['entries'] = $db->fetchAll(
        "SELECT h.*, u.username FROM download_history h
         LEFT JOIN users u ON h.user_id = u.telegram_id
         ORDER BY h.created_at DESC LIMIT {$perPage} OFFSET {$offset}"
    );
}

if ($page === 'stats') {
    $pageData['daily'] = $db->fetchAll(
        "SELECT * FROM stats_daily ORDER BY date DESC LIMIT 30"
    );
    $pageData['top_platforms'] = $db->fetchAll(
        "SELECT platform, COUNT(*) as count FROM download_history WHERE platform IS NOT NULL
         GROUP BY platform ORDER BY count DESC LIMIT 10"
    );
}

// ─── Helper functions ────────────────────────────────────────────────────────
function formatBytes(int $bytes): string
{
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function statusBadge(string $status): string
{
    $map = [
        'pending'    => ['warning', '⏳'],
        'processing' => ['info',    '⚙️'],
        'completed'  => ['success', '✅'],
        'failed'     => ['danger',  '❌'],
        'cancelled'  => ['secondary','🚫'],
    ];
    [$color, $icon] = $map[$status] ?? ['secondary', '?'];
    return "<span class='badge bg-{$color}'>{$icon} {$status}</span>";
}

function pagination(int $total, int $current, int $perPage, string $baseUrl): string
{
    $totalPages = (int)ceil($total / $perPage);
    if ($totalPages <= 1) return '';

    $html = '<nav><ul class="pagination pagination-sm mb-0">';
    for ($i = 1; $i <= $totalPages; $i++) {
        $active = $i === $current ? 'active' : '';
        $html  .= "<li class='page-item {$active}'><a class='page-link' href='{$baseUrl}&p={$i}'>{$i}</a></li>";
    }
    return $html . '</ul></nav>';
}

ob_start();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MediaBot Admin Panel</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
  :root {
    --mb-primary: #6c63ff;
    --mb-secondary: #2d2d3f;
    --mb-card: #1e1e2e;
    --mb-sidebar: #12121f;
    --mb-border: rgba(255,255,255,0.08);
  }
  body { background: #0d0d1a; font-family: 'Inter', system-ui, sans-serif; min-height: 100vh; }
  .sidebar { background: var(--mb-sidebar); width: 260px; min-height: 100vh; position: fixed; top: 0; left: 0; border-right: 1px solid var(--mb-border); z-index: 100; }
  .sidebar-brand { padding: 1.5rem; font-size: 1.2rem; font-weight: 700; color: var(--mb-primary); border-bottom: 1px solid var(--mb-border); }
  .sidebar .nav-link { color: rgba(255,255,255,0.65); padding: 0.65rem 1.25rem; border-radius: 8px; margin: 2px 8px; transition: all 0.2s; font-size: 0.875rem; }
  .sidebar .nav-link:hover, .sidebar .nav-link.active { background: rgba(108,99,255,0.15); color: #fff; }
  .sidebar .nav-link .fa { width: 22px; }
  .main-content { margin-left: 260px; padding: 2rem; min-height: 100vh; }
  .stat-card { background: var(--mb-card); border: 1px solid var(--mb-border); border-radius: 16px; padding: 1.5rem; transition: transform 0.2s; }
  .stat-card:hover { transform: translateY(-2px); }
  .stat-value { font-size: 2rem; font-weight: 700; line-height: 1; }
  .stat-label { color: rgba(255,255,255,0.5); font-size: 0.8rem; margin-top: 0.25rem; }
  .stat-icon { font-size: 2rem; opacity: 0.6; }
  .card { background: var(--mb-card); border: 1px solid var(--mb-border); border-radius: 16px; }
  .card-header { background: transparent; border-bottom: 1px solid var(--mb-border); padding: 1rem 1.25rem; font-weight: 600; }
  .table { color: rgba(255,255,255,0.85); }
  .table th { color: rgba(255,255,255,0.5); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; }
  .table td, .table th { border-color: var(--mb-border); vertical-align: middle; }
  .badge { font-size: 0.7rem; }
  .topbar { background: var(--mb-card); border-bottom: 1px solid var(--mb-border); padding: 1rem 1.5rem; margin: -2rem -2rem 2rem; display: flex; align-items: center; justify-content: space-between; }
  .maintenance-badge { background: #ff4444; color: #fff; padding: 3px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; animation: pulse 1.5s infinite; }
  @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.6} }
  .progress { background: rgba(255,255,255,0.1); border-radius: 10px; }
  .login-page { display: flex; align-items: center; justify-content: center; min-height: 100vh; background: #0d0d1a; }
  .login-card { width: 100%; max-width: 420px; background: var(--mb-card); border: 1px solid var(--mb-border); border-radius: 20px; padding: 2.5rem; }
  .login-logo { font-size: 3rem; text-align: center; margin-bottom: 1rem; }
  .btn-primary { background: var(--mb-primary); border-color: var(--mb-primary); }
  .btn-primary:hover { background: #5a52e0; border-color: #5a52e0; }
  .refresh-badge { display: inline-block; width: 8px; height: 8px; background: #00c853; border-radius: 50%; animation: pulse 1.5s infinite; margin-right: 6px; }
  pre { background: rgba(0,0,0,0.3); border-radius: 8px; padding: 1rem; font-size: 0.8rem; max-height: 200px; overflow-y: auto; }
</style>
</head>
<body>

<?php if ($page === 'login'): ?>
<div class="login-page">
  <div class="login-card">
    <div class="login-logo">🤖</div>
    <h4 class="text-center fw-bold mb-1">MediaBot Admin</h4>
    <p class="text-center text-muted mb-4 small">Secure Admin Dashboard</p>
    <?php if (!empty($loginError)): ?>
      <div class="alert alert-danger py-2 small">❌ <?= htmlspecialchars($loginError) ?></div>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="action" value="login">
      <div class="mb-3">
        <label class="form-label small">Admin Password</label>
        <input type="password" name="password" class="form-control" placeholder="Enter password" autofocus required>
      </div>
      <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
        <i class="fa fa-lock me-2"></i>Login
      </button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- Sidebar -->
<div class="sidebar">
  <div class="sidebar-brand">
    <i class="fa fa-robot me-2"></i>MediaBot Admin
    <?php if ($maintenance): ?><span class="maintenance-badge ms-2">MAINT</span><?php endif; ?>
  </div>
  <nav class="mt-3">
    <?php
    $navItems = [
        ['dashboard', 'fa-tachometer-alt', 'Dashboard'],
        ['queue',     'fa-list',           'Queue'],
        ['users',     'fa-users',          'Users'],
        ['history',   'fa-history',        'Downloads'],
        ['cache',     'fa-database',       'Cache'],
        ['stats',     'fa-chart-bar',      'Statistics'],
    ];
    foreach ($navItems as [$pg, $icon, $label]): ?>
      <a href="index.php?page=<?= $pg ?>" class="nav-link <?= $page === $pg ? 'active' : '' ?>">
        <i class="fa <?= $icon ?> me-2"></i><?= $label ?>
      </a>
    <?php endforeach; ?>
    <hr class="my-3 border-secondary">
    <form method="post" class="px-3">
      <input type="hidden" name="action" value="toggle_maintenance">
      <button type="submit" class="btn btn-sm w-100 <?= $maintenance ? 'btn-warning' : 'btn-outline-secondary' ?>">
        <i class="fa fa-tools me-1"></i><?= $maintenance ? 'Disable Maintenance' : 'Enable Maintenance' ?>
      </button>
    </form>
    <form method="post" class="px-3 mt-2">
      <input type="hidden" name="action" value="logout">
      <button type="submit" class="btn btn-sm btn-outline-danger w-100">
        <i class="fa fa-sign-out-alt me-1"></i>Logout
      </button>
    </form>
  </nav>
</div>

<!-- Main Content -->
<div class="main-content">
  <!-- Topbar -->
  <div class="topbar">
    <div>
      <h5 class="mb-0 fw-bold"><?= ucfirst($page) ?></h5>
      <small class="text-muted"><?= date('D, d M Y H:i') ?></small>
    </div>
    <div class="d-flex align-items-center gap-3">
      <span class="text-muted small"><span class="refresh-badge"></span>Live</span>
      <a href="index.php?page=<?= $page ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-sync-alt"></i>
      </a>
    </div>
  </div>

  <?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show py-2 small mb-3">
    <?= htmlspecialchars($flash['msg']) ?>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <?php if ($page === 'dashboard'): ?>
  <!-- Stat Cards Row 1 -->
  <div class="row g-3 mb-4">
    <?php
    $cards = [
        ['👥', number_format($stats['users']), 'Total Users', 'text-primary'],
        ['📥', number_format($stats['downloads']), 'Total Downloads', 'text-success'],
        ['⚡', $stats['today_downloads'], 'Downloads Today', 'text-info'],
        ['❌', $stats['failed_today'], 'Failed Today', 'text-danger'],
        ['⏳', $stats['queue_pending'], 'Queue Pending', 'text-warning'],
        ['🗄️', number_format($stats['cache_entries']), 'Cache Entries', 'text-secondary'],
        ['🎯', $cacheHitRate . '%', 'Cache Hit Rate', 'text-success'],
        ['👤', $stats['active_users_day'], 'Active (24h)', 'text-info'],
    ];
    foreach ($cards as [$icon, $val, $label, $color]): ?>
    <div class="col-md-3 col-6">
      <div class="stat-card">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <div class="stat-value <?= $color ?>"><?= $val ?></div>
            <div class="stat-label"><?= $label ?></div>
          </div>
          <div class="stat-icon"><?= $icon ?></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Queue Status & Recent Downloads -->
  <div class="row g-3">
    <div class="col-md-6">
      <div class="card">
        <div class="card-header">⚙️ Queue Status</div>
        <div class="card-body">
          <?php
          $queueItems = [
              ['Pending',    $stats['queue_pending'],    'warning'],
              ['Processing', $stats['queue_processing'], 'info'],
          ];
          foreach ($queueItems as [$label, $val, $color]): ?>
          <div class="d-flex justify-content-between align-items-center mb-3">
            <span class="small"><?= $label ?></span>
            <div class="d-flex align-items-center gap-2">
              <div class="progress flex-grow-1" style="width:120px;height:8px;">
                <div class="progress-bar bg-<?= $color ?>" style="width:<?= min(100, $val) ?>%"></div>
              </div>
              <span class="badge bg-<?= $color ?>"><?= $val ?></span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card">
        <div class="card-header">📊 Cache Performance</div>
        <div class="card-body">
          <div class="text-center">
            <div class="display-4 fw-bold text-success"><?= $cacheHitRate ?>%</div>
            <div class="text-muted small">Cache Hit Rate</div>
          </div>
          <div class="mt-3 small text-muted text-center">
            Total cache hits: <strong><?= number_format($stats['cache_hits']) ?></strong>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Recent Activity -->
  <div class="card mt-3">
    <div class="card-header d-flex justify-content-between">
      <span>🕐 Recent Downloads</span>
      <a href="index.php?page=history" class="btn btn-sm btn-outline-secondary">View All</a>
    </div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr>
          <th>User</th><th>Platform</th><th>Title</th><th>Size</th><th>Cache</th><th>Time</th>
        </tr></thead>
        <tbody>
        <?php
        $recent = $db->fetchAll(
            "SELECT h.*, u.username FROM download_history h LEFT JOIN users u ON h.user_id=u.telegram_id
             ORDER BY h.created_at DESC LIMIT 10"
        );
        foreach ($recent as $r): ?>
          <tr>
            <td><small>@<?= htmlspecialchars($r['username'] ?? $r['user_id']) ?></small></td>
            <td><span class="badge bg-secondary"><?= htmlspecialchars($r['platform'] ?? '-') ?></span></td>
            <td><small><?= htmlspecialchars(mb_substr($r['title'] ?? '-', 0, 40)) ?></small></td>
            <td><small><?= $r['file_size'] ? formatBytes((int)$r['file_size']) : '-' ?></small></td>
            <td><?= $r['from_cache'] ? '<span class="badge bg-success">⚡</span>' : '<span class="badge bg-secondary">fresh</span>' ?></td>
            <td><small class="text-muted"><?= date('H:i', strtotime($r['created_at'])) ?></small></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php elseif ($page === 'users'): ?>
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>👥 Users (<?= number_format($pageData['total']) ?>)</span>
      <form class="d-flex gap-2" method="get">
        <input type="hidden" name="page" value="users">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
        <button class="btn btn-sm btn-primary">Search</button>
      </form>
    </div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr>
          <th>ID</th><th>Username</th><th>Name</th><th>Language</th><th>Downloads</th><th>Status</th><th>Joined</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($pageData['users'] as $u): ?>
          <tr>
            <td><code><?= $u['telegram_id'] ?></code></td>
            <td><?= $u['username'] ? '@'.htmlspecialchars($u['username']) : '<em class="text-muted">—</em>' ?></td>
            <td><?= htmlspecialchars(trim($u['first_name'].' '.$u['last_name'])) ?></td>
            <td><span class="badge bg-secondary"><?= $u['lang_pref'] ?></span></td>
            <td><?= number_format($u['total_downloads']) ?></td>
            <td><?= $u['is_banned'] ? '<span class="badge bg-danger">Banned</span>' : '<span class="badge bg-success">Active</span>' ?></td>
            <td><small class="text-muted"><?= date('Y-m-d', strtotime($u['created_at'])) ?></small></td>
            <td>
              <form method="post" class="d-inline">
                <input type="hidden" name="user_id" value="<?= $u['telegram_id'] ?>">
                <?php if ($u['is_banned']): ?>
                  <input type="hidden" name="action" value="unban_user">
                  <button class="btn btn-success btn-sm py-0">Unban</button>
                <?php else: ?>
                  <input type="hidden" name="action" value="ban_user">
                  <input type="hidden" name="reason" value="Admin ban">
                  <button class="btn btn-danger btn-sm py-0">Ban</button>
                <?php endif; ?>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
      <small class="text-muted">Showing <?= count($pageData['users']) ?> of <?= number_format($pageData['total']) ?></small>
      <?= pagination($pageData['total'], $p, $perPage, 'index.php?page=users&q='.urlencode($_GET['q'] ?? '')) ?>
    </div>
  </div>

  <?php elseif ($page === 'queue'): ?>
  <div class="d-flex justify-content-between mb-3">
    <div class="d-flex gap-2">
      <?php foreach (['', 'pending', 'processing', 'completed', 'failed'] as $s): ?>
        <a href="index.php?page=queue<?= $s ? '&status='.$s : '' ?>" class="btn btn-sm <?= ($_GET['status'] ?? '') === $s ? 'btn-primary' : 'btn-outline-secondary' ?>">
          <?= $s ?: 'All' ?>
        </a>
      <?php endforeach; ?>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="cleanup_queue">
      <button class="btn btn-sm btn-outline-danger">🧹 Cleanup Old</button>
    </form>
  </div>
  <div class="card">
    <div class="card-header">🔄 Download Queue (<?= number_format($pageData['total']) ?>)</div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr>
          <th>ID</th><th>User</th><th>Platform</th><th>Quality</th><th>Format</th><th>Status</th><th>Progress</th><th>Retry</th><th>Created</th>
        </tr></thead>
        <tbody>
        <?php foreach ($pageData['jobs'] as $j): ?>
          <tr>
            <td><code>#<?= $j['id'] ?></code></td>
            <td><small>@<?= htmlspecialchars($j['username'] ?? $j['user_id']) ?></small></td>
            <td><span class="badge bg-secondary"><?= htmlspecialchars($j['platform'] ?? '-') ?></span></td>
            <td><?= $j['quality'] ?></td>
            <td><span class="badge bg-info"><?= $j['format'] ?></span></td>
            <td><?= statusBadge($j['status']) ?></td>
            <td>
              <div class="progress" style="width:80px;height:6px;">
                <div class="progress-bar bg-primary" style="width:<?= $j['progress'] ?>%"></div>
              </div>
              <small><?= $j['progress'] ?>%</small>
            </td>
            <td><?= $j['retry_count'] ?>/<?= $config['queue']['retry_attempts'] ?></td>
            <td><small class="text-muted"><?= date('H:i:s', strtotime($j['created_at'])) ?></small></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer">
      <?= pagination($pageData['total'], $p, $perPage, 'index.php?page=queue&status='.urlencode($_GET['status'] ?? '')) ?>
    </div>
  </div>

  <?php elseif ($page === 'cache'): ?>
  <div class="d-flex justify-content-end mb-3">
    <form method="post">
      <input type="hidden" name="action" value="cleanup_cache">
      <button class="btn btn-sm btn-outline-warning">🧹 Cleanup Expired</button>
    </form>
  </div>
  <div class="card">
    <div class="card-header">⚡ File Cache (<?= number_format($pageData['total']) ?> entries)</div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr>
          <th>Title</th><th>Platform</th><th>Type</th><th>Quality</th><th>Size</th><th>Hits</th><th>Expires</th>
        </tr></thead>
        <tbody>
        <?php foreach ($pageData['entries'] as $c): ?>
          <tr>
            <td><small><?= htmlspecialchars(mb_substr($c['title'] ?? '-', 0, 40)) ?></small></td>
            <td><span class="badge bg-secondary"><?= htmlspecialchars($c['platform'] ?? '-') ?></span></td>
            <td><span class="badge bg-info"><?= $c['file_type'] ?></span></td>
            <td><?= $c['quality'] ?></td>
            <td><small><?= $c['file_size'] ? formatBytes((int)$c['file_size']) : '-' ?></small></td>
            <td><span class="badge bg-success"><?= number_format($c['hit_count']) ?></span></td>
            <td><small class="text-muted"><?= date('m-d H:i', strtotime($c['expires_at'])) ?></small></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer">
      <?= pagination($pageData['total'], $p, $perPage, 'index.php?page=cache') ?>
    </div>
  </div>

  <?php elseif ($page === 'stats'): ?>
  <div class="row g-3">
    <div class="col-md-8">
      <div class="card">
        <div class="card-header">📈 Daily Download Stats (Last 30 days)</div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead><tr>
                <th>Date</th><th>New Users</th><th>Downloads</th><th>Cache Hits</th><th>Failed</th>
              </tr></thead>
              <tbody>
              <?php foreach ($pageData['daily'] as $d): ?>
                <tr>
                  <td><?= $d['date'] ?></td>
                  <td><?= number_format($d['new_users']) ?></td>
                  <td><span class="text-success"><?= number_format($d['total_downloads']) ?></span></td>
                  <td><?= number_format($d['cache_hits']) ?></td>
                  <td><span class="text-danger"><?= number_format($d['failed_downloads']) ?></span></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card">
        <div class="card-header">🌐 Top Platforms</div>
        <div class="card-body">
          <?php foreach ($pageData['top_platforms'] as $tp): ?>
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span><?= htmlspecialchars($tp['platform']) ?></span>
            <span class="badge bg-primary"><?= number_format($tp['count']) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <?php elseif ($page === 'history'): ?>
  <div class="card">
    <div class="card-header">📋 Download History (<?= number_format($pageData['total']) ?>)</div>
    <div class="table-responsive">
      <table class="table table-hover mb-0 table-sm">
        <thead><tr>
          <th>User</th><th>Platform</th><th>Title</th><th>Type</th><th>Size</th><th>Cache</th><th>Time</th><th>Date</th>
        </tr></thead>
        <tbody>
        <?php foreach ($pageData['entries'] as $h): ?>
          <tr>
            <td><small>@<?= htmlspecialchars($h['username'] ?? $h['user_id']) ?></small></td>
            <td><span class="badge bg-secondary"><?= htmlspecialchars($h['platform'] ?? '-') ?></span></td>
            <td><small><?= htmlspecialchars(mb_substr($h['title'] ?? '-', 0, 40)) ?></small></td>
            <td><span class="badge bg-info"><?= $h['file_type'] ?? '-' ?></span></td>
            <td><small><?= $h['file_size'] ? formatBytes((int)$h['file_size']) : '-' ?></small></td>
            <td><?= $h['from_cache'] ? '⚡' : '🔄' ?></td>
            <td><small><?= $h['download_time'] ? round((float)$h['download_time'], 1).'s' : '-' ?></small></td>
            <td><small class="text-muted"><?= date('m-d H:i', strtotime($h['created_at'])) ?></small></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer">
      <?= pagination($pageData['total'], $p, $perPage, 'index.php?page=history') ?>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /main-content -->
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
<script>
// Auto-refresh dashboard every 30 seconds
<?php if ($page === 'dashboard' || $page === 'queue'): ?>
setTimeout(() => location.reload(), 30000);
<?php endif; ?>
</script>
</body>
</html>
<?php
echo ob_get_clean();
