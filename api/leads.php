<?php
/**
 * ============================================================================
 *  ANALYTIX — LEAD CAPTURE ENDPOINT
 *  Приймає POST з форми реєстрації (ім'я + email + UTM) і зберігає в CSV.
 *  Підтримує також веб-перегляд і завантаження зібраних заявок.
 * ============================================================================
 *
 *  ВСТАНОВЛЕННЯ:
 *
 *  1. Завантажте папку /api/ з файлами `leads.php` і `.htaccess` у корінь сайту.
 *     Шлях має бути https://analytix.work/api/leads.php
 *
 *  2. Перевірте, що папка /api/ доступна на запис:
 *       chmod 755 /api/        (або 775 якщо потрібен запис групі)
 *       chmod 644 /api/leads.php
 *     Файл `leads.csv` створиться автоматично при першій заявці.
 *
 *  3. ОБОВ'ЯЗКОВО змініть VIEWER_PASS на свій унікальний пароль (рядок 35).
 *     Інакше будь-хто з URL зможе переглянути всі заявки.
 *
 *  4. (необов'язково) Заповніть NOTIFY_EMAIL — отримуватимете email при кожній
 *     новій заявці. Хостинг має підтримувати функцію mail().
 *
 *  ВИКОРИСТАННЯ:
 *
 *    POST  https://analytix.work/api/leads.php
 *      Тіло: JSON з полями {name, email, utm_*, lang, url, referrer, timestamp}
 *      Відповідь: 200 OK {"ok": true}
 *
 *    GET   https://analytix.work/api/leads.php?view=1
 *      Веб-інтерфейс з таблицею всіх заявок (запитує логін/пароль).
 *
 *    GET   https://analytix.work/api/leads.php?view=1&download=1
 *      Скачати весь CSV (для Excel чи Google Sheets).
 *
 * ============================================================================
 */

// ============= CONFIGURATION =============
$VIEWER_USER       = 'admin';
$VIEWER_PASS       = 'Normal2012';                          
$CSV_FILE          = __DIR__ . '/leads.csv';
$ALLOWED_ORIGINS   = ['https://analytix.work', 'http://localhost:8080'];
$NOTIFY_EMAIL      = 'analytixxl@gmail.com';                                        
$RATE_LIMIT_PER_MIN = 50;                                       // макс. заявок на хвилину з одного IP
// =========================================

// -------- CORS --------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $ALLOWED_ORIGINS, true)) {
    header("Access-Control-Allow-Origin: $origin");
}
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Vary: Origin');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// -------- VIEWER MODE --------
if (isset($_GET['view'])) {
    if (!isset($_SERVER['PHP_AUTH_USER'])
        || $_SERVER['PHP_AUTH_USER'] !== $VIEWER_USER
        || ($_SERVER['PHP_AUTH_PW'] ?? '') !== $VIEWER_PASS) {
        header('WWW-Authenticate: Basic realm="Analytix Leads"');
        http_response_code(401);
        exit('Unauthorized');
    }

    if (isset($_GET['download'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="analytix-leads-' . date('Y-m-d') . '.csv"');
        if (file_exists($CSV_FILE)) readfile($CSV_FILE);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="uk"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Analytix · Leads</title><style>';
    echo 'body{margin:0;padding:24px;background:#0f172a;color:#e5e7eb;font:14px/1.5 -apple-system,Segoe UI,Roboto,Inter,sans-serif}';
    echo 'h1{margin:0 0 4px;font-size:24px}.sub{color:#a0a7b8;margin:0 0 18px}';
    echo '.dl{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,#00ffa3,#7fffd4);';
    echo 'color:#04120d;padding:10px 18px;border-radius:10px;text-decoration:none;font-weight:700;margin-bottom:18px}';
    echo 'table{width:100%;border-collapse:collapse;font-size:12px;background:#1e293b;border-radius:12px;overflow:hidden}';
    echo 'th{background:#0f172a;text-align:left;padding:12px 10px;border-bottom:1px solid #334155;font-weight:700;color:#00ffa3;white-space:nowrap}';
    echo 'td{padding:10px;border-bottom:1px solid #334155;vertical-align:top;word-break:break-word;max-width:260px}';
    echo 'tr:hover td{background:rgba(255,255,255,.03)}';
    echo '.empty{color:#a0a7b8;padding:40px;text-align:center;border:1px dashed #334155;border-radius:12px}';
    echo '</style></head><body>';
    echo '<h1>Analytix Leads</h1>';

    if (!file_exists($CSV_FILE)) {
        echo '<p class="empty">Поки немає заявок. Сюди потрапляють всі заповнені форми з сайту.</p>';
    } else {
        $rows = array_map('str_getcsv', file($CSV_FILE, FILE_IGNORE_NEW_LINES));
        $headers = array_shift($rows);
        echo '<p class="sub">Загалом записів: <b>' . count($rows) . '</b></p>';
        echo '<a class="dl" href="?view=1&download=1">⬇ Завантажити CSV</a>';
        echo '<table><thead><tr>';
        foreach ($headers as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
        echo '</tr></thead><tbody>';
        foreach (array_reverse($rows) as $row) {
            echo '<tr>';
            foreach ($row as $cell) echo '<td>' . htmlspecialchars($cell) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</body></html>';
    exit;
}

// -------- POST: SAVE LEAD --------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'Method not allowed']));
}

// Rate limit (примітивний, на основі sys_get_temp_dir)
$ip = $_SERVER['HTTP_CF_CONNECTING_IP']
    ?? $_SERVER['HTTP_X_FORWARDED_FOR']
    ?? $_SERVER['REMOTE_ADDR']
    ?? 'unknown';
$rateKey = sys_get_temp_dir() . '/analytix_rate_' . md5($ip);
$now = time();
$window = [];
if (file_exists($rateKey)) {
    $window = json_decode(file_get_contents($rateKey), true) ?: [];
    $window = array_filter($window, fn($t) => $t > $now - 60);
}
if (count($window) >= $RATE_LIMIT_PER_MIN) {
    http_response_code(429);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'Too many requests']));
}
$window[] = $now;
@file_put_contents($rateKey, json_encode($window));

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'Invalid JSON']));
}

function clean($v, $max = 500) {
    $s = is_string($v) ? $v : (string)$v;
    $s = preg_replace('/[\r\n\t\x00-\x1F]/u', ' ', $s);
    return mb_substr(trim($s), 0, $max);
}

$lead = [
    'timestamp'    => clean($data['timestamp'] ?? date('c'), 40),
    'name'         => clean($data['name'] ?? '', 120),
    'email'        => clean($data['email'] ?? '', 200),
    'utm_source'   => clean($data['utm_source'] ?? '', 80),
    'utm_medium'   => clean($data['utm_medium'] ?? '', 80),
    'utm_campaign' => clean($data['utm_campaign'] ?? '', 80),
    'utm_content'  => clean($data['utm_content'] ?? '', 80),
    'utm_term'     => clean($data['utm_term'] ?? '', 80),
    'lang'         => clean($data['lang'] ?? '', 10),
    'url'          => clean($data['url'] ?? '', 1000),
    'referrer'     => clean($data['referrer'] ?? '', 1000),
    'ip'           => clean($ip, 64),
    'user_agent'   => clean($_SERVER['HTTP_USER_AGENT'] ?? '', 400),
];

if (mb_strlen($lead['name']) < 2 || !filter_var($lead['email'], FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'Invalid name or email']));
}

// Створюємо CSV з заголовком при першому запиті
if (!file_exists($CSV_FILE)) {
    $fh = fopen($CSV_FILE, 'w');
    if ($fh) {
        fwrite($fh, "\xEF\xBB\xBF"); // UTF-8 BOM — Excel коректно відкриє
        fputcsv($fh, array_keys($lead));
        fclose($fh);
    }
}

$fh = fopen($CSV_FILE, 'a');
if ($fh) {
    flock($fh, LOCK_EX);
    fputcsv($fh, array_values($lead));
    flock($fh, LOCK_UN);
    fclose($fh);
}

// Email-повідомлення (опціонально)
if ($NOTIFY_EMAIL && filter_var($NOTIFY_EMAIL, FILTER_VALIDATE_EMAIL)) {
    $subj = 'Нова заявка Analytix: ' . $lead['name'];
    $body = "Ім'я: {$lead['name']}\n"
          . "Email: {$lead['email']}\n"
          . "UTM source/medium/campaign/content:\n"
          . "  {$lead['utm_source']} / {$lead['utm_medium']} / {$lead['utm_campaign']} / {$lead['utm_content']}\n"
          . "Мова: {$lead['lang']}\n"
          . "URL: {$lead['url']}\n"
          . "Реферер: {$lead['referrer']}\n"
          . "Час: {$lead['timestamp']}\n"
          . "IP: {$lead['ip']}\n";
    @mail($NOTIFY_EMAIL, $subj, $body, "Content-Type: text/plain; charset=utf-8\r\nFrom: no-reply@analytix.work\r\n");
}

header('Content-Type: application/json');
echo json_encode(['ok' => true]);
