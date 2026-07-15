<?php
/**
 * Activate a phpBB style declared by PHPBB_DEFAULT_STYLE.
 *
 * Reads the style name (e.g. "digi") and a parent style (defaults to
 * "prosilver") from environment variables, registers the style in
 * phpbb_styles if missing, then sets it as the board default and
 * forces override_user_style so anonymous visitors actually use it
 * (otherwise phpBB falls back to each user's user_style — which is
 * 1=prosilver for every user in a fresh install).
 *
 * The bbcode_bitfield is inherited from the parent. The style_copyright
 * is read from the style's own style.cfg so we don't hardcode it.
 *
 * Idempotent: safe to run on every container start. Runs as root,
 * connects to the DB over the same mysqli driver phpBB itself uses.
 *
 * Required env vars: PHPBB_DB_HOST, PHPBB_DB_PORT, PHPBB_DB_USER,
 *                    PHPBB_DB_PASSWORD, PHPBB_DB_NAME.
 * Optional env vars: PHPBB_DEFAULT_STYLE (this script no-ops if empty).
 */

if (getenv('PHPBB_DEFAULT_STYLE') === false || getenv('PHPBB_DEFAULT_STYLE') === '') {
    exit(0);
}

$style_name  = getenv('PHPBB_DEFAULT_STYLE');
$parent_name = getenv('PHPBB_DEFAULT_STYLE_PARENT') ?: 'prosilver';
$style_path  = $style_name; // convention: path matches dir name

$dbhost    = getenv('PHPBB_DB_HOST')     ?: 'db';
$dbport    = getenv('PHPBB_DB_PORT')     ?: '3306';
$dbuser    = getenv('PHPBB_DB_USER')     ?: 'phpbb';
$dbpass    = getenv('PHPBB_DB_PASSWORD') ?: '';
$dbname    = getenv('PHPBB_DB_NAME')     ?: 'phpbb';
$table_prefix = getenv('PHPBB_TABLE_PREFIX') ?: 'phpbb_';

$mysqli = new mysqli($dbhost, $dbuser, $dbpass, $dbname, (int) $dbport);
if ($mysqli->connect_errno) {
    fwrite(STDERR, "[activate-style] DB connection failed: {$mysqli->connect_error}\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');

$styles_tbl    = $table_prefix . 'styles';
$config_tbl    = $table_prefix . 'config';
$users_tbl     = $table_prefix . 'users';

// 1) Read style_copyright from the style's own style.cfg
$style_cfg = "/var/www/html/styles/{$style_path}/style.cfg";
$copyright = '';
if (is_readable($style_cfg)) {
    foreach (file($style_cfg, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^\s*copyright\s*=\s*(.+?)\s*$/', $line, $m)) {
            $copyright = $m[1];
            break;
        }
    }
}
if ($copyright === '') {
    $copyright = $style_name;
}

// 2) Look up parent style_id (for style_parent_id)
$parent_id = 0;
$parent_bbc = "\\x2f\\x2f\\x67\\x3d\\x00"; // prosilver default: "//g=" + null
if ($stmt = $mysqli->prepare("SELECT style_id, bbcode_bitfield FROM {$styles_tbl} WHERE style_name = ? OR style_path = ? LIMIT 1")) {
    $stmt->bind_param('ss', $parent_name, $parent_name);
    $stmt->execute();
    $stmt->bind_result($pid, $pbbc);
    if ($stmt->fetch()) {
        $parent_id = (int) $pid;
        $parent_bbc = $pbbc;
    }
    $stmt->close();
}

// 3) Look up the style's own id (if already registered) or insert it
$style_id = 0;
if ($stmt = $mysqli->prepare("SELECT style_id FROM {$styles_tbl} WHERE style_path = ? LIMIT 1")) {
    $stmt->bind_param('s', $style_path);
    $stmt->execute();
    $stmt->bind_result($sid);
    if ($stmt->fetch()) {
        $style_id = (int) $sid;
    }
    $stmt->close();
}

if ($style_id === 0) {
    // parent_tree is a JSON-ish text column in phpBB: comma-separated style ids
    $parent_tree = $parent_id > 0 ? '["' . $parent_id . '"]' : '';
    $sql = "INSERT INTO {$styles_tbl}
        (style_name, style_copyright, style_active, style_path,
         bbcode_bitfield, style_parent_id, style_parent_tree)
        VALUES (?, ?, 1, ?, ?, ?, ?)";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param('ssssis', $style_name, $copyright, $style_path, $parent_bbc, $parent_id, $parent_tree);
        if (!$stmt->execute()) {
            fwrite(STDERR, "[activate-style] INSERT failed: {$stmt->error}\n");
            $stmt->close();
            $mysqli->close();
            exit(1);
        }
        $style_id = $stmt->insert_id;
        $stmt->close();
    } else {
        fwrite(STDERR, "[activate-style] INSERT prepare failed: {$mysqli->error}\n");
        $mysqli->close();
        exit(1);
    }
    echo "[activate-style] Registered style '{$style_name}' as id={$style_id}\n";
} else {
    echo "[activate-style] Style '{$style_name}' already registered (id={$style_id})\n";
}

// 4) Set default_style and override_user_style
$updates = [
    ['default_style',        (string) $style_id],
    ['override_user_style',  '1'],
];
$sql = "UPDATE {$config_tbl} SET config_value = ? WHERE config_name = ?";
foreach ($updates as [$name, $value]) {
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param('ss', $value, $name);
        $stmt->execute();
        $stmt->close();
    }
}

// 5) Update all users so they use the new default (until they pick one)
$sql = "UPDATE {$users_tbl} SET user_style = ? WHERE user_style = ?";
if ($stmt = $mysqli->prepare($sql)) {
    $old = 1; // prosilver — what the installer set
    $stmt->bind_param('ii', $style_id, $old);
    $stmt->execute();
    $stmt->close();
}

echo "[activate-style] Activated '{$style_name}' (id={$style_id}) as default; override_user_style=1\n";
$mysqli->close();
exit(0);
