<?php
// public_html/api/achievement.php
header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);

// --- tiny debug log (optional) ---
$__log = __DIR__ . '/../data/api.log';
if (!is_dir(dirname($__log))) { @mkdir(dirname($__log), 0755, true); }
function dbg($m){ global $__log; @file_put_contents($__log, date('c')." $m\n", FILE_APPEND); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error'=>'Method Not Allowed']); exit;
}
dbg('start POST');

// read + validate JSON
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) { http_response_code(400); echo json_encode(['error'=>'invalid json']); exit; }
foreach (['tier','total','name','date'] as $k) {
  if (!isset($data[$k])) { http_response_code(400); echo json_encode(['error'=>"missing $k"]); exit; }
}

// sanitize inputs
$tier  = (int)$data['tier'];
$total = (int)$data['total'];                // if 0 => increment
$name  = substr(preg_replace('/[^a-z0-9 \-._]/i','',(string)$data['name']),0,40);
$date  = (string)$data['date'];
$uid   = isset($data['uid']) ? (string)$data['uid'] : ''; // optional but recommended
$now   = time();

// open store
$store = __DIR__ . '/../data/highscores.json';
if (!is_dir(dirname($store))) { @mkdir(dirname($store), 0755, true); }

$fp = @fopen($store, 'c+');
if (!$fp) { http_response_code(500); echo json_encode(['error'=>'cannot open store']); exit; }

@flock($fp, LOCK_EX);
rewind($fp);
$contents = stream_get_contents($fp);
$items = $contents ? json_decode($contents, true) : [];
if (!is_array($items)) $items = [];

// list -> map (dedupe)
$nameKey = strtolower(trim($name));
$key = $uid !== '' ? "{$tier}|uid:{$uid}" : "{$tier}|name:{$nameKey}";
$map = [];
foreach ($items as $it) {
  $itTier = (int)($it['tier'] ?? 0);
  $itUid  = isset($it['uid']) ? (string)$it['uid'] : '';
  $itNameKey = strtolower(trim((string)($it['name'] ?? '')));
  $k = $itUid !== '' ? "{$itTier}|uid:{$itUid}" : "{$itTier}|name:{$itNameKey}";
  if (!isset($map[$k])) {
    $map[$k] = $it;
  } else {
    // keep best of duplicates
    $map[$k]['count'] = max((int)($map[$k]['count'] ?? 0), (int)($it['count'] ?? 0));
    $map[$k]['ts']    = max((int)($map[$k]['ts'] ?? 0),    (int)($it['ts'] ?? 0));
    if (!empty($it['name'])) $map[$k]['name'] = (string)$it['name'];
    if (!empty($it['date'])) $map[$k]['date'] = (string)$it['date'];
  }
}

// upsert this POST
if (isset($map[$key])) {
  $cur = $map[$key];
  $curCount = (int)($cur['count'] ?? 0);
  $newCount = $total > 0 ? $total : ($curCount + 1);
  $map[$key] = array_merge($cur, [
    'tier'  => $tier,
    'name'  => $name,
    'uid'   => $uid,
    'count' => $newCount,
    'date'  => $date,
    'ts'    => $now,
  ]);
} else {
  $initial = max(1, $total);
  $map[$key] = [
    'tier'  => $tier,
    'name'  => $name,
    'uid'   => $uid,
    'count' => $initial,
    'date'  => $date,
    'ts'    => $now,
  ];
}

// map -> sorted list
$items = array_values($map);
usort($items, function($a, $b){
  $t = ((int)($a['tier'] ?? 0)) <=> ((int)($b['tier'] ?? 0));
  if ($t !== 0) return $t;
  $c = ((int)($b['count'] ?? 0)) <=> ((int)($a['count'] ?? 0));
  if ($c !== 0) return $c;
  return strcmp(strtolower($a['name'] ?? ''), strtolower($b['name'] ?? ''));
});

// write back
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($items, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
fflush($fp);
@flock($fp, LOCK_UN);
fclose($fp);

echo json_encode(['ok'=>true,'updated'=>$key]);

