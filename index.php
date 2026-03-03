<?php
// Anti-cache (utile avec nginx/proxy)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// ===================== CONFIG =====================
$host = "localhost";
$user = "station";
$password = "XXXX";     // <-- Mets ton vrai mot de passe
$dbname = "station_meteo";
$preferredTables = ["meteo", "mesures"];

$limitChart = 200;   // points graphes (brut, ~16h40 si 5 min)
$limitHours = 72;    // historique horaire (72h = 3 jours)
$refreshSeconds = 10;

// ===================== DB CONNECT =====================
$conn = new mysqli($host, $user, $password, $dbname);
if ($conn->connect_error) {
  http_response_code(500);
  die("Erreur SQL (connexion). Vérifie user/mdp/base.");
}
$conn->set_charset("utf8mb4");

// ===================== FIND TABLE =====================
$table = null;
$tablesRes = $conn->query("SHOW TABLES");
$existingTables = [];
while ($tablesRes && ($r = $tablesRes->fetch_array())) $existingTables[] = $r[0];

foreach ($preferredTables as $t) {
  if (in_array($t, $existingTables, true)) { $table = $t; break; }
}
if (!$table && count($existingTables) > 0) $table = $existingTables[0];
if (!$table) { http_response_code(500); die("Aucune table trouvée dans la base '$dbname'."); }

// ===================== HELPERS =====================
function col($row, $name) { return (is_array($row) && array_key_exists($name, $row)) ? $row[$name] : null; }
function numOrNull($v) { return is_numeric($v) ? floatval($v) : null; }

// ===================== LAST ROW =====================
$last = null;
$lastRes = $conn->query("SELECT temperature, humidite, pluie, date_mesure FROM `$table` ORDER BY date_mesure DESC LIMIT 1");
if ($lastRes) $last = $lastRes->fetch_assoc();

$lastTemp = $last ? col($last, "temperature") : null;
$lastHum  = $last ? col($last, "humidite") : null;
$lastRain = $last ? col($last, "pluie") : null;
$lastDate = $last ? col($last, "date_mesure") : null;

// ===================== HISTORY (HOURLY) =====================
// Temp/Hum = AVG ; Pluie = SUM
$history = [];
$sqlHistory = "
  SELECT
    DATE_FORMAT(date_mesure, '%Y-%m-%d %H:00:00') AS heure,
    ROUND(AVG(temperature), 1) AS temperature,
    ROUND(AVG(humidite), 1) AS humidite,
    ROUND(SUM(pluie), 2) AS pluie
  FROM `$table`
  GROUP BY heure
  ORDER BY heure DESC
  LIMIT " . intval($limitHours);

$histRes = $conn->query($sqlHistory);
if ($histRes) while ($row = $histRes->fetch_assoc()) $history[] = $row;

// ===================== CHART DATA (RAW 5-min) =====================
$chartRows = [];
$chartRes = $conn->query("SELECT temperature, humidite, pluie, date_mesure FROM `$table` ORDER BY date_mesure DESC LIMIT " . intval($limitChart));
if ($chartRes) while ($row = $chartRes->fetch_assoc()) $chartRows[] = $row;
$chartRows = array_reverse($chartRows);

$labels = [];
$temp = [];
$hum  = [];
$rain = [];

foreach ($chartRows as $r) {
  $labels[] = col($r, "date_mesure");
  $temp[]   = numOrNull(col($r, "temperature"));
  $hum[]    = numOrNull(col($r, "humidite"));
  $rain[]   = numOrNull(col($r, "pluie"));
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1"/>

  <meta http-equiv="refresh" content="<?= intval($refreshSeconds) ?>">
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
  <meta http-equiv="Pragma" content="no-cache">
  <meta http-equiv="Expires" content="0">

  <title>Station météo</title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <style>
    :root{--bg:#0b1220;--card:rgba(255,255,255,0.06);--text:rgba(255,255,255,0.92);--muted:rgba(255,255,255,0.65);--border:rgba(255,255,255,0.12);--shadow:0 10px 30px rgba(0,0,0,0.35);--radius:18px;}
    *{box-sizing:border-box}
    body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:radial-gradient(1200px 700px at 20% 10%, rgba(56,189,248,0.15), transparent 60%),radial-gradient(900px 600px at 80% 10%, rgba(167,139,250,0.13), transparent 55%),radial-gradient(900px 700px at 50% 90%, rgba(34,197,94,0.10), transparent 55%),var(--bg);color:var(--text);}
    .wrap{max-width:1100px;margin:0 auto;padding:28px 18px 60px;}
    header{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:18px;}
    h1{margin:0;font-size:28px;}
    .pill{border:1px solid var(--border);background:rgba(255,255,255,0.05);padding:8px 12px;border-radius:999px;color:var(--muted);font-size:13px;}
    .grid{display:grid;grid-template-columns:repeat(12,1fr);gap:14px;margin-top:14px;}
    .card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:16px;backdrop-filter:blur(8px);}
    .span-4{grid-column:span 4;}
    .span-12{grid-column:span 12;}
    @media (max-width: 900px){.span-4{grid-column:span 12;}}
    .kpi{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;}
    .kpi .name{color:var(--muted);font-size:13px;}
    .kpi .value{font-size:26px;font-weight:700;margin-top:6px;}
    .kpi .unit{font-size:14px;color:var(--muted);font-weight:600;margin-left:6px;}
    .section-title{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:18px 2px 10px;}
    .section-title h2{margin:0;font-size:18px;}
    .hint{color:var(--muted);font-size:13px;}
    .chartBox{height:320px;}
    canvas{width:100% !important;height:100% !important;}
    table{width:100%;border-collapse:collapse;overflow:hidden;border-radius:14px;}
    thead th{text-align:left;font-size:13px;color:var(--muted);padding:12px 10px;border-bottom:1px solid var(--border);}
    tbody td{padding:12px 10px;border-bottom:1px solid rgba(255,255,255,0.07);font-size:14px;}
    tbody tr:hover{background:rgba(255,255,255,0.04);}
    .right{text-align:right;}
    .muted{color:var(--muted);}
  </style>
</head>

<body>
<div class="wrap">
  <header>
    <div>
      <h1>🌦️ Station météo</h1>
    </div>
    <div class="pill">Dernière mesure : <b><?= $lastDate ? htmlspecialchars($lastDate) : "—" ?></b> · refresh <?= intval($refreshSeconds) ?>s</div>
  </header>

  <div class="grid">
    <div class="card span-4">
      <div class="kpi">
        <div><div class="name">Température</div><div class="value"><?= $lastTemp ?? "—" ?><span class="unit">°C</span></div></div>
        <div>🌡️</div>
      </div>
    </div>
    <div class="card span-4">
      <div class="kpi">
        <div><div class="name">Humidité</div><div class="value"><?= $lastHum ?? "—" ?><span class="unit">%</span></div></div>
        <div>💧</div>
      </div>
    </div>
    <div class="card span-4">
      <div class="kpi">
        <div><div class="name">Pluie (dernier point)</div><div class="value"><?= $lastRain ?? "—" ?><span class="unit">mm</span></div></div>
        <div>🌧️</div>
      </div>
    </div>
  </div>

  <div class="section-title">
    <h2>📊 Graphiques (<?= intval($limitChart) ?> dernières mesures)</h2>
    <div class="hint">Données brutes (5 min)</div>
  </div>

  <div class="grid">
    <div class="card span-12"><h3 style="margin:0 0 10px;">🌡️ Température (°C)</h3><div class="chartBox"><canvas id="chartTemp"></canvas></div></div>
    <div class="card span-12"><h3 style="margin:0 0 10px;">💧 Humidité (%)</h3><div class="chartBox"><canvas id="chartHum"></canvas></div></div>
    <div class="card span-12"><h3 style="margin:0 0 10px;">🌧️ Pluie (mm)</h3><div class="chartBox"><canvas id="chartRain"></canvas></div></div>
  </div>

  <div class="section-title">
    <h2>🕒 Historique horaire (<?= intval($limitHours) ?> dernières heures)</h2>
    <div class="hint">Temp/Hum = AVG, Pluie = SUM</div>
  </div>

  <div class="card span-12">
    <div style="overflow:auto;">
      <table>
        <thead>
          <tr>
            <th>Heure</th>
            <th class="right">Temp (°C)</th>
            <th class="right">Hum (%)</th>
            <th class="right">Pluie (mm/h)</th>
          </tr>
        </thead>
        <tbody>
        <?php if (count($history) === 0): ?>
          <tr><td colspan="4" class="muted">Aucune donnée.</td></tr>
        <?php else: foreach ($history as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r["heure"] ?? "—") ?></td>
            <td class="right"><?= htmlspecialchars($r["temperature"] ?? "—") ?></td>
            <td class="right"><?= htmlspecialchars($r["humidite"] ?? "—") ?></td>
            <td class="right"><?= htmlspecialchars($r["pluie"] ?? "—") ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
const labels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
const temp   = <?= json_encode($temp, JSON_UNESCAPED_UNICODE) ?>;
const hum    = <?= json_encode($hum, JSON_UNESCAPED_UNICODE) ?>;
const rain   = <?= json_encode($rain, JSON_UNESCAPED_UNICODE) ?>;

function makeChart(canvasId, label, data, yLabel) {
  new Chart(document.getElementById(canvasId), {
    type: 'line',
    data: { labels, datasets: [{ label, data, tension: 0.25, pointRadius: 0, borderWidth: 2 }]},
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { labels: { color: 'rgba(255,255,255,0.85)' } } },
      scales: {
        x: { ticks: { color: 'rgba(255,255,255,0.6)', maxTicksLimit: 10 }, grid: { color: 'rgba(255,255,255,0.08)' } },
        y: { title: { display: true, text: yLabel, color: 'rgba(255,255,255,0.7)' },
             ticks: { color: 'rgba(255,255,255,0.6)' }, grid: { color: 'rgba(255,255,255,0.08)' } }
      }
    }
  });
}
makeChart("chartTemp", "Température (°C)", temp, "°C");
makeChart("chartHum",  "Humidité (%)", hum, "%");
makeChart("chartRain", "Pluie (mm)", rain, "mm");
</script>
</body>
</html>
