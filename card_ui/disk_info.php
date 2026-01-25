<?php
/******************************
 * Scrutiny Simple Dashboard (FINAL v2)
 * - cfg로 '계산 규칙(convert_*)' 과 '표기 순서(display_invert_*)' 분리
 * - 온도 바 20–80°C 기준, 바 위치 들뜸 방지
 ******************************/
date_default_timezone_set('Asia/Seoul');
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$BASE = getenv('SCRUTINY_BASE') ?: 'http://192.168.1.2:6090'; // <-- Scrutiny 주소 (환경변수 지원)
$TIMEOUT = 6;
$RETRY = 1;

$TEMP_MIN = 20; // °C
$TEMP_MAX = 70; // °C (SSD 환경에 맞춰 80에서 70으로 하향 조정)

$CFG_PATH = __DIR__ . '/wear_invert.cfg';

/* ---------- 공통 유틸 ---------- */
function http_get_json(string $url, int $timeout = 5, int $retry = 0): array
{
  $attempt = 0;
  do {
    $attempt++;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => $timeout,
      CURLOPT_TIMEOUT => $timeout,
      CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($err || $code < 200 || $code >= 300 || $body === false) {
      if ($attempt <= $retry + 1) {
        usleep(200 * 1000);
        continue;
      }
      return ['_error' => "GET $url 실패 (code=$code, err=$err)"];
    }
    $json = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE)
      return ['_error' => "JSON 파싱 실패: " . json_last_error_msg()];
    return $json ?? [];
  } while ($attempt <= $retry + 1);
}
function fmt_bytes($bytes): string
{
  if ($bytes === null || $bytes === '' || !is_numeric($bytes))
    return '-';
  $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
  $i = 0;
  $bytes = (float) $bytes;
  while ($bytes >= 1024 && $i < count($units) - 1) {
    $bytes /= 1024;
    $i++;
  }
  return sprintf(($i >= 3 ? '%.2f %s' : '%.0f %s'), $bytes, $units[$i]);
}
function fmt_hours($hours): string
{
  if ($hours === null || $hours === '' || !is_numeric($hours))
    return '-';
  $hours = (int) $hours;
  $d = intdiv($hours, 24);
  $h = $hours % 24;
  $y = $hours / (24 * 365);
  $main = ($d > 0) ? "{$d}d {$h}h" : "{$h}h";
  return sprintf("%s (%.1fy)", $main, $y);
}
function find_attr(array $latest, array $metadata, array $cands): ?array
{
  $attrs = $latest['attrs'] ?? [];
  if (!is_array($attrs) || !$attrs)
    return null;
  if ($metadata) {
    foreach ($metadata as $id => $meta) {
      $name = strtolower($meta['display_name'] ?? '');
      foreach ($cands as $c) {
        if (isset($c['display_name']) && strtolower($c['display_name']) === $name) {
          if (isset($attrs[$id]) && is_array($attrs[$id]))
            return $attrs[$id] + ['_id' => (string) $id, '_name' => $meta['display_name']];
        }
      }
    }
  }
  foreach ($cands as $c) {
    if (isset($c['id'])) {
      $idStr = (string) $c['id'];
      if (isset($attrs[$idStr]))
        return $attrs[$idStr] + ['_id' => $idStr];
      if (isset($attrs[(int) $c['id']]))
        return $attrs[(int) $c['id']] + ['_id' => (string) $c['id']];
    }
  }
  return null;
}
function latest_smart(array $results): array
{
  if (!is_array($results))
    return [];
  usort($results, function ($a, $b) {
    $da = strtotime($a['date'] ?? $a['collector_date'] ?? '1970-01-01');
    $db = strtotime($b['date'] ?? $b['collector_date'] ?? '1970-01-01');
    return $db <=> $da;
  });
  return $results[0] ?? [];
}

/* ---------- cfg 로더 ----------

  계산 규칙(원시값 의미 지정 → 남은% 계산 방법):
    convert_model=정확모델명
    convert_serial=정확시리얼
    convert_wwn=정확WWN
    convert_regex_model=/정규식/i
    convert_regex_serial=/정규식/i
    convert_regex_wwn=/정규식/i
   → 매치되면 남은%=100-원시값 (원시=소모%)

  표기 순서(텍스트만 변경):
    display_invert_model=...
    display_invert_serial=...
    display_invert_wwn=...
    display_invert_regex_model=/.../
    display_invert_regex_serial=/.../
    display_invert_regex_wwn=/.../
   → 매치되면 "소모 X% (남은 Y%)", 아니면 "남은 Y% (소모 X%)"
*/
function load_cfg(string $path): array
{
  $cfg = [
    'conv_model' => [],
    'conv_serial' => [],
    'conv_wwn' => [],
    'conv_r_model' => [],
    'conv_r_serial' => [],
    'conv_r_wwn' => [],
    'disp_model' => [],
    'disp_serial' => [],
    'disp_wwn' => [],
    'disp_r_model' => [],
    'disp_r_serial' => [],
    'disp_r_wwn' => [],
    'simple' => [],
  ];

  // 환경 변수에서 구성을 먼저 읽어옴
  $envCfg = getenv('WEAR_INVERT_CONFIG');
  if ($envCfg) {
    $lines = explode("\n", $envCfg);
  } elseif (is_file($path)) {
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  } else {
    return $cfg;
  }

  if ($lines === false)
    return $cfg;

  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#'))
      continue;

    // '=' 가 없는 경우: 단순 매치 목록에 추가
    if (!str_contains($line, '=')) {
      $cfg['simple'][] = $line;
      continue;
    }

    // convert_*
    if (preg_match('/^convert_(model|serial|wwn)\s*=\s*(.+)$/i', $line, $m)) {
      $cfg['conv_' . $m[1]][] = trim($m[2]);
      continue;
    }
    if (preg_match('/^convert_regex_(model|serial|wwn)\s*=\s*(\/.+\/[imsxuADSUXJ]*)\s*$/i', $line, $m)) {
      $cfg['conv_r_' . $m[1]][] = trim($m[2]);
      continue;
    }
    // display_invert_*
    if (preg_match('/^display_invert_(model|serial|wwn)\s*=\s*(.+)$/i', $line, $m)) {
      $cfg['disp_' . $m[1]][] = trim($m[2]);
      continue;
    }
    if (preg_match('/^display_invert_regex_(model|serial|wwn)\s*=\s*(\/.+\/[imsxuADSUXJ]*)\s*$/i', $line, $m)) {
      $cfg['disp_r_' . $m[1]][] = trim($m[2]);
      continue;
    }
  }
  return $cfg;
}
function match_any(string $val, array $list, array $rlist, array $simple = []): bool
{
  if (in_array($val, $list, true))
    return true;
  foreach ($rlist as $rx) {
    if (@preg_match($rx, $val))
      return true;
  }
  foreach ($simple as $s) {
    if ($s !== '' && stripos($val, $s) !== false)
      return true;
  }
  return false;
}
function need_convert(array $dev, array $cfg): bool
{
  $model = $dev['model_name'] ?? '';
  $serial = $dev['serial_number'] ?? '';
  $wwn = $dev['wwn'] ?? ($dev['device']['wwn'] ?? '');
  $node = $dev['device_node'] ?? ($dev['device']['device_node'] ?? '');
  $simple = $cfg['simple'] ?? [];
  return match_any($model, $cfg['conv_model'], $cfg['conv_r_model'], $simple)
    || match_any($serial, $cfg['conv_serial'], $cfg['conv_r_serial'], $simple)
    || match_any($wwn, $cfg['conv_wwn'], $cfg['conv_r_wwn'], $simple)
    || match_any($node, [], [], $simple);
}
function need_display_invert(array $dev, array $cfg): bool
{
  $model = $dev['model_name'] ?? '';
  $serial = $dev['serial_number'] ?? '';
  $wwn = $dev['wwn'] ?? ($dev['device']['wwn'] ?? '');
  $node = $dev['device_node'] ?? ($dev['device']['device_node'] ?? '');
  $simple = $cfg['simple'] ?? [];
  return match_any($model, $cfg['disp_model'], $cfg['disp_r_model'], $simple)
    || match_any($serial, $cfg['disp_serial'], $cfg['disp_r_serial'], $simple)
    || match_any($wwn, $cfg['disp_wwn'], $cfg['disp_r_wwn'], $simple)
    || match_any($node, [], [], $simple);
}

/* ---------- 데이터 수집/가공 ---------- */
$cfg = load_cfg($CFG_PATH);

$summaryResp = http_get_json($BASE . '/api/summary', $TIMEOUT, $RETRY);
$summary = $summaryResp['data']['summary'] ?? ($summaryResp['summary'] ?? []);
if (!is_array($summary))
  $summary = [];
$rows = [];

foreach ($summary as $wwnKey => $entry) {
  $wwn = is_string($wwnKey) ? $wwnKey : ($entry['device']['wwn'] ?? null);
  if (!$wwn)
    continue;

  $detailsResp = http_get_json($BASE . '/api/device/' . rawurlencode($wwn) . '/details', $TIMEOUT, $RETRY);
  $data = $detailsResp['data'] ?? [];
  $device = $data['device'] ?? ($entry['device'] ?? []);
  if (!isset($device['wwn']) && $wwn)
    $device['wwn'] = $wwn;

  $smartResults = $data['smart_results'] ?? [];
  $meta = $data['metadata'] ?? ($detailsResp['metadata'] ?? []);
  $latest = latest_smart($smartResults);

  // 상태
  $statusRaw = $latest['Status'] ?? $latest['status'] ?? null;
  $statusText = '-';
  $statusClass = 'badge-neutral';
  $statusWeight = 0;
  if (is_numeric($statusRaw)) {
    if ($statusRaw == 0) {
      $statusText = 'OK';
      $statusClass = 'badge-ok';
      $statusWeight = 0;
    } elseif ($statusRaw == 1) {
      $statusText = 'WARN';
      $statusClass = 'badge-warn';
      $statusWeight = 1;
    } elseif ($statusRaw >= 2) {
      $statusText = 'FAIL';
      $statusClass = 'badge-fail';
      $statusWeight = 2;
    }
  } elseif (is_string($statusRaw)) {
    $s = strtolower($statusRaw);
    if (str_contains($s, 'ok') || str_contains($s, 'pass')) {
      $statusText = 'OK';
      $statusClass = 'badge-ok';
      $statusWeight = 0;
    } elseif (str_contains($s, 'warn') || str_contains($s, 'advis')) {
      $statusText = 'WARN';
      $statusClass = 'badge-warn';
      $statusWeight = 1;
    } elseif (str_contains($s, 'fail') || str_contains($s, 'crit')) {
      $statusText = 'FAIL';
      $statusClass = 'badge-fail';
      $statusWeight = 2;
    } else {
      $statusText = strtoupper($statusRaw);
      $statusWeight = 1;
    }
  }

  $powerOnHours = $latest['power_on_hours'] ?? null;
  $powerCycle = $latest['power_cycle_count'] ?? null;
  $tempC = $latest['temp'] ?? ($latest['temperature'] ?? null);

  // Wear
  $wearAttr = find_attr($latest, $meta, [
    ['display_name' => 'Wear Range Delta'],
    ['id' => 173],
    ['id' => 177],
    ['display_name' => 'Wear Leveling Count'],
    ['display_name' => 'Percentage Used'],
  ]);
  $raw = $wearAttr['raw_value'] ?? ($wearAttr['value'] ?? null);
  $attrName = strtolower($wearAttr['_name'] ?? '');

  $remain = null;
  $consumed = null;
  $text = '-';
  if (is_numeric($raw)) {
    $raw = (int) max(0, min(100, $raw));

    // NVMe "Percentage Used"는 그 자체로 소모량임
    $isPercentageUsed = (str_contains($attrName, 'percentage used'));
    $convert = $isPercentageUsed || need_convert($device, $cfg); // Percentage Used이거나 cfg에 명시된 경우 남은%=100-raw

    $remain = $convert ? (100 - $raw) : $raw;
    $consumed = 100 - $remain;
    $invertDisp = $isPercentageUsed || need_display_invert($device, $cfg);
    $text = $invertDisp
      ? sprintf("소모 %d%% (남은 %d%%)", $consumed, $remain)
      : sprintf("남은 %d%% (소모 %d%%)", $remain, $consumed);
  }

  // TBW
  $tbwAttr = find_attr($latest, $meta, [
    ['display_name' => 'Total LBAs Written'],
    ['id' => 241],
    ['display_name' => 'Data Units Written'],
    ['display_name' => 'Host Writes'],
  ]);
  $nameLower = strtolower($tbwAttr['_name'] ?? (($tbwAttr['_id'] ?? '') !== '' ? ('id ' . $tbwAttr['_id']) : ''));
  $tbwRaw = $tbwAttr['raw_value'] ?? ($tbwAttr['value'] ?? null);
  $tbwFmt = '-';
  $tbBytes = null;
  if (is_numeric($tbwRaw)) {
    if (str_contains($nameLower, 'data units written'))
      $tbBytes = (float) $tbwRaw * 512000.0;
    elseif (str_contains($nameLower, 'total lbas written') || ($tbwAttr['_id'] ?? '') === '241')
      $tbBytes = (float) $tbwRaw * 512.0;
    elseif (str_contains($nameLower, 'host writes'))
      $tbwFmt = number_format((float) $tbwRaw) . ' (raw)';
    if ($tbBytes !== null) {
      $gb = $tbBytes / (1024 ** 3);
      $tb = $tbBytes / (1024 ** 4);
      $tbwFmt = sprintf("%.1f GB (%.2f TB)", $gb, $tb);
    }
  }

  // Bars
  $tBar = null;
  if (is_numeric($tempC)) {
    $tBar = ($tempC - $TEMP_MIN) / max(1, ($TEMP_MAX - $TEMP_MIN)) * 100.0;
    $tBar = max(0, min(100, $tBar));
  }
  $lifeBar = is_numeric($remain) ? max(0, min(100, $remain)) : null;

  $rows[] = [
    'status_weight' => $statusWeight,
    'status_text' => $statusText,
    'status_class' => $statusClass,
    'model' => $device['model_name'] ?? '-',
    'serial' => $device['serial_number'] ?? '-',
    'wwn' => $device['wwn'] ?? '',
    'poc' => is_null($powerCycle) ? '-' : number_format((int) $powerCycle),
    'poh' => fmt_hours($powerOnHours),
    'capacity' => fmt_bytes($device['capacity'] ?? null),
    'tempC_val' => is_numeric($tempC) ? (float) $tempC : null,
    'temp_bar' => $tBar,
    'temp_label' => is_null($tempC) ? '-' : (number_format((float) $tempC, 0) . '°C'),
    'wear_text' => $text,
    'life_bar' => $lifeBar,
    'remain_pct' => $remain,
    'tbw' => $tbwFmt,
    'detail_url' => $BASE . '/device/' . rawurlencode($device['wwn'] ?? ''),
  ];
}

/* ---------- 통계 ---------- */
$total = count($rows);
$cntOK = $cntWARN = $cntFAIL = 0;
$tAcc = 0;
$tCount = 0;
$wAcc = 0;
$wCount = 0;
foreach ($rows as $r) {
  if ($r['status_class'] === 'badge-ok')
    $cntOK++;
  elseif ($r['status_class'] === 'badge-warn')
    $cntWARN++;
  elseif ($r['status_class'] === 'badge-fail')
    $cntFAIL++;
  if (is_numeric($r['tempC_val'])) {
    $tAcc += $r['tempC_val'];
    $tCount++;
  }
  if (is_numeric($r['remain_pct'])) {
    $wAcc += (int) $r['remain_pct'];
    $wCount++;
  }
}
$avgT = $tCount ? round($tAcc / $tCount, 1) : '-';
$avgW = $wCount ? round($wAcc / $wCount, 1) : '-';

?>
<!doctype html>
<html lang="ko">

<head>
  <meta charset="utf-8">
  <title>Scrutiny Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root {
      --bg: #030712;
      --card-bg: rgba(17, 24, 39, 0.7);
      --border: rgba(255, 255, 255, 0.08);
      --fg: #f9fafb;
      --fg-muted: #9ca3af;
      --accent: #3b82f6;
      --accent-glow: rgba(59, 130, 246, 0.4);

      --ok: #10b981;
      --warn: #f59e0b;
      --fail: #ef4444;

      --glass: blur(10px);
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      background: var(--bg);
      background-image:
        radial-gradient(at 0% 0%, rgba(59, 130, 246, 0.15) 0, transparent 50%),
        radial-gradient(at 100% 100%, rgba(16, 185, 129, 0.1) 0, transparent 50%);
      color: var(--fg);
      font-family: 'Inter', -apple-system, system-ui, sans-serif;
      line-height: 1.5;
      min-height: 100vh;
      padding: 2rem;
    }

    .container {
      max-width: 1400px;
      margin: 0 auto;
    }

    header {
      display: flex;
      justify-content: space-between;
      align-items: flex-end;
      margin-bottom: 2.5rem;
    }

    h1 {
      font-size: 1.875rem;
      font-weight: 800;
      letter-spacing: -0.025em;
    }

    .header-stats {
      display: flex;
      gap: 2rem;
    }

    .header-stat {
      display: flex;
      flex-direction: column;
    }

    .header-stat .label {
      font-size: 0.75rem;
      color: var(--fg-muted);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      font-weight: 600;
    }

    .header-stat .value {
      font-size: 1.5rem;
      font-weight: 700;
    }

    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 1.25rem;
      margin-bottom: 1rem;
    }

    .kpi-card {
      background: var(--card-bg);
      backdrop-filter: var(--glass);
      -webkit-backdrop-filter: var(--glass);
      border: 1px solid var(--border);
      border-radius: 1.25rem;
      padding: 1.5rem;
      transition: transform 0.2s, border-color 0.2s;
    }

    .kpi-card:hover {
      transform: translateY(-2px);
      border-color: var(--accent);
    }

    .kpi-card .label {
      font-size: 0.875rem;
      color: var(--fg-muted);
      margin-bottom: 0.5rem;
    }

    .kpi-card .value {
      font-size: 1.75rem;
      font-weight: 800;
    }

    .kpi-card.ok .value {
      color: var(--ok);
    }

    .kpi-card.warn .value {
      color: var(--warn);
    }

    .kpi-card.fail .value {
      color: var(--fail);
    }

    .disk-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(440px, 1fr));
      gap: 1.5rem;
    }

    .disk-card {
      background: var(--card-bg);
      backdrop-filter: var(--glass);
      -webkit-backdrop-filter: var(--glass);
      border: 1px solid var(--border);
      border-radius: 1.5rem;
      padding: 1.75rem;
      display: flex;
      flex-direction: column;
      gap: 1.5rem;
      position: relative;
      overflow: hidden;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      cursor: pointer;
    }

    .disk-card:hover {
      border-color: rgba(255, 255, 255, 0.2);
      box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3);
      transform: translateY(-4px);
    }

    .status-glow {
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
    }

    .badge-ok .status-glow {
      background: var(--ok);
      box-shadow: 0 0 15px var(--ok);
    }

    .badge-warn .status-glow {
      background: var(--warn);
      box-shadow: 0 0 15px var(--warn);
    }

    .badge-fail .status-glow {
      background: var(--fail);
      box-shadow: 0 0 15px var(--fail);
    }

    .card-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
    }

    .model-info {
      display: flex;
      flex-direction: column;
      gap: 0.25rem;
    }

    .model-name {
      font-size: 1.25rem;
      font-weight: 700;
      color: #fff;
    }

    .serial-number {
      font-size: 0.875rem;
      color: var(--fg-muted);
      font-family: monospace;
    }

    .badge {
      font-size: 0.75rem;
      font-weight: 800;
      padding: 0.25rem 0.75rem;
      border-radius: 999px;
      text-transform: uppercase;
    }

    .badge-ok {
      background: rgba(16, 185, 129, 0.1);
      color: var(--ok);
      border: 1px solid rgba(16, 185, 129, 0.2);
    }

    .badge-warn {
      background: rgba(245, 158, 11, 0.1);
      color: var(--warn);
      border: 1px solid rgba(245, 158, 11, 0.2);
    }

    .badge-fail {
      background: rgba(239, 68, 68, 0.1);
      color: var(--fail);
      border: 1px solid rgba(239, 68, 68, 0.2);
    }

    .main-stats {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1.5rem;
    }

    .stat-item {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
    }

    .stat-label {
      display: flex;
      justify-content: space-between;
      font-size: 0.875rem;
      font-weight: 600;
      color: var(--fg-muted);
    }

    .stat-value {
      color: var(--fg);
      font-weight: 700;
    }

    .meter {
      height: 10px;
      background: rgba(255, 255, 255, 0.05);
      border-radius: 5px;
      overflow: hidden;
      position: relative;
    }

    .bar {
      position: absolute;
      left: 0;
      top: 0;
      bottom: 0;
      border-radius: 5px;
    }

    .tempbar {
      background: linear-gradient(90deg, #3b82f6 0%, #22c55e 40%, #facc15 75%, #ef4444 100%);
      background-repeat: no-repeat;
      background-position: left center;
    }

    .wearbar {
      background: linear-gradient(90deg, var(--fail) 0%, var(--warn) 30%, var(--ok) 100%);
    }

    .sub-info {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 1rem;
      padding-top: 1rem;
      border-top: 1px solid var(--border);
    }

    .info-box {
      display: flex;
      flex-direction: column;
      gap: 0.25rem;
    }

    .info-label {
      font-size: 0.7rem;
      color: var(--fg-muted);
      text-transform: uppercase;
      font-weight: 700;
    }

    .info-value {
      font-size: 0.9375rem;
      font-weight: 600;
      color: #d1d5db;
    }

    footer {
      margin-top: 4rem;
      padding-top: 2rem;
      border-top: 1px solid var(--border);
      display: flex;
      justify-content: space-between;
      color: var(--fg-muted);
      font-size: 0.875rem;
    }

    a {
      color: var(--accent);
      text-decoration: none;
      transition: color 0.2s;
    }

    a:hover {
      color: #60a5fa;
      text-decoration: underline;
    }

    @media (max-width: 960px) {
      .disk-grid {
        grid-template-columns: 1fr;
      }

      .header-stats {
        display: none;
      }
    }
  </style>
  <script>
    function reloadNow() { location.reload(); }
    setTimeout(reloadNow, 30000);
  </script>
</head>

<body>
  <div class="container">
    <header>
      <div class="title-group">
        <h1>Scrutiny Dashboard</h1>
        <p style="color:var(--fg-muted); font-size: 0.875rem; margin-top: 0.25rem;">Real-time storage health analytics
        </p>
      </div>
      <div class="header-stats">
        <div class="header-stat">
          <div class="label">Avg Temp</div>
          <div class="value"><?= is_numeric($avgT) ? $avgT . '°C' : '-' ?></div>
        </div>
        <div class="header-stat">
          <div class="label">Avg Life</div>
          <div class="value"><?= is_numeric($avgW) ? $avgW . '%' : '-' ?></div>
        </div>
      </div>
    </header>

    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="label">Total Disks</div>
        <div class="value"><?= htmlspecialchars((string) $total) ?></div>
      </div>
      <div class="kpi-card ok">
        <div class="label">Healthy</div>
        <div class="value"><?= htmlspecialchars((string) $cntOK) ?></div>
      </div>
      <div class="kpi-card warn">
        <div class="label">Warnings</div>
        <div class="value"><?= htmlspecialchars((string) $cntWARN) ?></div>
      </div>
      <div class="kpi-card fail">
        <div class="label">Failure</div>
        <div class="value"><?= htmlspecialchars((string) $cntFAIL) ?></div>
      </div>
    </div>

    <div class="disk-grid">
      <?php if (empty($rows)): ?>
        <p style="grid-column: 1/-1; text-align: center; padding: 4rem; color: var(--fg-muted);">No disks found.</p>
      <?php else:
        foreach ($rows as $r): ?>
          <div class="disk-card <?= $r['status_class'] ?>"
            onclick="window.open('<?= htmlspecialchars($r['detail_url']) ?>','_blank')">
            <div class="status-glow"></div>

            <div class="card-header">
              <div class="model-info">
                <div class="model-name" title="<?= htmlspecialchars($r['wwn']) ?>"><?= htmlspecialchars($r['model']) ?>
                </div>
                <div class="serial-number"><?= htmlspecialchars($r['serial']) ?></div>
              </div>
              <div class="badge <?= $r['status_class'] ?>"><?= $r['status_text'] ?></div>
            </div>

            <div class="main-stats">
              <div class="stat-item">
                <div class="stat-label">
                  <span>Temperature</span>
                  <span class="stat-value"><?= htmlspecialchars($r['temp_label']) ?></span>
                </div>
                <div class="meter">
                  <?php $tW = is_numeric($r['temp_bar']) ? $r['temp_bar'] : 0; ?>
                  <div class="bar tempbar"
                    style="width:<?= $tW ?>%; background-size:<?= ($tW > 0 ? (100 / $tW * 100) : 100) ?>% 100%;"></div>
                </div>
              </div>
              <div class="stat-item">
                <div class="stat-label">
                  <span>Life Remaining</span>
                  <span class="stat-value"><?= is_numeric($r['remain_pct']) ? $r['remain_pct'] . '%' : '-' ?></span>
                </div>
                <div class="meter">
                  <?php $lW = is_numeric($r['life_bar']) ? $r['life_bar'] : 0; ?>
                  <div class="bar wearbar" style="width:<?= $lW ?>%;"></div>
                </div>
              </div>
            </div>

            <div class="sub-info">
              <div class="info-box">
                <div class="info-label">Capacity</div>
                <div class="info-value"><?= htmlspecialchars($r['capacity']) ?></div>
              </div>
              <div class="info-box">
                <div class="info-label">Power On</div>
                <div class="info-value"><?= $r['poh'] ?></div>
              </div>
              <div class="info-box">
                <div class="info-label">Written</div>
                <div class="info-value" title="<?= $r['tbw'] ?>"><?= $r['tbw'] ?></div>
              </div>
            </div>
          </div>
        <?php endforeach; endif; ?>
    </div>

    <footer>
      <div>Updated at <?= htmlspecialchars(date('H:i:s')) ?></div>
      <div>
        Source: <a href="<?= htmlspecialchars($BASE) ?>" target="_blank"><?= htmlspecialchars($BASE) ?></a>
      </div>
    </footer>
  </div>
</body>

</html>
```