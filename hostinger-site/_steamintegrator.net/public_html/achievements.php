<?php
// public_html/achievements.php
$store = __DIR__ . '/data/highscores.json';
$items = [];

if (is_file($store)) {
  $raw = file_get_contents($store);
  $list = json_decode($raw, true);
  if (is_array($list)) {
    // de-dupe on read (defensive; API already upserts)
    $map = [];
    foreach ($list as $it) {
      $tier = (int)($it['tier'] ?? 0);
      $uid  = isset($it['uid']) ? (string)$it['uid'] : '';
      $name = (string)($it['name'] ?? 'Player');
      $nameKey = strtolower(trim($name));
      $k = $uid !== '' ? "{$tier}|uid:{$uid}" : "{$tier}|name:{$nameKey}";
      if (!isset($map[$k])) {
        $map[$k] = [
          'tier'=>$tier,'name'=>$name,'uid'=>$uid,
          'count'=>(int)($it['count'] ?? 0),
          'date'=>(string)($it['date'] ?? ''),
          'ts'  =>(int)($it['ts'] ?? 0),
        ];
      } else {
        // keep max count and newest ts/date/name
        $map[$k]['count'] = max($map[$k]['count'], (int)($it['count'] ?? 0));
        if ((int)($it['ts'] ?? 0) >= $map[$k]['ts']) {
          $map[$k]['ts']   = (int)($it['ts'] ?? 0);
          if (!empty($it['date'])) $map[$k]['date'] = (string)$it['date'];
          if (!empty($it['name'])) $map[$k]['name'] = (string)$it['name'];
        }
      }
    }
    $items = array_values($map);
    usort($items, function($a,$b){
      $t = $a['tier'] <=> $b['tier']; if ($t) return $t;
      $c = $b['count'] <=> $a['count']; if ($c) return $c;
      return strcmp(strtolower($a['name']), strtolower($b['name']));
    });
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>SlugBug Achievements</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
  :root{
    --slug-blue:#0b3d91;       /* main blue */
    --slug-blue-dark:#082c69;  /* header blue */
    --slug-blue-alt:#0a357f;   /* even-row blue */
    --slug-yellow:#ffd60a;     /* text/accent */
    --border:#0a2a60;          /* table borders */
  }

  body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;margin:24px}
  .tbl-wrap{overflow-x:auto}
  table{width:100%; border-collapse:collapse; table-layout:fixed; min-width:520px}
  th,td{border:1px solid var(--border); padding:10px 12px; color:var(--slug-yellow)}
  th{background:var(--slug-blue-dark); text-align:left; position:sticky; top:0; z-index:1}
  tbody tr{background:var(--slug-blue)}
  tbody tr:nth-child(even){background:var(--slug-blue-alt)}

  /* subtle left accent so rows still feel distinct by tier */
  tr[class^="tier-"] td:first-child{border-left:4px solid var(--slug-yellow)}

  /* make rows stretch nicely across */
  td:nth-child(1){width:34%}
  td:nth-child(2){width:16%}
  td:nth-child(3){width:18%}
  td:nth-child(4){width:32%}
  @media (max-width:720px){
    td,th{padding:8px}
  }

  /* count badge */
  .badge{
    display:inline-block; min-width:2ch; text-align:center;
    padding:.2rem .55rem; border-radius:999px; font-weight:700;
    color:var(--slug-yellow); background:transparent; border:2px solid var(--slug-yellow);
  }

  /* dark-mode polish (keeps your blue/yellow look) */
  @media (prefers-color-scheme: dark){
    body{background:#0b0b0c}
  }
</style>

  
</head>
 <meta name="google-site-verification" content="LJkmYjemyxYuEwThQ361NoAcF3OWKlipiDYUaBXsH6c" />
    <script src="https://app.termly.io/resource-blocker/264d1ed0-9bc4-4549-ad18-606adb43c7e0?autoBlock=on"></script>
</head>
<body>
    <nav class="topbar">
  <a class="btn" href="/highScores.html" id="backLink" aria-label="Back to High Scores">← Back to High Scores</a>
</nav>

<script>
// If user arrived from your first page, use history.back() for a smoother return.
const backLink = document.getElementById('backLink');
backLink.addEventListener('click', (e) => {
  const fromSameSite = document.referrer && new URL(document.referrer).origin === location.origin;
  if (fromSameSite) {
    e.preventDefault();
    history.back();
  }
});
</script>

  <h1>SlugBug Achievements</h1>

  <?php if (empty($items)): ?>
    <p class="muted">No achievements yet.</p>
  <?php else: ?>
   <div class="tbl-wrap">
  <table>
    <thead>
      <tr><th>Name</th><th>Tier</th><th>Count</th><th>Last Achieved</th></tr>
    </thead>
    <tbody>
      <?php foreach ($items as $it): ?>
        <tr class="tier-<?= (int)($it['tier'] ?? 0) ?>">
          <td><?= htmlspecialchars($it['name'] ?? 'Player') ?></td>
          <td><?= (int)($it['tier'] ?? 0) ?></td>
          <td><span class="badge"><?= (int)($it['count'] ?? 0) ?></span></td>
          <td><?= htmlspecialchars($it['date'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
  <?php endif; ?>
</body>
</html>
