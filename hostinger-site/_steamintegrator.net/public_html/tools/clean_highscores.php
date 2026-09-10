<?php
// public_html/tools/clean_highscores.php
// One-time dedupe/aggregate for ../data/highscores.json

$store = __DIR__ . '/../data/highscores.json';
if (!is_file($store)) { echo "No highscores.json found.\n"; exit; }

$raw = file_get_contents($store);
$list = json_decode($raw, true);
if (!is_array($list)) { echo "Invalid JSON.\n"; exit; }

$map = [];
$dupes = 0;
foreach ($list as $it) {
  $tier = (int)($it['tier'] ?? 0);
  $uid  = isset($it['uid']) ? (string)$it['uid'] : '';
  $name = (string)($it['name'] ?? 'Player');
  $nameKey = strtolower(trim(preg_replace('/\s+/', ' ', $name))); // normalize spaces & case
  $k = $uid !== '' ? "{$tier}|uid:{$uid}" : "{$tier}|name:{$nameKey}";

  $count = (int)($it['count'] ?? ($it['total'] ?? 0)); // fallback if old field existed
  $ts    = (int)($it['ts'] ?? 0);
  $date  = (string)($it['date'] ?? '');

  if (!isset($map[$k])) {
    $map[$k] = [
      'tier'  => $tier,
      'name'  => $name,
      'uid'   => $uid,
      'count' => max(0, $count),
      'date'  => $date,
      'ts'    => $ts,
    ];
  } else {
    $dupes++;
    // Keep highest count
    $map[$k]['count'] = max((int)$map[$k]['count'], $count);
    // Keep latest ts/date/name
    if ($ts >= (int)$map[$k]['ts']) {
      $map[$k]['ts']   = $ts;
      if ($date !== '') $map[$k]['date'] = $date;
      if ($name !== '') $map[$k]['name'] = $name;
    }
  }
}

$items = array_values($map);
usort($items, function($a, $b){
  $t = ((int)$a['tier']) <=> ((int)$b['tier']);
  if ($t !== 0) return $t;
  $c = ((int)$b['count']) <=> ((int)$a['count']);
  if ($c !== 0) return $c;
  return strcmp(strtolower($a['name']), strtolower($b['name']));
});

file_put_contents($store, json_encode($items, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

echo "Cleaned. Kept ".count($items)." unique rows. Removed/merged ~$dupes duplicates.\n";
