<?php
declare(strict_types=1);
session_start();

const APP_NAME = 'Projarium';
const APP_EMAIL = 'projarium@quantumnet.space';
const APP_REPLY_TO = 'mariuserbanica@proton.me';
const APP_PACKAGE = 'com.myname.projarium';
const APP_RELEASE_ID = '20260812-initial-projarium';
const APP_RELEASE_DATE = '12/08/2026';
const INITIAL_APP_VERSION = '1.0';

$DB_HOST = getenv('PROJARIUM_DB_HOST') ?: '127.0.0.1';
$DB_NAME = getenv('PROJARIUM_DB_NAME') ?: 'projarium';
$DB_USER = getenv('PROJARIUM_DB_USER') ?: 'projarium';
$DB_PASS = getenv('PROJARIUM_DB_PASS') ?: '';
$DB_CHARSET = 'utf8mb4';

main();

function main(): void
{
    init_app();
    $action = (string)($_GET['a'] ?? 'dashboard');
    $routes = [
        'dashboard' => 'dashboard',
        'login' => 'login',
        'register' => 'register',
        'logout' => 'logout',
        'verify' => 'verify_email',
        'verify-pending' => 'verify_pending',
        'forgot-password' => 'forgot_password',
        'reset-password' => 'reset_password',
        'captcha' => 'captcha_image',
        'project-new' => 'project_form',
        'project-edit' => 'project_edit',
        'project-delete' => 'project_delete',
        'profile' => 'profile',
        'enable-2fa' => 'enable_2fa',
        'disable-2fa' => 'disable_2fa',
        'twofactor-login' => 'twofactor_login',
        'mobile-biometric-login' => 'mobile_biometric_login',
        'mobile-unlock' => 'mobile_unlock',
        'admin' => 'admin_dashboard',
        'admin-users' => 'admin_users',
        'admin-activity' => 'admin_activity',
        'admin-settings' => 'admin_settings',
    ];
    if (!isset($routes[$action])) $action = 'dashboard';
    $routes[$action]();
}

function db(): PDO
{
    static $pdo;
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS, $DB_CHARSET;
    if (!$pdo) {
        $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";
        $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function init_app(): void
{
    $db = db();
    $db->exec("CREATE TABLE IF NOT EXISTS projarium_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(80) NOT NULL UNIQUE,
        email VARCHAR(190) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(16) NOT NULL DEFAULT 'user',
        status VARCHAR(16) NOT NULL DEFAULT 'active',
        email_verified_at DATETIME NULL,
        pending_email VARCHAR(190) NULL,
        twofa_secret VARCHAR(64) NULL,
        twofa_enabled TINYINT NOT NULL DEFAULT 0,
        mobile_biometric_enabled TINYINT NOT NULL DEFAULT 0,
        notify_updates TINYINT NOT NULL DEFAULT 1,
        theme VARCHAR(16) NOT NULL DEFAULT 'dark',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS projarium_projects (
        id VARCHAR(64) PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        description TEXT NULL,
        type VARCHAR(255) NULL,
        added_date VARCHAR(64) NULL,
        start_date VARCHAR(64) NULL,
        end_date VARCHAR(64) NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'Pending',
        priority VARCHAR(16) NOT NULL DEFAULT 'normal',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_projarium_projects_user (user_id),
        CONSTRAINT fk_projarium_projects_user FOREIGN KEY (user_id) REFERENCES projarium_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS projarium_settings (
        name VARCHAR(80) PRIMARY KEY,
        value TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS projarium_email_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(64) NOT NULL,
        purpose VARCHAR(32) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_projarium_email_tokens_lookup (token, purpose),
        CONSTRAINT fk_projarium_email_tokens_user FOREIGN KEY (user_id) REFERENCES projarium_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS projarium_login_attempts (
        attempt_key VARCHAR(64) PRIMARY KEY,
        failed_count INT NOT NULL DEFAULT 0,
        last_failed_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS projarium_mobile_unlock_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        selector VARCHAR(64) NOT NULL UNIQUE,
        validator_hash VARCHAR(64) NOT NULL,
        device_label VARCHAR(190) NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        last_used_at DATETIME NULL,
        INDEX idx_projarium_mobile_user (user_id),
        CONSTRAINT fk_projarium_mobile_user FOREIGN KEY (user_id) REFERENCES projarium_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS projarium_trusted_2fa_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        selector VARCHAR(64) NOT NULL UNIQUE,
        validator_hash VARCHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        last_used_at DATETIME NULL,
        INDEX idx_projarium_trusted_2fa_user (user_id, expires_at),
        CONSTRAINT fk_projarium_trusted_2fa_user FOREIGN KEY (user_id) REFERENCES projarium_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS projarium_activity (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        username VARCHAR(80) NULL,
        action VARCHAR(80) NOT NULL,
        details TEXT NULL,
        ip_address VARCHAR(64) NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_projarium_activity_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    seed_settings();
    seed_admin_and_projects();
    sync_release();
}

function seed_settings(): void
{
    $defaults = [
        'site_url' => 'https://projarium.quantumnet.space',
        'allow_registration' => '1',
        'require_email_verification' => '1',
        'site_default_theme' => 'dark',
        'mail_from_email' => APP_EMAIL,
        'mail_from_name' => APP_NAME,
        'mail_reply_to' => APP_REPLY_TO,
        'mail_envelope_sender' => APP_EMAIL,
        'app_version' => INITIAL_APP_VERSION,
        'last_patched' => APP_RELEASE_DATE,
        'app_release_id' => APP_RELEASE_ID,
    ];
    $stmt = db()->prepare('INSERT IGNORE INTO projarium_settings (name,value) VALUES (?,?)');
    foreach ($defaults as $name => $value) $stmt->execute([$name, $value]);
}

function seed_admin_and_projects(): void
{
    $count = (int)db()->query('SELECT COUNT(*) FROM projarium_users')->fetchColumn();
    if ($count > 0) return;
    $now = now();
    $initialPassword = getenv('PROJARIUM_INITIAL_ADMIN_PASSWORD');
    if (!$initialPassword) return;
    $hash = password_hash($initialPassword, PASSWORD_DEFAULT);
    db()->prepare('INSERT INTO projarium_users (username,email,password_hash,role,status,email_verified_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute(['marius', APP_REPLY_TO, $hash, 'admin', 'active', $now, $now, $now]);
    $userId = (int)db()->lastInsertId();
    try {
        $stmt = db()->query('SELECT id,name,description,type,added_date,start_date,end_date,status,created_at FROM projects');
        $ins = db()->prepare('INSERT IGNORE INTO projarium_projects (id,user_id,name,description,type,added_date,start_date,end_date,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($stmt as $p) {
            $created = $p['created_at'] ?: $now;
            $ins->execute([$p['id'], $userId, $p['name'], $p['description'], $p['type'], $p['added_date'], $p['start_date'], $p['end_date'], normalize_status($p['status']), $created, $now]);
        }
    } catch (Throwable $e) {
        $json = __DIR__ . '/data/projects.json';
        if (is_file($json)) {
            $items = json_decode((string)file_get_contents($json), true) ?: [];
            $ins = db()->prepare('INSERT IGNORE INTO projarium_projects (id,user_id,name,description,type,added_date,start_date,end_date,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($items as $p) {
                $ins->execute([$p['id'] ?? uniqid('p_', true), $userId, $p['name'] ?? 'Untitled', $p['description'] ?? '', $p['type'] ?? '', $p['added_date'] ?? '', $p['start_date'] ?? '', $p['end_date'] ?? '', normalize_status($p['status'] ?? 'Pending'), $now, $now]);
            }
        }
    }
    log_activity(['id' => $userId, 'username' => 'marius'], 'account.created', 'Initial Projarium admin and imported projects');
}

function sync_release(): void
{
    if (setting('app_release_id') === APP_RELEASE_ID) return;
    $version = next_app_version(setting('app_version', INITIAL_APP_VERSION));
    set_setting('app_version', $version);
    set_setting('last_patched', APP_RELEASE_DATE);
    set_setting('app_release_id', APP_RELEASE_ID);
}

function next_app_version(string $version): string
{
    if (!preg_match('/^(\d+)\.(\d+)$/', $version, $m)) return INITIAL_APP_VERSION;
    $major = (int)$m[1]; $minor = (int)$m[2];
    return $minor >= 50 ? ($major + 1) . '.0' : $major . '.' . ($minor + 1);
}

function setting(string $name, ?string $fallback = null): string
{
    $stmt = db()->prepare('SELECT value FROM projarium_settings WHERE name=?');
    $stmt->execute([$name]);
    $value = $stmt->fetchColumn();
    return $value === false || $value === null ? (string)$fallback : (string)$value;
}

function set_setting(string $name, string $value): void
{
    db()->prepare('INSERT INTO projarium_settings (name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute([$name, $value]);
}

function now(): string { return gmdate('Y-m-d H:i:s'); }
function h(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function redirect(string $url): never { header('Location: ' . $url); exit; }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function check_csrf(): void { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { flash('Your session expired. Please try again.', 'error'); redirect('?'); } }
function flash(?string $message = null, string $type = 'ok'): ?string
{
    if ($message !== null) { $_SESSION['flash'] = ['message' => $message, 'type' => $type]; return null; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
    return $f ? '<div class="flash ' . h($f['type']) . '">' . h($f['message']) . '</div>' : null;
}

function current_user(): ?array
{
    $id = (int)($_SESSION['user_id'] ?? 0);
    if (!$id) return null;
    $stmt = db()->prepare('SELECT * FROM projarium_users WHERE id=? AND status="active"');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) redirect('?a=login');
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if (($u['role'] ?? '') !== 'admin') { http_response_code(403); exit('Admin only.'); }
    return $u;
}

function layout(string $title, string $content): void
{
    $u = current_user();
    $theme = $u['theme'] ?? setting('site_default_theme', 'dark');
    $mobileLock = $u && (int)($u['mobile_biometric_enabled'] ?? 0) === 1 ? ' data-mobile-session-lock data-mobile-lock-csrf="' . h(csrf()) . '"' : '';
    $admin = $u && $u['role'] === 'admin' ? '<a href="?a=admin">Admin</a>' : '';
    $menu = $u
        ? '<details class="user-menu"><summary>' . h($u['username']) . '</summary><div><a href="?a=profile">Preferences</a>' . $admin . '<button type="button" data-version-log-open>Version</button><a href="?a=logout">Sign out</a></div></details>'
        : '<a href="?a=login">Sign in</a><a class="btn primary" href="?a=register">Register</a>';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>' . h($title) . ' - ' . APP_NAME . '</title><link rel="icon" href="assets/icon.png"><link rel="stylesheet" href="assets/style.css?v=20260812-initial"></head><body data-theme="' . h($theme) . '"' . $mobileLock . '><div class="app-shell"><header class="topbar"><a class="brand" href="?"><img src="assets/icon.png" alt=""><span><strong>Projarium</strong><small>Project workspace</small></span></a><nav><a href="?">Projects</a>' . ($u ? '<a href="?a=project-new">New project</a>' : '') . $menu . '</nav></header><main class="wrap">' . (flash() ?? '') . $content . '</main><footer class="footer"><button class="footer-brand" type="button" data-version-log-open><img src="assets/icon.png" alt=""><span>&copy; 2026 Projarium v' . h(setting('app_version', INITIAL_APP_VERSION)) . '</span></button><p><a href="mailto:' . h(APP_REPLY_TO) . '">' . h(APP_REPLY_TO) . '</a> &middot; <a href="https://mariusserbanica.co.uk/" target="_blank" rel="noopener noreferrer">mariusserbanica.co.uk</a></p></footer></div>' . changelog_html() . '<script src="assets/app.js?v=20260812-initial"></script></body></html>';
}

function changelog_html(): string
{
    return '<dialog class="version-dialog" data-version-log><div class="version-shell"><header><div><p>Projarium</p><h2>Release History</h2></div><button type="button" data-version-log-close>&times;</button></header><article><h3>v1.0</h3><time>12/08/2026</time><h4>Added</h4><ul><li>Renamed the project tracker to Projarium with the new icon and mobile-friendly layout.</li><li>Added user accounts with isolated project workspaces, registration, email confirmation, password recovery, CAPTCHA, 2FA, and admin controls.</li><li>Added mobile fingerprint unlock hooks, version panels, and update prompts for the Android app.</li></ul></article></div></dialog>';
}

function status_options(): array
{
    return ['Pending' => 'Pending', 'In-Progress' => 'In-Progress', 'Researching' => 'Researching', 'Working' => 'Working', 'Done' => 'Done', 'Scrapped' => 'Scrapped'];
}
function normalize_status(string $status): string { return status_options()[$status] ?? ($status === 'In Progress' ? 'In-Progress' : 'Pending'); }
function status_key(string $status): string { return strtolower(str_replace(['-', ' '], '', normalize_status($status))); }
function select_options(array $options, string $selected): string { $html=''; foreach ($options as $value=>$label) $html .= '<option value="' . h((string)$value) . '" ' . ((string)$value===$selected?'selected':'') . '>' . h((string)$label) . '</option>'; return $html; }

function dashboard(): void
{
    $u = require_login();
    $q = trim((string)($_GET['q'] ?? ''));
    $status = (string)($_GET['status'] ?? 'All');
    $args = [$u['id']];
    $where = ['user_id=?'];
    if ($q !== '') { $where[] = '(name LIKE ? OR description LIKE ? OR type LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
    if ($status !== 'All' && isset(status_options()[$status])) { $where[] = 'status=?'; $args[] = $status; }
    $stmt = db()->prepare('SELECT * FROM projarium_projects WHERE ' . implode(' AND ', $where) . ' ORDER BY FIELD(status,"In-Progress","Working","Researching","Pending","Done","Scrapped"), updated_at DESC');
    $stmt->execute($args);
    $projects = $stmt->fetchAll();
    $all = projects_for_stats((int)$u['id']);
    $counts = array_fill_keys(array_keys(status_options()), 0);
    $types = [];
    foreach ($all as $p) { $counts[normalize_status($p['status'])]++; $types[$p['type'] ?: 'Unspecified'] = ($types[$p['type'] ?: 'Unspecified'] ?? 0) + 1; }
    arsort($types);
    $stats = '<section class="stats"><div><span>Total</span><strong>' . count($all) . '</strong></div>';
    foreach ($counts as $name => $count) $stats .= '<div><span>' . h($name) . '</span><strong>' . (int)$count . '</strong></div>';
    $stats .= '</section>';
    $filters = '<form class="filters" method="get"><input name="q" placeholder="Search projects" value="' . h($q) . '"><select name="status"><option>All</option>' . select_options(status_options(), $status) . '</select><button>Filter</button><a class="btn secondary" href="?">Clear</a><a class="btn primary" href="?a=project-new">New project</a></form>';
    $cards = '';
    foreach ($projects as $p) $cards .= project_card($p);
    if ($cards === '') $cards = '<div class="empty">No projects match this view.</div>';
    $typeBars = '';
    foreach (array_slice($types, 0, 8, true) as $type => $count) {
        $pct = count($all) ? max(4, round($count / count($all) * 100)) : 0;
        $typeBars .= '<div class="bar-row"><span>' . h($type) . '</span><strong>' . (int)$count . '</strong><em style="width:' . $pct . '%"></em></div>';
    }
    layout('Projects', '<section class="hero"><div><p>Private project database</p><h1>Your Projarium</h1><span class="muted">Track projects, statuses, dates, and work streams in your own account workspace.</span></div><img src="assets/icon.png" alt=""></section>' . $stats . '<section class="panel">' . $filters . '<div class="project-grid">' . $cards . '</div></section><section class="panel"><h2>Projects by Type</h2><div class="bars">' . $typeBars . '</div></section>');
}

function projects_for_stats(int $userId): array
{
    $stmt = db()->prepare('SELECT * FROM projarium_projects WHERE user_id=?');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function project_card(array $p): string
{
    $status = normalize_status((string)$p['status']);
    $dates = array_filter([$p['added_date'] ? 'Added ' . $p['added_date'] : '', $p['start_date'] ? 'Start ' . $p['start_date'] : '', $p['end_date'] ? 'End ' . $p['end_date'] : '']);
    return '<article class="project-card status-' . h(status_key($status)) . '"><div class="project-card-head"><span class="status-pill">' . h($status) . '</span><span class="priority">' . h($p['priority']) . '</span></div><h2>' . h($p['name']) . '</h2><p>' . h($p['description']) . '</p><div class="project-meta"><span>' . h($p['type'] ?: 'Unspecified') . '</span><span>' . h(implode(' | ', $dates)) . '</span></div><div class="btn-row"><a class="btn secondary" href="?a=project-edit&id=' . h($p['id']) . '">Edit</a><a class="btn danger" data-confirm="Delete this project?" href="?a=project-delete&id=' . h($p['id']) . '&csrf=' . h(csrf()) . '">Delete</a></div></article>';
}

function project_form(array $project = []): void
{
    $u = require_login();
    $editing = isset($project['id']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') { flash('Project name is required.', 'error'); redirect($_SERVER['REQUEST_URI']); }
        $payload = [
            'id' => $editing ? $project['id'] : uniqid('p_', true),
            'user_id' => $u['id'],
            'name' => $name,
            'description' => trim((string)($_POST['description'] ?? '')),
            'type' => trim((string)($_POST['type'] ?? '')),
            'added_date' => trim((string)($_POST['added_date'] ?? date('d/m/Y'))),
            'start_date' => trim((string)($_POST['start_date'] ?? '')),
            'end_date' => trim((string)($_POST['end_date'] ?? '')),
            'status' => normalize_status((string)($_POST['status'] ?? 'Pending')),
            'priority' => in_array($_POST['priority'] ?? 'normal', ['low','normal','high'], true) ? $_POST['priority'] : 'normal',
        ];
        if ($editing) {
            db()->prepare('UPDATE projarium_projects SET name=?,description=?,type=?,added_date=?,start_date=?,end_date=?,status=?,priority=?,updated_at=? WHERE id=? AND user_id=?')
                ->execute([$payload['name'],$payload['description'],$payload['type'],$payload['added_date'],$payload['start_date'],$payload['end_date'],$payload['status'],$payload['priority'],now(),$project['id'],$u['id']]);
            log_activity($u, 'project.updated', $payload['name']);
            flash('Project updated.');
        } else {
            db()->prepare('INSERT INTO projarium_projects (id,user_id,name,description,type,added_date,start_date,end_date,status,priority,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$payload['id'],$u['id'],$payload['name'],$payload['description'],$payload['type'],$payload['added_date'],$payload['start_date'],$payload['end_date'],$payload['status'],$payload['priority'],now(),now()]);
            log_activity($u, 'project.created', $payload['name']);
            flash('Project created.');
        }
        redirect('?');
    }
    $p = array_merge(['name'=>'','description'=>'','type'=>'','added_date'=>date('d/m/Y'),'start_date'=>'','end_date'=>'','status'=>'Pending','priority'=>'normal'], $project);
    $html = '<section class="panel narrow"><h1>' . ($editing ? 'Edit Project' : 'New Project') . '</h1><form method="post" class="form-grid"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><div class="inline-grid"><div><label>Name</label><input name="name" value="' . h($p['name']) . '" required></div><div><label>Type</label><input name="type" value="' . h($p['type']) . '"></div></div><div><label>Description</label><textarea name="description" rows="5">' . h($p['description']) . '</textarea></div><div class="inline-grid"><div><label>Added date</label><input name="added_date" value="' . h($p['added_date']) . '"></div><div><label>Start date</label><input name="start_date" value="' . h($p['start_date']) . '"></div><div><label>End date</label><input name="end_date" value="' . h($p['end_date']) . '"></div></div><div class="inline-grid"><div><label>Status</label><select name="status">' . select_options(status_options(), $p['status']) . '</select></div><div><label>Priority</label><select name="priority">' . select_options(['low'=>'Low','normal'=>'Normal','high'=>'High'], $p['priority']) . '</select></div></div><div class="btn-row"><button>Save project</button><a class="btn secondary" href="?">Cancel</a></div></form></section>';
    layout($editing ? 'Edit project' : 'New project', $html);
}

function project_edit(): void
{
    $u = require_login();
    $stmt = db()->prepare('SELECT * FROM projarium_projects WHERE id=? AND user_id=?');
    $stmt->execute([(string)($_GET['id'] ?? ''), $u['id']]);
    $p = $stmt->fetch();
    if (!$p) { flash('Project not found.', 'error'); redirect('?'); }
    project_form($p);
}

function project_delete(): void
{
    $u = require_login();
    if (!hash_equals($_SESSION['csrf'] ?? '', $_GET['csrf'] ?? '')) { flash('Your session expired.', 'error'); redirect('?'); }
    $stmt = db()->prepare('SELECT * FROM projarium_projects WHERE id=? AND user_id=?');
    $stmt->execute([(string)($_GET['id'] ?? ''), $u['id']]);
    $p = $stmt->fetch();
    if ($p) {
        db()->prepare('DELETE FROM projarium_projects WHERE id=? AND user_id=?')->execute([$p['id'], $u['id']]);
        log_activity($u, 'project.deleted', (string)$p['name']);
    }
    flash('Project deleted.');
    redirect('?');
}

function auth_form(string $heading, string $action, string $error = '', bool $register = false, ?string $captcha = null): string
{
    $identity = $register
        ? '<div><label>Username</label><input name="username" required maxlength="80"></div><div><label>Email</label><input type="email" name="email" required></div>'
        : '<div><label>Email or username</label><input name="login" required></div>';
    $extra = $register ? password_help() : '';
    $captchaHtml = $captcha ? captcha_field($captcha) : '';
    $links = $register
        ? '<p class="auth-links">Already registered? <a href="?a=login">Sign in</a><br><span>Check your spam folder for emails from Projarium.</span></p>'
        : '<p class="auth-links">New here? <a href="?a=register">Register</a><br><span>Check your spam folder for emails from Projarium.</span><br><a href="?a=forgot-password">Forgot password?</a></p>';
    $mobileUnlock = (!$register && has_mobile_unlock_token()) ? '<div class="mobile-biometric-login" data-mobile-trusted-unlock hidden><button class="secondary biometric-button" type="button" data-mobile-unlock data-csrf="' . h(csrf()) . '">Unlock with fingerprint</button><p class="muted" data-mobile-unlock-status></p></div>' : '';
    return '<section class="auth-layout"><div class="auth-art"><img src="assets/icon.png" alt=""><h1>Projarium</h1><p>Your private self-hosted project database.</p></div><div class="panel auth-panel"><h1>' . h($heading) . '</h1>' . ($error ? '<div class="flash error">' . h($error) . '</div>' : '') . '<form method="post" action="' . h($action) . '" class="form-grid" data-auth-form><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="is_mobile_app" value="0" data-mobile-app-field>' . $identity . '<div><label>Password</label><input type="password" name="password" minlength="10" required ' . ($register ? 'data-password-check' : '') . '>' . $extra . '</div>' . $captchaHtml . '<button>' . h($heading) . '</button></form>' . $mobileUnlock . $links . '</div></section>';
}

function login(): void
{
    if (current_user()) redirect('?');
    $error = '';
    $login = trim((string)($_POST['login'] ?? ''));
    $captchaRequired = $login !== '' && login_failure_count($login) >= 2;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        if ($captchaRequired && !verify_captcha('login')) $error = 'Verification code is incorrect or expired.';
        else {
            $stmt = db()->prepare('SELECT * FROM projarium_users WHERE (email=? OR username=?) AND status="active"');
            $stmt->execute([strtolower($login), $login]);
            $u = $stmt->fetch();
            if (!$u || !password_verify((string)($_POST['password'] ?? ''), $u['password_hash'])) {
                $captchaRequired = record_login_failure($login) >= 2;
                $error = $captchaRequired ? 'Invalid login details. A verification code is required now.' : 'Invalid login details.';
            } elseif (!$u['email_verified_at']) {
                $_SESSION['pending_verification_email'] = $u['email'];
                redirect('?a=verify-pending');
            } elseif ((int)$u['twofa_enabled'] === 1 && !trusted_2fa_valid($u)) {
                $_SESSION['pending_2fa_user_id'] = (int)$u['id'];
                $_SESSION['pending_mobile_app_login'] = is_mobile_app_request() ? 1 : 0;
                redirect('?a=twofactor-login');
            } else {
                clear_login_failures($login);
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$u['id'];
                if (is_mobile_app_request()) issue_mobile_unlock_token($u);
                log_activity($u, 'account.login');
                redirect('?');
            }
        }
    }
    layout('Sign in', auth_form('Sign In', '?a=login', $error, false, $captchaRequired ? 'login' : null));
}

function register(): void
{
    if (setting('allow_registration', '1') !== '1') { layout('Registration closed', '<section class="panel narrow"><h1>Registration Closed</h1><p class="muted">New accounts are not being accepted right now.</p></section>'); return; }
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $username = trim((string)($_POST['username'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        if (!verify_captcha('register')) $error = 'Verification code is incorrect or expired.';
        elseif ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Use a valid username and email.';
        elseif (!strong_password($password)) $error = 'Use a stronger password before registering.';
        else {
            try {
                $verified = setting('require_email_verification', '1') === '1' ? null : now();
                db()->prepare('INSERT INTO projarium_users (username,email,password_hash,role,status,email_verified_at,theme,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$username,$email,password_hash($password,PASSWORD_DEFAULT),'user','active',$verified,setting('site_default_theme','dark'),now(),now()]);
                $id = (int)db()->lastInsertId();
                log_activity(['id'=>$id,'username'=>$username], 'account.registered');
                if ($verified) { $_SESSION['user_id'] = $id; redirect('?'); }
                $_SESSION['pending_verification_email'] = $email;
                $_SESSION['verification_mail_message'] = send_verification($id, $email, $username) ? 'A verification email has been sent.' : 'Account created, but email sending failed. Use resend.';
                redirect('?a=verify-pending');
            } catch (Throwable $e) { $error = 'That username or email is already registered.'; }
        }
    }
    layout('Register', auth_form('Register', '?a=register', $error, true, 'register'));
}

function logout(): void
{
    if ($u = current_user()) log_activity($u, 'account.logout');
    clear_mobile_unlock_cookie();
    session_destroy();
    redirect('?a=login');
}

function send_verification(int $userId, string $email, string $username): bool
{
    $token = bin2hex(random_bytes(32));
    db()->prepare('DELETE FROM projarium_email_tokens WHERE user_id=? AND purpose="verify"')->execute([$userId]);
    db()->prepare('INSERT INTO projarium_email_tokens (user_id,token,purpose,expires_at,created_at) VALUES (?,?,?,?,?)')->execute([$userId, hash('sha256',$token), 'verify', gmdate('Y-m-d H:i:s', time()+86400), now()]);
    $link = app_url('a=verify&email=' . rawurlencode($email) . '&token=' . urlencode($token));
    return send_mail($email, 'Confirm your Projarium email', "Hello {$username},\n\nConfirm your Projarium account:\n\n{$link}\n\nThis link expires in 24 hours.\n\nCheck your spam folder for emails from Projarium.\n\nProjarium");
}

function verify_email(): void
{
    $email = strtolower(trim((string)($_GET['email'] ?? ''))); $token = (string)($_GET['token'] ?? '');
    $stmt = db()->prepare('SELECT t.* FROM projarium_email_tokens t JOIN projarium_users u ON u.id=t.user_id WHERE u.email=? AND t.token=? AND t.purpose="verify" AND t.expires_at>?');
    $stmt->execute([$email, strlen($token) === 64 ? hash('sha256',$token) : '', now()]);
    $row = $stmt->fetch();
    if (!$row) { flash('Verification link is invalid or expired.', 'error'); redirect('?a=login'); }
    db()->prepare('UPDATE projarium_users SET email_verified_at=?,updated_at=? WHERE id=?')->execute([now(), now(), $row['user_id']]);
    db()->prepare('DELETE FROM projarium_email_tokens WHERE user_id=? AND purpose="verify"')->execute([$row['user_id']]);
    $_SESSION['user_id'] = (int)$row['user_id'];
    flash('Email confirmed. Welcome to Projarium.');
    redirect('?');
}

function verify_pending(): void
{
    $email = (string)($_SESSION['pending_verification_email'] ?? '');
    if ($email === '') redirect('?a=login');
    $message = (string)($_SESSION['verification_mail_message'] ?? ''); unset($_SESSION['verification_mail_message']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $stmt = db()->prepare('SELECT * FROM projarium_users WHERE email=?'); $stmt->execute([$email]); $u = $stmt->fetch();
        if ($u) $message = send_verification((int)$u['id'], $u['email'], $u['username']) ? 'A new verification email has been sent.' : 'The mail server could not send the message.';
    }
    layout('Confirm email', '<section class="panel narrow"><h1>Confirm Your Email</h1><p class="muted">We sent a verification link to <strong>' . h($email) . '</strong>. It expires in 24 hours.</p><p class="muted">Please check your spam folder for emails from Projarium.</p>' . ($message ? '<div class="flash ok">' . h($message) . '</div>' : '') . '<form method="post"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><button>Resend confirmation email</button></form></section>');
}

function forgot_password(): void
{
    $message = '<p class="muted">Enter your confirmed account email. Check your spam folder for emails from Projarium.</p>';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $stmt = db()->prepare('SELECT * FROM projarium_users WHERE email=? AND status="active" AND email_verified_at IS NOT NULL');
        $stmt->execute([$email]); $u = $stmt->fetch();
        if ($u) send_password_reset($u);
        $message = '<div class="flash ok">If that email exists, a password reset link has been sent. Check your spam folder for emails from Projarium.</div>';
    }
    layout('Password recovery', '<section class="panel narrow"><h1>Password Recovery</h1>' . $message . '<form method="post" class="form-grid"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><div><label>Email</label><input type="email" name="email" required></div><button>Send reset link</button></form></section>');
}

function send_password_reset(array $u): bool
{
    $token = bin2hex(random_bytes(32));
    db()->prepare('DELETE FROM projarium_email_tokens WHERE user_id=? AND purpose="reset"')->execute([$u['id']]);
    db()->prepare('INSERT INTO projarium_email_tokens (user_id,token,purpose,expires_at,created_at) VALUES (?,?,?,?,?)')->execute([$u['id'], hash('sha256',$token), 'reset', gmdate('Y-m-d H:i:s', time()+3600), now()]);
    $link = app_url('a=reset-password&email=' . rawurlencode($u['email']) . '&token=' . urlencode($token));
    return send_mail($u['email'], 'Reset your Projarium password', "Hello {$u['username']},\n\nReset your Projarium password here:\n\n{$link}\n\nThis link expires in one hour.\n\nProjarium");
}

function reset_password(): void
{
    $email = strtolower(trim((string)($_GET['email'] ?? ''))); $token = (string)($_GET['token'] ?? '');
    $stmt = db()->prepare('SELECT t.*,u.username,u.email FROM projarium_email_tokens t JOIN projarium_users u ON u.id=t.user_id WHERE u.email=? AND t.token=? AND t.purpose="reset" AND t.expires_at>?');
    $stmt->execute([$email, strlen($token) === 64 ? hash('sha256',$token) : '', now()]);
    $row = $stmt->fetch();
    if (!$row) { layout('Reset password', '<section class="panel narrow"><h1>Reset Password</h1><div class="flash error">This reset link is invalid or expired.</div></section>'); return; }
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $password = (string)($_POST['password'] ?? '');
        if (!strong_password($password)) $error = 'Use a stronger password.';
        else {
            db()->prepare('UPDATE projarium_users SET password_hash=?,updated_at=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT), now(), $row['user_id']]);
            db()->prepare('DELETE FROM projarium_email_tokens WHERE user_id=? AND purpose="reset"')->execute([$row['user_id']]);
            revoke_trusted_2fa_tokens((int)$row['user_id']);
            log_activity(['id'=>(int)$row['user_id'],'username'=>$row['username']], 'account.password_reset');
            flash('Password updated. You can sign in now.');
            redirect('?a=login');
        }
    }
    layout('Reset password', '<section class="panel narrow"><h1>Reset Password</h1>' . ($error ? '<div class="flash error">' . h($error) . '</div>' : '') . '<form method="post" class="form-grid"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><div><label>New password</label><input type="password" name="password" data-password-check required>' . password_help() . '</div><button>Update password</button></form></section>');
}

function profile(): void
{
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $action = (string)($_POST['profile_action'] ?? 'prefs');
        if ($action === 'prefs') {
            db()->prepare('UPDATE projarium_users SET theme=?,notify_updates=?,updated_at=? WHERE id=?')->execute([($_POST['theme'] ?? 'dark') === 'light' ? 'light' : 'dark', !empty($_POST['notify_updates']) ? 1 : 0, now(), $u['id']]);
            flash('Preferences saved.');
        } elseif ($action === 'password') {
            if (!password_verify((string)($_POST['current_password'] ?? ''), $u['password_hash'])) flash('Current password is incorrect.', 'error');
            elseif (!strong_password((string)($_POST['new_password'] ?? ''))) flash('Use a stronger new password.', 'error');
            else { db()->prepare('UPDATE projarium_users SET password_hash=?,updated_at=? WHERE id=?')->execute([password_hash((string)$_POST['new_password'],PASSWORD_DEFAULT), now(), $u['id']]); revoke_trusted_2fa_tokens((int)$u['id']); flash('Password updated.'); }
        } elseif ($action === 'mobile') {
            if (!password_verify((string)($_POST['current_password'] ?? ''), $u['password_hash'])) flash('Current password is required.', 'error');
            else { db()->prepare('UPDATE projarium_users SET mobile_biometric_enabled=?,updated_at=? WHERE id=?')->execute([!empty($_POST['mobile_biometric_enabled']) ? 1 : 0, now(), $u['id']]); flash('Mobile fingerprint setting saved.'); }
        }
        redirect('?a=profile');
    }
    $twofa = (int)$u['twofa_enabled'] === 1 ? '<span class="pill">Enabled</span><form method="post" action="?a=disable-2fa"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><button class="danger">Disable 2FA</button></form>' : '<span class="pill">Disabled</span><a class="btn" href="?a=enable-2fa">Enable 2FA</a>';
    $html = '<section class="panel narrow"><h1>Preferences</h1><p class="muted">' . h($u['email']) . '</p><form method="post" class="form-grid"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="profile_action" value="prefs"><label>Theme<select name="theme">' . select_options(['dark'=>'Dark','light'=>'Light'], $u['theme']) . '</select></label><label class="check"><input type="checkbox" name="notify_updates" value="1" ' . ((int)$u['notify_updates'] ? 'checked' : '') . '> Notify me about new app versions</label><button>Save preferences</button></form><hr><h2>Two-Factor Authentication</h2><div class="btn-row">' . $twofa . '</div><hr><h2>Mobile Fingerprint</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="profile_action" value="mobile"><label class="check"><input type="checkbox" name="mobile_biometric_enabled" value="1" ' . ((int)$u['mobile_biometric_enabled'] ? 'checked' : '') . '> Allow fingerprint/device unlock in the Android app</label><label>Current password<input type="password" name="current_password" required></label><button>Save fingerprint setting</button></form><hr><h2>Change Password</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="profile_action" value="password"><label>Current password<input type="password" name="current_password" required></label><label>New password<input type="password" name="new_password" data-password-check required>' . password_help() . '</label><button>Update password</button></form></section>';
    layout('Profile', $html);
}

function enable_2fa(): void
{
    $u = require_login();
    $secret = $_SESSION['new_2fa_secret'] ??= base32_secret();
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        if (verify_totp($secret, (string)($_POST['code'] ?? ''))) {
            db()->prepare('UPDATE projarium_users SET twofa_secret=?,twofa_enabled=1,updated_at=? WHERE id=?')->execute([$secret, now(), $u['id']]);
            unset($_SESSION['new_2fa_secret']);
            flash('2FA enabled.');
            redirect('?a=profile');
        }
        $error = 'Invalid code.';
    }
    $otpauth = 'otpauth://totp/' . rawurlencode(APP_NAME . ':' . $u['email']) . '?secret=' . rawurlencode($secret) . '&issuer=' . rawurlencode(APP_NAME) . '&algorithm=SHA1&digits=6&period=30';
    $qr = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=' . urlencode($otpauth);
    layout('Enable 2FA', '<section class="panel narrow"><h1>Enable 2FA</h1>' . ($error ? '<div class="flash error">' . h($error) . '</div>' : '') . '<p class="muted">Scan this QR code, then enter a 6-digit authenticator code.</p><img class="qr" src="' . h($qr) . '" alt="2FA QR code"><form method="post" class="form-grid"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><label>Authenticator code<input name="code" inputmode="numeric" required></label><button>Enable 2FA</button></form><p class="muted">Manual secret: <code>' . h($secret) . '</code></p></section>');
}

function disable_2fa(): void { check_csrf(); $u = require_login(); db()->prepare('UPDATE projarium_users SET twofa_secret=NULL,twofa_enabled=0 WHERE id=?')->execute([$u['id']]); revoke_trusted_2fa_tokens((int)$u['id']); flash('2FA disabled.'); redirect('?a=profile'); }

function twofactor_login(): void
{
    if (empty($_SESSION['pending_2fa_user_id'])) redirect('?a=login');
    $stmt = db()->prepare('SELECT * FROM projarium_users WHERE id=?'); $stmt->execute([$_SESSION['pending_2fa_user_id']]); $u = $stmt->fetch();
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        if ($u && verify_totp($u['twofa_secret'], (string)($_POST['code'] ?? ''))) {
            $_SESSION['user_id'] = (int)$u['id'];
            unset($_SESSION['pending_2fa_user_id']);
            issue_trusted_2fa_token($u);
            if (!empty($_SESSION['pending_mobile_app_login']) || is_mobile_app_request()) issue_mobile_unlock_token($u);
            unset($_SESSION['pending_mobile_app_login']);
            redirect('?');
        }
        $error = 'Invalid authentication code.';
    }
    $bio = $u && (int)$u['mobile_biometric_enabled'] === 1 ? '<div class="mobile-biometric-login" data-mobile-biometric hidden><button class="secondary biometric-button" type="button" data-biometric-login data-csrf="' . h(csrf()) . '">Use fingerprint instead</button><p class="muted" data-biometric-status></p></div>' : '';
    layout('2FA', '<section class="panel narrow"><h1>Two-Factor Authentication</h1>' . ($error ? '<div class="flash error">' . h($error) . '</div>' : '') . '<form method="post" class="form-grid" data-auth-form><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="is_mobile_app" value="0" data-mobile-app-field><label>6-digit code<input name="code" inputmode="numeric" required autofocus></label><button>Verify</button></form>' . $bio . '</section>');
}

function admin_dashboard(): void
{
    require_admin();
    $counts = [
        'users' => db()->query('SELECT COUNT(*) FROM projarium_users')->fetchColumn(),
        'projects' => db()->query('SELECT COUNT(*) FROM projarium_projects')->fetchColumn(),
    ];
    layout('Admin', '<section class="panel narrow"><h1>Admin Panel</h1><div class="stats compact"><div><span>Users</span><strong>' . (int)$counts['users'] . '</strong></div><div><span>Projects</span><strong>' . (int)$counts['projects'] . '</strong></div></div><div class="btn-row"><a class="btn" href="?a=admin-users">Users</a><a class="btn secondary" href="?a=admin-activity">Activity</a><a class="btn secondary" href="?a=admin-settings">Settings</a></div></section>');
}

function admin_users(): void
{
    $admin = require_admin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$admin['id']) { flash('You cannot edit your own admin lock here.', 'error'); redirect('?a=admin-users'); }
        if (($_POST['admin_action'] ?? '') === 'delete') db()->prepare('DELETE FROM projarium_users WHERE id=?')->execute([$id]);
        else db()->prepare('UPDATE projarium_users SET role=?,status=?,email_verified_at=IF(?=1,COALESCE(email_verified_at,?),NULL),updated_at=? WHERE id=?')->execute([($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user', ($_POST['status'] ?? 'active') === 'disabled' ? 'disabled' : 'active', !empty($_POST['verified']) ? 1 : 0, now(), now(), $id]);
        flash('User updated.');
        redirect('?a=admin-users');
    }
    $rows = '';
    foreach (db()->query('SELECT u.*, (SELECT COUNT(*) FROM projarium_projects p WHERE p.user_id=u.id) project_count FROM projarium_users u ORDER BY created_at DESC') as $u) {
        $self = (int)$u['id'] === (int)$admin['id'];
        $rows .= '<article class="admin-user"><form method="post" class="form-grid"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="id" value="' . (int)$u['id'] . '"><h2>' . h($u['username']) . '</h2><p class="muted">' . h($u['email']) . ' | ' . (int)$u['project_count'] . ' projects | 2FA ' . ((int)$u['twofa_enabled'] ? 'on' : 'off') . '</p><div class="inline-grid"><label>Role<select name="role" ' . ($self?'disabled':'') . '>' . select_options(['user'=>'User','admin'=>'Admin'], $u['role']) . '</select></label><label>Status<select name="status" ' . ($self?'disabled':'') . '>' . select_options(['active'=>'Active','disabled'=>'Disabled'], $u['status']) . '</select></label></div><label class="check"><input type="checkbox" name="verified" value="1" ' . ($u['email_verified_at']?'checked':'') . ' ' . ($self?'disabled':'') . '> Email verified</label><div class="btn-row"><button ' . ($self?'disabled':'') . '>Save</button><button class="danger" name="admin_action" value="delete" data-confirm="Delete this user and all projects?" ' . ($self?'disabled':'') . '>Delete</button></div></form></article>';
    }
    layout('Users', '<section class="panel"><div class="page-title"><h1>Registered Users</h1><a class="btn secondary" href="?a=admin">Admin</a></div><div class="admin-list">' . $rows . '</div></section>');
}

function admin_activity(): void
{
    require_admin();
    $rows = '';
    foreach (db()->query('SELECT * FROM projarium_activity ORDER BY created_at DESC LIMIT 200') as $r) $rows .= '<tr><td>' . h($r['created_at']) . '</td><td>' . h($r['username']) . '</td><td>' . h($r['action']) . '</td><td>' . h($r['details']) . '</td><td>' . h($r['ip_address']) . '</td></tr>';
    layout('Activity', '<section class="panel"><div class="page-title"><h1>User Activity</h1><a class="btn secondary" href="?a=admin">Admin</a></div><div class="table-wrap"><table><tr><th>Date</th><th>User</th><th>Action</th><th>Details</th><th>IP</th></tr>' . ($rows ?: '<tr><td colspan="5">No activity yet.</td></tr>') . '</table></div></section>');
}

function admin_settings(): void
{
    require_admin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        foreach (['site_url','mail_from_email','mail_from_name','mail_reply_to','mail_envelope_sender'] as $key) set_setting($key, trim((string)($_POST[$key] ?? '')));
        set_setting('allow_registration', !empty($_POST['allow_registration']) ? '1' : '0');
        set_setting('require_email_verification', !empty($_POST['require_email_verification']) ? '1' : '0');
        set_setting('site_default_theme', ($_POST['site_default_theme'] ?? 'dark') === 'light' ? 'light' : 'dark');
        flash('Settings saved.');
        redirect('?a=admin-settings');
    }
    layout('Settings', '<section class="panel narrow"><h1>Settings</h1><form method="post" class="form-grid"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><label class="check"><input type="checkbox" name="allow_registration" value="1" ' . (setting('allow_registration')==='1'?'checked':'') . '> Allow registration</label><label class="check"><input type="checkbox" name="require_email_verification" value="1" ' . (setting('require_email_verification')==='1'?'checked':'') . '> Require email confirmation</label><label>Default theme<select name="site_default_theme">' . select_options(['dark'=>'Dark','light'=>'Light'], setting('site_default_theme','dark')) . '</select></label><label>Site URL<input name="site_url" value="' . h(setting('site_url')) . '"></label><label>From email<input name="mail_from_email" value="' . h(setting('mail_from_email')) . '"></label><label>Envelope sender<input name="mail_envelope_sender" value="' . h(setting('mail_envelope_sender')) . '"></label><label>From name<input name="mail_from_name" value="' . h(setting('mail_from_name')) . '"></label><label>Reply-to<input name="mail_reply_to" value="' . h(setting('mail_reply_to')) . '"></label><button>Save settings</button></form></section>');
}

function send_mail(string $to, string $subject, string $body): bool
{
    $headers = ['From: ' . setting('mail_from_name', APP_NAME) . ' <' . setting('mail_from_email', APP_EMAIL) . '>', 'Reply-To: ' . setting('mail_reply_to', APP_REPLY_TO), 'Content-Type: text/plain; charset=UTF-8', 'X-Mailer: ' . APP_NAME];
    return @mail($to, $subject, $body, implode("\r\n", $headers), '-f' . setting('mail_envelope_sender', APP_EMAIL));
}

function app_url(string $query = ''): string { return rtrim(setting('site_url','https://projarium.quantumnet.space'), '/') . '/' . ($query ? '?' . ltrim($query, '?') : ''); }

function password_help(): string { return '<p class="password-help" data-password-help>Use at least 10 characters with uppercase, lowercase, number, and symbol.</p>'; }
function strong_password(string $p): bool { return strlen($p) >= 10 && preg_match('/[a-z]/',$p) && preg_match('/[A-Z]/',$p) && preg_match('/\d/',$p) && preg_match('/[^A-Za-z0-9]/',$p); }

function captcha_code(): string { $a='ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $s=''; for($i=0;$i<6;$i++) $s.=$a[random_int(0,strlen($a)-1)]; return $s; }
function issue_captcha(string $purpose): string { $token=bin2hex(random_bytes(16)); $_SESSION['captchas'][$token]=['code'=>captcha_code(),'purpose'=>$purpose,'expires'=>time()+600]; return $token; }
function verify_captcha(string $purpose): bool { $token=(string)($_POST['captcha_token']??''); $answer=strtoupper(preg_replace('/[^A-Z0-9]/i','',(string)($_POST['captcha_code']??''))); $c=$_SESSION['captchas'][$token]??null; unset($_SESSION['captchas'][$token]); return is_array($c)&&$c['purpose']===$purpose&&(int)$c['expires']>=time()&&hash_equals($c['code'],$answer); }
function captcha_field(string $purpose): string { $token=issue_captcha($purpose); return '<div class="captcha-field"><label>Verification code</label><div><img src="?a=captcha&token=' . h($token) . '" data-captcha-image data-captcha-source="?a=captcha&token=' . h($token) . '" alt="Verification code"><button class="secondary" type="button" data-captcha-refresh>&#8635;</button></div><input type="hidden" name="captcha_token" value="' . h($token) . '"><input name="captcha_code" maxlength="6" required placeholder="Enter code"></div>'; }
function captcha_image(): never { $token=(string)($_GET['token']??''); $c=$_SESSION['captchas'][$token]??null; if(!is_array($c)){http_response_code(404);exit;} if(isset($_GET['refresh'])){$_SESSION['captchas'][$token]['code']=captcha_code();$c=$_SESSION['captchas'][$token];} header('Content-Type:image/svg+xml'); echo '<svg xmlns="http://www.w3.org/2000/svg" width="220" height="70"><rect width="220" height="70" rx="8" fill="#edf7ff"/><text x="28" y="46" font-size="30" font-family="monospace" font-weight="800" fill="#101827" letter-spacing="8">' . h($c['code']) . '</text></svg>'; exit; }

function login_attempt_key(string $login): string { return hash('sha256', strtolower(trim($login))); }
function login_failure_count(string $login): int { if($login==='') return 0; $st=db()->prepare('SELECT failed_count,last_failed_at FROM projarium_login_attempts WHERE attempt_key=?'); $st->execute([login_attempt_key($login)]); $r=$st->fetch(); if(!$r) return 0; if($r['last_failed_at'] < gmdate('Y-m-d H:i:s', time()-1800)){db()->prepare('DELETE FROM projarium_login_attempts WHERE attempt_key=?')->execute([login_attempt_key($login)]);return 0;} return (int)$r['failed_count']; }
function record_login_failure(string $login): int { db()->prepare('INSERT INTO projarium_login_attempts (attempt_key,failed_count,last_failed_at) VALUES (?,1,?) ON DUPLICATE KEY UPDATE failed_count=failed_count+1,last_failed_at=VALUES(last_failed_at)')->execute([login_attempt_key($login), now()]); return login_failure_count($login); }
function clear_login_failures(string $login): void { db()->prepare('DELETE FROM projarium_login_attempts WHERE attempt_key=?')->execute([login_attempt_key($login)]); }

function base32_secret(int $length=32): string { $a='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $s=''; for($i=0;$i<$length;$i++) $s.=$a[random_int(0,strlen($a)-1)]; return $s; }
function base32_decode_secret(string $secret): string { $secret=strtoupper(preg_replace('/[^A-Z2-7]/','',$secret)); $a='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits=''; foreach(str_split($secret) as $c){$pos=strpos($a,$c); if($pos!==false)$bits.=str_pad(decbin($pos),5,'0',STR_PAD_LEFT);} $out=''; foreach(str_split($bits,8) as $b) if(strlen($b)===8)$out.=chr(bindec($b)); return $out; }
function totp(string $secret, ?int $slice=null): string { $slice ??=(int)floor(time()/30); $hash=hash_hmac('sha1', pack('N*',0,$slice), base32_decode_secret($secret), true); $off=ord(substr($hash,-1))&0x0f; $v=unpack('N',substr($hash,$off,4))[1]&0x7fffffff; return str_pad((string)($v%1000000),6,'0',STR_PAD_LEFT); }
function verify_totp(?string $secret, string $code): bool { if(!$secret) return false; $code=preg_replace('/\D/','',$code); $slice=(int)floor(time()/30); foreach([-1,0,1] as $w) if(hash_equals(totp($secret,$slice+$w),$code)) return true; return false; }

function is_mobile_app_request(): bool { return !empty($_POST['is_mobile_app']) || !empty($_GET['mobile_app']) || str_contains((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), APP_PACKAGE); }
function mobile_unlock_cookie_options(int $expires): array { return ['expires'=>$expires,'path'=>'/','secure'=>false,'httponly'=>true,'samesite'=>'Lax']; }
function set_mobile_unlock_cookie(string $selector, string $validator, int $expires): void { setcookie('projarium_mobile_unlock', $selector . ':' . $validator, mobile_unlock_cookie_options($expires)); }
function clear_mobile_unlock_cookie(): void { setcookie('projarium_mobile_unlock', '', mobile_unlock_cookie_options(time()-3600)); }
function secure_request(): bool { $forwardedProto=strtolower(trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))[0])); return (!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||$forwardedProto==='https'||str_contains((string)($_SERVER['HTTP_CF_VISITOR']??''),'"scheme":"https"'); }
function trusted_2fa_cookie_options(int $expires): array { return ['expires'=>$expires,'path'=>'/','secure'=>secure_request(),'httponly'=>true,'samesite'=>'Lax']; }
function clear_trusted_2fa_cookie(): void { setcookie('projarium_trusted_2fa','',trusted_2fa_cookie_options(time()-3600)); }
function issue_trusted_2fa_token(array $u): void { $selector=bin2hex(random_bytes(9)); $validator=bin2hex(random_bytes(32)); $expires=time()+60*60*24*21; db()->prepare('INSERT INTO projarium_trusted_2fa_tokens (user_id,selector,validator_hash,expires_at,created_at) VALUES (?,?,?,?,?)')->execute([(int)$u['id'],hash('sha256',$selector),hash('sha256',$validator),gmdate('Y-m-d H:i:s',$expires),now()]); db()->prepare('DELETE FROM projarium_trusted_2fa_tokens WHERE user_id=? AND id NOT IN (SELECT id FROM (SELECT id FROM projarium_trusted_2fa_tokens WHERE user_id=? ORDER BY created_at DESC LIMIT 5) keepers)')->execute([(int)$u['id'],(int)$u['id']]); setcookie('projarium_trusted_2fa',$selector.':'.$validator,trusted_2fa_cookie_options($expires)); }
function trusted_2fa_valid(array $u): bool { $cookie=(string)($_COOKIE['projarium_trusted_2fa']??''); if(!str_contains($cookie,':')) return false; [$selector,$validator]=explode(':',$cookie,2); if(!preg_match('/^[a-f0-9]{18}$/',$selector)||!preg_match('/^[a-f0-9]{64}$/',$validator)) return false; db()->prepare('DELETE FROM projarium_trusted_2fa_tokens WHERE expires_at<=?')->execute([now()]); $st=db()->prepare('SELECT * FROM projarium_trusted_2fa_tokens WHERE selector=? AND expires_at>?'); $st->execute([hash('sha256',$selector),now()]); $token=$st->fetch(); if(!$token||(int)$token['user_id']!==(int)$u['id']||!hash_equals((string)$token['validator_hash'],hash('sha256',$validator))){clear_trusted_2fa_cookie(); return false;} db()->prepare('UPDATE projarium_trusted_2fa_tokens SET last_used_at=? WHERE id=?')->execute([now(),$token['id']]); return true; }
function revoke_trusted_2fa_tokens(int $userId): void { db()->prepare('DELETE FROM projarium_trusted_2fa_tokens WHERE user_id=?')->execute([$userId]); clear_trusted_2fa_cookie(); }
function issue_mobile_unlock_token(array $u): void { if((int)$u['mobile_biometric_enabled']!==1) db()->prepare('UPDATE projarium_users SET mobile_biometric_enabled=1 WHERE id=?')->execute([$u['id']]); $selector=bin2hex(random_bytes(9)); $validator=bin2hex(random_bytes(32)); $expires=time()+2592000; db()->prepare('INSERT INTO projarium_mobile_unlock_tokens (user_id,selector,validator_hash,device_label,expires_at,created_at) VALUES (?,?,?,?,?,?)')->execute([$u['id'],hash('sha256',$selector),hash('sha256',$validator),substr((string)($_SERVER['HTTP_USER_AGENT'] ?? APP_PACKAGE),0,180),gmdate('Y-m-d H:i:s',$expires),now()]); set_mobile_unlock_cookie($selector,$validator,$expires); }
function mobile_unlock_user(): ?array { $cookie=(string)($_COOKIE['projarium_mobile_unlock']??''); if(!str_contains($cookie,':')) return null; [$selector,$validator]=explode(':',$cookie,2); $st=db()->prepare('SELECT u.*,t.selector unlock_selector,t.validator_hash unlock_validator_hash FROM projarium_mobile_unlock_tokens t JOIN projarium_users u ON u.id=t.user_id WHERE t.selector=? AND t.expires_at>? AND u.status="active" AND u.email_verified_at IS NOT NULL'); $st->execute([hash('sha256',$selector),now()]); $u=$st->fetch(); if(!$u || !hash_equals($u['unlock_validator_hash'],hash('sha256',$validator)) || (int)$u['mobile_biometric_enabled']!==1){clear_mobile_unlock_cookie();return null;} return $u; }
function has_mobile_unlock_token(): bool { return mobile_unlock_user() !== null; }
function mobile_unlock(): void { header('Content-Type:application/json'); if($_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals($_SESSION['csrf']??'',$_POST['csrf']??'')){http_response_code(419);echo json_encode(['ok'=>false,'message'=>'Session expired.']);return;} $u=mobile_unlock_user(); if(!$u){http_response_code(403);echo json_encode(['ok'=>false,'message'=>'Sign in once with your password.']);return;} $_SESSION['user_id']=(int)$u['id']; db()->prepare('UPDATE projarium_mobile_unlock_tokens SET last_used_at=? WHERE selector=?')->execute([now(),$u['unlock_selector']]); echo json_encode(['ok'=>true,'redirect'=>'?']); }
function mobile_biometric_login(): void { header('Content-Type:application/json'); if($_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals($_SESSION['csrf']??'',$_POST['csrf']??'')){http_response_code(419);echo json_encode(['ok'=>false]);return;} $id=(int)($_SESSION['pending_2fa_user_id']??0); $st=db()->prepare('SELECT * FROM projarium_users WHERE id=?'); $st->execute([$id]); $u=$st->fetch(); if(!$u || (int)$u['twofa_enabled']!==1 || (int)$u['mobile_biometric_enabled']!==1){http_response_code(403);echo json_encode(['ok'=>false,'message'=>'Fingerprint login is unavailable.']);return;} $_SESSION['user_id']=(int)$u['id']; unset($_SESSION['pending_2fa_user_id']); issue_mobile_unlock_token($u); echo json_encode(['ok'=>true,'redirect'=>'?']); }

function log_activity(?array $u, string $action, string $details = ''): void
{
    db()->prepare('INSERT INTO projarium_activity (user_id,username,action,details,ip_address,created_at) VALUES (?,?,?,?,?,?)')->execute([$u['id'] ?? null, $u['username'] ?? null, $action, $details, $_SERVER['REMOTE_ADDR'] ?? null, now()]);
}
