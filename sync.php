<?php
/**
 * SNAT NAS Sync API v2.0
 * ─────────────────────────────────────────────────────────────
 * Installation : copiez ce fichier dans le dossier Web Station
 *   ex: /volume1/web/snat/sync.php
 *   puis accédez via https://votre-nas/snat/sync.php
 *
 * Configuration :
 *   1. Changez API_KEY ci-dessous (chaîne secrète de votre choix)
 *   2. Notez l'URL complète : https://votre-nas/snat/sync.php
 *   3. Saisissez cette URL + clé dans les deux applications
 *
 * Protocole (compatible S Chantier TP + Dashboard) :
 *   GET  ?doc=nom_doc            → lit un document
 *   POST ?doc=nom_doc + body JSON → écrit un document
 *   GET  (sans ?doc)             → statut général
 *
 * Réponse GET  : {"ok":true,"exists":bool,"data":{...},"modified":ts}
 * Réponse POST : {"ok":true}
 */

// ── CONFIGURATION ─────────────────────────────────────────────
// Changez cette valeur : clé API secrète partagée entre les apps
define('API_KEY',  'SNAT_SYNC_2024');   // ← à personnaliser !

define('DATA_DIR', __DIR__ . '/data/');
define('LOG_FILE', __DIR__ . '/sync.log');
define('MAX_SIZE', 30 * 1024 * 1024);   // 30 Mo max par document

// Documents autorisés (whitelist de sécurité)
$ALLOWED = [
    'test_ping',
    'dashboard', 'chantier_tp',
    'messages',  'pointages',
    'avancement','avancement_dashboard',
    'roles',     'users', 'users_dashboard',
    'aleas',     'journal',
    'reclamations',
    'TB_GANTT',  'TB_GANTT_NAMES',
    'TB_EQ',     'TB_PT',
    'TB_ZONES',  'TB_SEC', 'TB_PERMIS_CFG',
    'TB_VH',     'TB_DB',
    'TB_ABS',    'TB_EVAL',
];
// Préfixes autorisés pour les docs dynamiques (ex: dashboard_msgs_123)
$ALLOWED_PREFIXES = ['dashboard_msgs_', 'dashboard_pj_'];

// ── CORS ──────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-Device-Id');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── AUTH ──────────────────────────────────────────────────────
$clientKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['k'] ?? '';
if (API_KEY !== '' && $clientKey !== API_KEY) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Non autorisé — clé API incorrecte', 'code' => 401]);
    exit;
}

// ── HELPERS ───────────────────────────────────────────────────
function jsonOut($d) { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

function isAllowed($doc) {
    global $ALLOWED, $ALLOWED_PREFIXES;
    if (in_array($doc, $ALLOWED)) return true;
    foreach ($ALLOWED_PREFIXES as $p) {
        if (strpos($doc, $p) === 0 && strlen($doc) < 80) return true;
    }
    return false;
}

function logOp($op, $doc, $size = 0) {
    $ip  = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '?';
    $dev = substr($_SERVER['HTTP_X_DEVICE_ID'] ?? 'inconnu', 0, 32);
    $ts  = date('Y-m-d H:i:s');
    @file_put_contents(LOG_FILE, "$ts | $op | $doc | $ip | $dev | {$size}b\n", FILE_APPEND | LOCK_EX);
}

// ── STATUT (GET sans ?doc) ────────────────────────────────────
$doc = isset($_GET['doc']) ? preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['doc']) : null;

if ($doc === null || $doc === '') {
    if (!is_dir(DATA_DIR)) { jsonOut(['ok' => true, 'tables' => [], 'server_time' => time(), 'server_ts' => date('c'), 'version' => '2.0']); }
    $tables = [];
    foreach (glob(DATA_DIR . '*.json') ?: [] as $f) {
        $k = basename($f, '.json');
        $tables[$k] = ['size' => filesize($f), 'modified' => filemtime($f), 'ts' => date('c', filemtime($f))];
    }
    jsonOut(['ok' => true, 'tables' => $tables, 'server_time' => time(), 'server_ts' => date('c'), 'version' => '2.0']);
}

// ── SÉCURITÉ ─────────────────────────────────────────────────
if (!isAllowed($doc)) {
    http_response_code(403);
    jsonOut(['ok' => false, 'error' => "Document '$doc' non autorisé"]);
}

// Créer le répertoire de données si absent
if (!is_dir(DATA_DIR)) { mkdir(DATA_DIR, 0750, true); }

$file   = DATA_DIR . $doc . '.json';
$method = $_SERVER['REQUEST_METHOD'];

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {
    if ($doc === 'test_ping') {
        jsonOut(['ok' => true, 'exists' => true, 'data' => ['pong' => true, 'server_ts' => date('c')]]);
    }
    if (!file_exists($file)) {
        jsonOut(['ok' => true, 'exists' => false, 'data' => null, 'modified' => 0]);
    }
    $content = file_get_contents($file);
    jsonOut([
        'ok'       => true,
        'exists'   => true,
        'data'     => json_decode($content, true),
        'modified' => filemtime($file),
        'ts'       => date('c', filemtime($file)),
    ]);
}

// ── POST ──────────────────────────────────────────────────────
if ($method === 'POST') {
    $body = file_get_contents('php://input');

    if ($body === '' || $body === false) {
        http_response_code(400); jsonOut(['ok' => false, 'error' => 'Corps vide']);
    }
    if (strlen($body) > MAX_SIZE) {
        http_response_code(413); jsonOut(['ok' => false, 'error' => 'Données trop volumineuses (max 30 Mo)']);
    }
    json_decode($body);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400); jsonOut(['ok' => false, 'error' => 'JSON invalide : ' . json_last_error_msg()]);
    }

    // Écriture atomique
    $tmp = $file . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $body, LOCK_EX) === false) {
        http_response_code(500); jsonOut(['ok' => false, 'error' => 'Erreur écriture disque']);
    }
    rename($tmp, $file);

    logOp('WRITE', $doc, strlen($body));
    jsonOut(['ok' => true, 'modified' => filemtime($file), 'ts' => date('c', filemtime($file)), 'size' => strlen($body)]);
}

http_response_code(405);
jsonOut(['ok' => false, 'error' => 'Méthode non supportée : ' . $method]);
?>
