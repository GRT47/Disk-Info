<?php
/******************************
 * Scrutiny Simple Dashboard (FINAL v2)
 * - cfg로 '계산 규칙(convert_*)' 과 '표기 순서(display_invert_*)' 분리
 * - 온도 바 20–80°C 기준, 바 위치 들뜸 방지
 ******************************/
date_default_timezone_set('Asia/Seoul');
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$BASE = getenv('SCRUTINY_BASE') ?: 'http://192.168.1.2:6090';
$BASE = rtrim($BASE, '/');
$BASE = preg_replace('/\/api\/summary$/i', '', $BASE);
$BASE = rtrim($BASE, '/'); // 한 번 더 슬래시 제거
$TIMEOUT = 6;
$RETRY = 1;

$TEMP_MIN = 20; // °C
$TEMP_MAX = 70; // °C
$TEMP_CRIT = 65; // °C (NVMe 쓰로틀링 경고 온도)

$CFG_PATH = __DIR__ . '/wear_invert.cfg';

// AJAX History Handler
if (isset($_GET['action']) && $_GET['action'] === 'get_history' && isset($_GET['wwn'])) {
  header('Content-Type: application/json');
  $wwn = $_GET['wwn'];
  $details = http_get_json($BASE . '/api/device/' . rawurlencode($wwn) . '/details', 10, 1);
  $results = $details['data']['smart_results'] ?? [];

  $history = [];
  foreach ($results as $res) {
    if (!isset($res['date']))
      continue;
    $date = date('Y-m-d', strtotime($res['date']));
    $attrs = $res['attrs'] ?? [];

    // TBW
    $tbBytes = null;
    if (isset($attrs['data_units_written'])) {
      $tbBytes = (float) $attrs['data_units_written']['value'] * 512000.0;
    } elseif (isset($attrs['241'])) {
      $tbBytes = (float) $attrs['241']['raw_value'] * 512.0;
    }

    // Life
    $life = null;
    if (isset($attrs['percentage_used'])) {
      $life = 100 - (int) $attrs['percentage_used']['value'];
    } else {
      foreach ([177, 231, 173] as $id) {
        if (isset($attrs[$id])) {
          $life = (int) $attrs[$id]['value'];
          break;
        }
      }
    }

    if ($tbBytes !== null || $life !== null) {
      $history[] = [
        'date' => $date,
        'tbw_tb' => $tbBytes ? round($tbBytes / (1024 ** 4), 4) : null,
        'life' => $life
      ];
    }
  }
  // Sort by date ascending for Chart.js
  usort($history, fn($a, $b) => strcmp($a['date'], $b['date']));
  echo json_encode($history);
  exit;
}

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

  // Available Spare (가용 예비 공간 - NVMe 및 SATA 지원)
  $spareAttr = find_attr($latest, $meta, [
    ['display_name' => 'Available Spare'],           // NVMe 표준
    ['id' => 'available_spare'],                    // NVMe 표준 ID
    ['id' => 232],                                  // SATA: Available Reserved Space (Intel/Samsung 등)
    ['id' => 179],                                  // SATA: Used Reserved Block Count Total (Samsung 모델)
    ['id' => 251],                                  // SATA: Minimum Spares Remaining (Enterprise 모델)
    ['display_name' => 'Available Reserved Space'],
    ['display_name' => 'Minimum Spares Remaining']
  ]);
  $spareVal = $spareAttr['value'] ?? null;
  $spareThresh = $spareAttr['thresh'] ?? null;

  $isNVMe = (strtolower($device['device_protocol'] ?? '') === 'nvme' || str_contains(strtolower($device['model_name'] ?? ''), 'nvme'));

  // Bars
  $tBar = null;
  if (is_numeric($tempC)) {
    $tBar = ($tempC - $TEMP_MIN) / max(1, ($TEMP_MAX - $TEMP_MIN)) * 100.0;
    $tBar = max(0, min(100, $tBar));
  }
  $lifeBar = is_numeric($remain) ? max(0, min(100, $remain)) : null;

  // EOL Calculation (기존 사용량/시간 기반 예측)
  $eolDate = null;
  $eolRemainingDays = null;
  if (is_numeric($consumed) && $consumed > 0 && is_numeric($powerOnHours) && $powerOnHours > 0) {
    $hoursPerPercent = $powerOnHours / $consumed;
    $remainingHours = $remain * $hoursPerPercent;
    $eolRemainingDays = $remainingHours / 24;
    $eolTime = (int) (time() + ($eolRemainingDays * 86400));
    $eolDate = date('Y-m', $eolTime);
  }

  $rows[] = [
    'status_weight' => $statusWeight,
    'status_text' => $statusText,
    'status_class' => $statusClass,
    'model' => $device['model_name'] ?? '-',
    'protocol' => $isNVMe ? 'NVMe' : 'SATA',
    'serial' => $device['serial_number'] ?? '-',
    'wwn' => $device['wwn'] ?? '',
    'poc' => is_null($powerCycle) ? '-' : number_format((int) $powerCycle),
    'poh' => fmt_hours($powerOnHours),
    'capacity' => fmt_bytes($device['capacity'] ?? null),
    'tempC_val' => is_numeric($tempC) ? (float) $tempC : null,
    'temp_bar' => $tBar,
    'temp_label' => is_null($tempC) ? '-' : (number_format((float) $tempC, 0) . '°C'),
    'is_hot' => (is_numeric($tempC) && $tempC >= $TEMP_CRIT),
    'wear_text' => $text,
    'life_bar' => $lifeBar,
    'remain_pct' => $remain,
    'spare_pct' => $spareVal,
    'spare_thresh' => $spareThresh,
    'eol_date' => $eolDate,
    'eol_days' => $eolRemainingDays,
    'tbw' => $tbwFmt,
    'tbw_raw' => $tbBytes, // For JS tracking
    'detail_url' => $BASE . '/web/device/' . rawurlencode($device['wwn'] ?? ''),
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
  <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
  <style>
    :root {
      --bg: #020617;
      --card-bg: rgba(15, 23, 42, 0.6);
      --card-border: rgba(255, 255, 255, 0.08);
      --fg: #f8fafc;
      --fg-muted: #94a3b8;
      --accent: #38bdf8;
      --ok: #10b981;
      --warn: #f59e0b;
      --fail: #ef4444;
      --nvme: #ec4899;
      --sata: #3b82f6;
      --glass: blur(16px);
      --spring: cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    body {
      background: var(--bg);
      background-image:
        radial-gradient(circle at 0% 0%, rgba(56, 189, 248, 0.08) 0%, transparent 50%),
        radial-gradient(circle at 100% 100%, rgba(16, 185, 129, 0.05) 0%, transparent 50%);
      color: var(--fg);
      font-family: 'Inter', -apple-system, sans-serif;
      margin: 0;
      padding: 0;
      min-height: 100vh;
    }

    .container {
      max-width: 1400px;
      margin: 0 auto;
      padding: 2rem;
    }

    header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 3rem;
    }

    h1 {
      font-size: 2rem;
      font-weight: 900;
      letter-spacing: -0.04em;
      background: linear-gradient(135deg, #fff 0%, #94a3b8 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    .header-stats {
      display: flex;
      align-items: center;
      gap: 1.5rem;
      flex-wrap: wrap;
      justify-content: flex-end;
    }

    .header-stat {
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 0.1rem;
    }

    .header-stat .label {
      font-size: 0.65rem;
      font-weight: 700;
      color: var(--fg-muted);
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .header-stat .value {
      font-size: 1.15rem;
      font-weight: 900;
      color: #fff;
    }

    /* KPI Grid - Re-styled for New Concept */
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 1.5rem;
      margin-bottom: 3rem;
    }

    .kpi-card {
      background: var(--card-bg);
      backdrop-filter: var(--glass);
      border: 1px solid var(--card-border);
      border-radius: 1.5rem;
      padding: 1.25rem 1.5rem;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
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

    .kpi-card:hover {
      transform: translateY(-8px) scale(1.02);
      border-color: var(--accent);
      box-shadow: 0 25px 30px -5px rgba(0, 0, 0, 0.4);
    }

    .kpi-card:active {
      transform: translateY(-2px) scale(0.98);
      transition-duration: 0.1s;
    }

    .kpi-card .label {
      font-size: 0.75rem;
      font-weight: 700;
      color: var(--fg-muted);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      margin-bottom: 0.5rem;
    }

    .kpi-card .value {
      font-size: 1.75rem;
      font-weight: 900;
    }

    /* NEW DISK CARD V3 */
    .disk-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 1.5rem;
    }

    .disk-card {
      background: var(--card-bg);
      backdrop-filter: var(--glass);
      border: 1px solid var(--card-border);
      border-radius: 1.75rem;
      padding: 2rem;
      display: flex;
      flex-direction: column;
      gap: 2rem;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      position: relative;
      overflow: hidden;
      cursor: pointer;
    }

    /* I18N Smooth Transitions */
    [data-i18n] {
      transition: opacity 0.15s ease, transform 0.15s ease;
      display: inline-block;
    }

    .lang-switching [data-i18n] {
      opacity: 0;
      transform: translateY(2px);
    }

    .metric-value,
    .model-title,
    .info-item .value,
    .header-stat .value {
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .disk-card:hover {
      border-color: rgba(255, 255, 255, 0.2);
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
      transform: translateY(-4px) scale(1.005);
    }

    .disk-card:active {
      transform: scale(0.995);
      transition-duration: 0.1s;
    }

    /* Staggered Entrance */
    .disk-card {
      animation: cardEntrance 0.6s var(--spring) backwards;
      animation-delay: calc(var(--i) * 0.05s);
    }

    @keyframes cardEntrance {
      from {
        opacity: 0;
        transform: translateY(30px) scale(0.95);
      }

      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    /* Status Indicator Bar */
    .status-edge {
      position: absolute;
      top: 0;
      left: 0;
      bottom: 0;
      width: 4px;
    }

    /* DRAG HANDLE */
    .drag-handle {
      display: none;
      position: absolute;
      left: 10px;
      top: 50%;
      transform: translateY(-50%);
      z-index: 10;
      cursor: grab;
      color: var(--fg-muted);
      font-size: 1.25rem;
      align-items: center;
      transition: color 0.2s;
    }

    .is-reordering .drag-handle {
      display: flex;
    }

    .is-reordering .disk-card {
      padding-left: 3.5rem;
      cursor: default;
    }

    .drag-handle:hover {
      color: #fff;
    }

    .drag-handle:active {
      cursor: grabbing;
    }

    /* SORTABLE VISUALS */
    .sortable-ghost {
      opacity: 0.4;
      background: rgba(56, 189, 248, 0.05) !important;
      border: 2px dashed var(--accent) !important;
      box-shadow: 0 0 15px rgba(56, 189, 248, 0.2);
    }

    .sortable-chosen {
      transform: scale(1.02) rotate(1deg);
      box-shadow: 0 30px 60px -12px rgba(0, 0, 0, 0.6) !important;
      z-index: 100 !important;
      border-color: var(--accent) !important;
    }

    .sortable-drag {
      opacity: 1 !important;
      cursor: grabbing;
    }

    .badge-ok .status-edge {
      background: var(--ok);
      box-shadow: 0 0 20px var(--ok);
    }

    .badge-warn .status-edge {
      background: var(--warn);
      box-shadow: 0 0 20px var(--warn);
    }

    .badge-fail .status-edge {
      background: var(--fail);
      box-shadow: 0 0 20px var(--fail);
    }

    /* Card Header */
    .card-top {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 1rem;
    }

    .model-group {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
      flex: 1;
      min-width: 0;
    }

    .protocol-row {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .protocol-tag {
      display: inline-flex;
      align-items: center;
      padding: 0.25rem 0.75rem;
      border-radius: 9999px;
      font-size: 0.65rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .tag-nvme {
      background: rgba(236, 72, 153, 0.1);
      color: var(--nvme);
      border: 1px solid rgba(236, 72, 153, 0.2);
    }

    .tag-sata {
      background: rgba(59, 130, 246, 0.1);
      color: var(--sata);
      border: 1px solid rgba(59, 130, 246, 0.2);
    }

    .model-title {
      font-size: 1.25rem;
      font-weight: 850;
      color: #fff;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      line-height: 1.1;
      letter-spacing: -0.02em;
    }

    .serial-tag {
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
      font-size: 0.75rem;
      color: var(--fg-muted);
      opacity: 0.8;
    }

    .status-badge-v3 {
      padding: 0.25rem 0.6rem;
      border-radius: 0.5rem;
      font-size: 0.6rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .status-badge-v3.badge-ok {
      background: rgba(16, 185, 129, 0.1);
      color: var(--ok);
      border: 1px solid rgba(16, 185, 129, 0.2);
    }

    .status-badge-v3.badge-warn {
      background: rgba(245, 158, 11, 0.1);
      color: var(--warn);
      border: 1px solid rgba(245, 158, 11, 0.2);
    }

    .status-badge-v3.badge-fail {
      background: rgba(239, 68, 68, 0.1);
      color: var(--fail);
      border: 1px solid rgba(239, 68, 68, 0.2);
    }

    /* Core Metrics Section */
    .core-metrics {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 2rem;
      align-items: center;
    }

    .metric-gauge {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
    }

    .metric-info {
      display: flex;
      justify-content: space-between;
      align-items: flex-end;
    }

    .metric-label {
      font-size: 0.7rem;
      font-weight: 700;
      color: var(--fg-muted);
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .metric-value {
      font-size: 1.25rem;
      font-weight: 900;
      color: #fff;
      line-height: 1;
    }

    .gauge-track {
      height: 8px;
      background: rgba(255, 255, 255, 0.05);
      border-radius: 9999px;
      position: relative;
      overflow: hidden;
    }

    .gauge-fill {
      position: absolute;
      top: 0;
      left: 0;
      bottom: 0;
      border-radius: 9999px;
      transition: width 1s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .fill-temp {
      background: linear-gradient(90deg, #38bdf8, #10b981, #f59e0b, #ef4444);
      background-size: 300% 100%;
    }

    .fill-life {
      background: linear-gradient(90deg, var(--fail), var(--warn), var(--ok));
    }

    /* Info Grid (Secondary Metrics) */
    .info-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 1.5rem;
      padding-top: 1.5rem;
      border-top: 1px solid var(--card-border);
    }

    .info-item {
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
    }

    .info-item .label {
      font-size: 0.65rem;
      font-weight: 700;
      color: var(--fg-muted);
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .info-item .value {
      font-size: 1rem;
      font-weight: 800;
      color: rgba(255, 255, 255, 0.95);
    }

    .info-item .sub-value {
      font-size: 0.65rem;
      color: var(--fg-muted);
      font-weight: 500;
    }

    /* Responsiveness for View Modes */
    .view-list .disk-card {
      padding: 1.5rem 2.5rem;
    }

    @media (min-width: 1024px) {
      .view-list .disk-card {
        flex-direction: row;
        align-items: center;
        gap: 3rem;
      }

      .view-list .card-top {
        width: 300px;
        flex-shrink: 0;
      }

      .view-list .core-metrics {
        flex: 1;
      }

      .view-list .info-grid {
        border-top: none;
        border-left: 1px solid var(--card-border);
        padding-top: 0;
        padding-left: 2rem;
        grid-template-columns: repeat(3, 1fr);
        width: 500px;
        flex-shrink: 0;
      }
    }

    /* GRID Mode Adjustments */
    .view-grid .disk-grid {
      grid-template-columns: repeat(auto-fill, minmax(420px, 1fr));
      gap: 2rem;
    }

    .view-grid .info-grid {
      grid-template-columns: repeat(3, 1fr) !important;
      gap: 1rem;
    }

    .view-grid .info-item .value {
      font-size: 0.85rem;
    }

    /* VIEW TOGGLE & CONTROLS */
    .view-toggle {
      display: flex;
      background: rgba(255, 255, 255, 0.03);
      padding: 0.25rem;
      border-radius: 0.75rem;
      border: 1px solid var(--card-border);
    }

    .view-toggle button {
      padding: 0.4rem 0.8rem;
      border: none;
      background: transparent;
      color: var(--fg-muted);
      font-size: 0.7rem;
      font-weight: 700;
      border-radius: 0.5rem;
      cursor: pointer;
      transition: all 0.2s;
    }

    .view-toggle button:hover {
      color: #fff;
      transform: scale(1.05);
    }

    .view-toggle button:active {
      transform: scale(0.95);
    }

    .view-toggle button.active {
      background: var(--accent);
      color: var(--bg);
      box-shadow: 0 4px 12px rgba(56, 189, 248, 0.3);
    }


    footer {
      margin-top: 4rem;
      padding: 2rem 0;
      border-top: 1px solid var(--card-border);
      display: flex;
      justify-content: space-between;
      color: var(--fg-muted);
      font-size: 0.75rem;
      font-weight: 500;
    }

    a {
      color: var(--accent);
      text-decoration: none;
    }

    a:hover {
      text-decoration: underline;
    }

    footer {
      margin-top: 1rem;
      padding-top: 0.5rem;
      border-top: 1px solid var(--border);
      display: flex;
      justify-content: space-between;
      color: var(--fg-muted);
      font-size: 0.8rem;
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


    @media (max-width: 1100px) {
      .view-list .disk-card {
        grid-template-columns: 200px 1fr;
        gap: 1rem;
      }

      .view-list .sub-info {
        display: none;
      }
    }

    @media (max-width: 768px) {
      .disk-card {
        grid-template-columns: 1fr;
        gap: 1rem;
      }

      .header-stats {
        display: none;
      }
    }

    /* Duplicated Toggle Removed */

    /* ANALYTICS VIEW STYLES */
    #analytics-view {
      display: none;
    }

    .analytics-grid>* {
      animation: analyticsEntrance 0.5s var(--spring) backwards;
    }

    .analytics-grid>*:nth-child(1) {
      animation-delay: 0.1s;
    }

    .analytics-grid>*:nth-child(2) {
      animation-delay: 0.2s;
    }

    .analytics-grid>*:nth-child(3) {
      animation-delay: 0.3s;
    }

    @keyframes analyticsEntrance {
      from {
        opacity: 0;
        transform: translateY(20px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .analytics-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1.5rem;
      margin-top: 2rem;
    }

    .chart-card {
      background: var(--card-bg);
      backdrop-filter: var(--glass);
      border: 1px solid var(--border);
      border-radius: 20px;
      padding: 1.5rem;
    }

    .chart-card.full {
      grid-column: 1 / -1;
    }

    .chart-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 2rem;
      flex-wrap: wrap;
      gap: 1.5rem;
    }

    .chart-title {
      font-size: 1rem;
      font-weight: 700;
      color: var(--fg-muted);
    }

    .analytics-controls {
      display: flex;
      gap: 1rem;
      align-items: center;
      margin-bottom: 2rem;
      background: var(--card-bg);
      padding: 1rem;
      border-radius: 12px;
      border: 1px solid var(--border);
    }

    .analytics-controls select {
      background: #1f2937;
      border: 1px solid var(--border);
      color: #fff;
      padding: 0.5rem;
      border-radius: 8px;
      flex: 1;
    }
  </style>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
    function reloadNow() {
      location.reload();
    }
    // setTimeout(reloadNow, 30000); // 사용자 요청으로 자동 새로고침 비활성화

    function setView(mode) {
      const cont = document.querySelector('.container');
      const btns = document.querySelectorAll('.view-toggle[data-view-group] button');

      if (mode === 'grid') {
        cont.classList.add('view-grid');
        cont.classList.remove('view-list');
      } else {
        cont.classList.add('view-list');
        cont.classList.remove('view-grid');
      }

      btns.forEach(b => {
        b.classList.toggle('active', b.dataset.mode === mode);
      });

      localStorage.setItem('scrutiny_view_mode', mode);
    }

    function toggleReorder() {
      const cont = document.querySelector('.container');
      const btn = document.getElementById('sort-btn');
      const active = cont.classList.toggle('is-reordering');
      btn.classList.toggle('active', active);
      btn.innerText = active ? 'Done' : 'Sort';
    }

    function saveOrder() {
      const list = document.querySelector('.disk-grid');
      const order = Array.from(list.querySelectorAll('.disk-card')).map(el => el.dataset.wwn);
      localStorage.setItem('scrutiny_disk_order', JSON.stringify(order));
    }

    const i18n = {
      ko: {
        title: 'Scrutiny 대시보드',
        subtitle: '드라이브 상태 및 수명 실시간 모니터링',
        total_disks: '총 드라이브 수',
        healthy: '정상',
        warnings: '주의',
        failure: '위험',
        avg_temp: '평균 온도',
        avg_life: '평균 잔여 수명',
        sort: '정렬',
        done: '완료',
        capacity: '용량',
        written: '누적 쓰기량',
        power_on: '총 사용 시간',
        temp: '온도',
        life: '잔여 수명',
        updated_at: '업데이트 시간: ',
        source: '원본 데이터: ',
        no_disks: '인식된 드라이브가 없습니다.',
        year_suffix: '년',
        ok: '정상',
        warn: '주의',
        fail: '위험',
        spare: '예비 영역',
        write_delta: '일일 쓰기량',
        eol: '예상 수명 종료일',
        analytics: '상세 분석',
        show_dashboard: '대시보드 복귀',
        chart_cum: '누적 쓰기량 추이 (TBW)',
        chart_delta: '기간별 쓰기 변화 (GB)',
        chart_life: '잔여 수명 추이 (%)',
        disk_label: '대상 드라이브:',
        p_1d: '24시간',
        p_1w: '1주',
        p_1m: '1개월',
        p_1y: '1년'
      },
      en: {
        title: 'Scrutiny Dashboard',
        subtitle: 'Real-time storage health analytics',
        total_disks: 'Total Disks',
        healthy: 'Healthy',
        warnings: 'Warnings',
        failure: 'Failure',
        avg_temp: 'Avg Temp',
        avg_life: 'Avg Life',
        sort: 'Sort',
        done: 'Done',
        capacity: 'Capacity',
        written: 'Written',
        power_on: 'Power On',
        temp: 'Temp',
        life: 'Life',
        updated_at: 'Updated at ',
        source: 'Source: ',
        no_disks: 'No disks found.',
        year_suffix: 'y',
        ok: 'OK',
        warn: 'WARN',
        fail: 'FAIL',
        spare: 'Spare Area',
        write_delta: 'Daily Write',
        eol: 'Estimated EOL',
        analytics: 'Analytics',
        show_dashboard: 'Show Dashboard',
        chart_cum: 'Cumulative Usage (TBW)',
        chart_delta: 'Usage Delta (GB)',
        chart_life: 'Life Intensity (%)',
        disk_label: 'Disk:',
        p_1d: '1D',
        p_1w: '1W',
        p_1m: '1M',
        p_1y: '1Y'
      }
    };

    function setLanguage(lang) {
      if (document.body.classList.contains('lang-switching')) return;

      const trans = i18n[lang];
      if (!trans) return;

      document.body.classList.add('lang-switching');

      setTimeout(() => {
        document.querySelectorAll('[data-i18n]').forEach(el => {
          const key = el.dataset.i18n;
          if (trans[key]) el.innerText = trans[key];
        });

        // Special cases for units and complex labels
        document.querySelectorAll('.year-unit').forEach(el => el.innerText = trans.year_suffix);
        document.querySelectorAll('.status-badge-v3').forEach(el => {
          const status = el.classList.contains('badge-ok') ? 'ok' : (el.classList.contains('badge-warn') ? 'warn' : 'fail');
          el.innerText = trans[status];
        });

        // Update Sort button text if reordering
        const sortBtn = document.getElementById('sort-btn');
        if (document.querySelector('.container').classList.contains('is-reordering')) {
          sortBtn.innerText = trans.done;
        } else {
          sortBtn.innerText = trans.sort;
        }

        // Trigger analytics button text update if active
        const analyticsBtn = document.getElementById('analytics-btn');
        if (document.getElementById('analytics-view').style.display === 'block') {
          analyticsBtn.innerText = trans.show_dashboard;
        } else {
          analyticsBtn.innerText = trans.analytics;
        }

        // Update active class on buttons
        document.querySelectorAll('.lang-toggle button').forEach(b => {
          b.classList.toggle('active', b.dataset.lang === lang);
        });

        localStorage.setItem('scrutiny_lang', lang);

        // Fade in
        setTimeout(() => {
          document.body.classList.remove('lang-switching');
        }, 50);
      }, 150);
    }

    function applyOrder() {
      const list = document.querySelector('.disk-grid');
      const saved = localStorage.getItem('scrutiny_disk_order');
      if (!saved) return;

      const order = JSON.parse(saved);
      const items = Array.from(list.querySelectorAll('.disk-card'));

      order.forEach(wwn => {
        const item = items.find(el => el.dataset.wwn === wwn);
        if (item) list.appendChild(item);
      });
    }

    window.addEventListener('DOMContentLoaded', () => {
      const savedMode = localStorage.getItem('scrutiny_view_mode') || 'list';
      setView(savedMode);

      const savedLang = localStorage.getItem('scrutiny_lang') || 'ko';
      setLanguage(savedLang);

      applyOrder();

      new Sortable(document.querySelector('.disk-grid'), {
        animation: 250,
        handle: '.drag-handle',
        ghostClass: 'sortable-ghost',
        chosenClass: 'sortable-chosen',
        dragClass: 'sortable-drag',
        onEnd: saveOrder,
        easing: "cubic-bezier(0.2, 0, 0, 1)"
      });

      // Daily Write Tracking
      document.querySelectorAll('.disk-card').forEach(card => {
        const wwn = card.dataset.wwn;
        const currentTbw = parseFloat(card.dataset.tbwRaw);
        if (!isNaN(currentTbw) && currentTbw > 0) {
          const key = `tbw_history_${wwn}`;
          const lastTbw = localStorage.getItem(key);
          if (lastTbw) {
            const diff = currentTbw - parseFloat(lastTbw);
            if (diff > 0) {
              const gb = diff / (1024 ** 3);
              const label = card.querySelector('.write-delta-val');
              if (label) label.innerText = `(+${gb.toFixed(1)}GB)`;
            }
          }
          localStorage.setItem(key, currentTbw);
        }
      });
    });

    let currentCharts = {};
    let fullHistory = [];

    function toggleView(view) {
      const db = document.getElementById('dashboard-view');
      const an = document.getElementById('analytics-view');
      const btn = document.getElementById('analytics-btn');
      const lang = localStorage.getItem('scrutiny_lang') || 'ko';
      const trans = i18n[lang];

      if (view === 'analytics') {
        const isOpening = an.style.display !== 'block';
        db.style.display = isOpening ? 'none' : 'block';
        an.style.display = isOpening ? 'block' : 'none';

        // Hide/Show Sort and View toggle
        const sortToggle = document.getElementById('sort-btn').parentElement;
        const viewGroupToggle = document.querySelector('.view-toggle[data-view-group]');

        if (sortToggle) sortToggle.style.display = isOpening ? 'none' : 'flex';
        if (viewGroupToggle) viewGroupToggle.style.display = isOpening ? 'none' : 'flex';

        // Update button text from i18n
        btn.innerText = isOpening ? trans.show_dashboard : trans.analytics;

        // Update button style
        if (isOpening) {
          btn.style.background = '#4b5563';
          btn.style.color = '#fff';
          const selector = document.getElementById('disk-selector');
          loadAnalytics(selector.value);
        } else {
          btn.style.background = '';
          btn.style.color = '';
        }
      } else {
        db.style.display = 'block';
        an.style.display = 'none';
      }
    }

    async function loadAnalytics(wwn) {
      const resp = await fetch(`?action=get_history&wwn=${encodeURIComponent(wwn)}`);
      fullHistory = await resp.json();
      renderCharts(fullHistory.slice(-7)); // Default 1W
    }

    function changePeriod(days) {
      document.querySelectorAll('#period-toggle button').forEach(b => {
        b.classList.toggle('active', parseInt(b.dataset.p) === days);
      });
      // To show delta for 1D, we need at least 2 points (yesterday + today)
      renderCharts(fullHistory.slice(-Math.max(2, days)));
    }

    function renderCharts(data) {
      const labels = data.map(d => d.date);
      const life = data.map(d => d.life);
      const cum = data.map(d => d.tbw_tb);
      const delta = data.map((d, i) => {
        if (i === 0) return 0;
        return Math.max(0, (d.tbw_tb - data[i - 1].tbw_tb) * 1024).toFixed(2);
      });

      if (currentCharts.cum) currentCharts.cum.destroy();
      if (currentCharts.delta) currentCharts.delta.destroy();
      if (currentCharts.life) currentCharts.life.destroy();

      const options = {
        responsive: true, plugins: { legend: { display: false } },
        scales: { x: { grid: { display: false } }, y: { grid: { color: 'rgba(255,255,255,0.05)' } } }
      };

      currentCharts.cum = new Chart(document.getElementById('cumChart'), {
        type: 'line', data: { labels, datasets: [{ label: 'TBW', data: cum, borderColor: '#3b82f6', fill: true, backgroundColor: 'rgba(59,130,246,0.1)', tension: 0.4 }] },
        options: options
      });
      currentCharts.delta = new Chart(document.getElementById('deltaChart'), {
        type: 'bar', data: { labels, datasets: [{ label: 'GB', data: delta, backgroundColor: '#10b981' }] },
        options: options
      });
      currentCharts.life = new Chart(document.getElementById('lifeChart'), {
        type: 'line', data: { labels, datasets: [{ label: '%', data: life, borderColor: '#f472b6', borderWidth: 3, pointRadius: 0 }] },
        options: { ...options, scales: { ...options.scales, y: { min: 0, max: 100 } } }
      });
    }
  </script>
</head>

<body>
  <div class="container">
    <header>
      <div class="title-group">
        <h1 data-i18n="title">Scrutiny Dashboard</h1>
        <p data-i18n="subtitle" style="color:var(--fg-muted); font-size: 0.875rem; margin-top: 0.25rem;">Real-time
          storage health analytics
        </p>
      </div>
      <div class="header-stats">
        <div class="view-toggle lang-toggle">
          <button onclick="setLanguage('ko')" data-lang="ko">KO</button>
          <button onclick="setLanguage('en')" data-lang="en">EN</button>
        </div>
        <div class="view-toggle">
          <button id="sort-btn" data-i18n="sort" onclick="toggleReorder()">Sort</button>
        </div>
        <div class="view-toggle" data-view-group>
          <button onclick="setView('list')" data-mode="list">List</button>
          <button onclick="setView('grid')" data-mode="grid">Card</button>
        </div>
        <div class="view-toggle">
          <button onclick="toggleView('analytics')" data-i18n="analytics" id="analytics-btn">Analytics</button>
        </div>
        <div class="header-stat">
          <div class="label" data-i18n="avg_temp">Avg Temp</div>
          <div class="value"><?= is_numeric($avgT) ? $avgT . '°C' : '-' ?></div>
        </div>
        <div class="header-stat">
          <div class="label" data-i18n="avg_life">Avg Life</div>
          <div class="value"><?= is_numeric($avgW) ? $avgW . '%' : '-' ?></div>
        </div>
      </div>
    </header>

    <!-- DASHBOARD VIEW -->
    <div id="dashboard-view">
      <div class="kpi-grid">
        <div class="kpi-card">
          <div class="label" data-i18n="total_disks">Total Disks</div>
          <div class="value"><?= htmlspecialchars((string) $total) ?></div>
        </div>
        <div class="kpi-card ok">
          <div class="label" data-i18n="healthy">Healthy</div>
          <div class="value"><?= htmlspecialchars((string) $cntOK) ?></div>
        </div>
        <div class="kpi-card warn">
          <div class="label" data-i18n="warnings">Warnings</div>
          <div class="value"><?= htmlspecialchars((string) $cntWARN) ?></div>
        </div>
        <div class="kpi-card fail">
          <div class="label" data-i18n="failure">Failure</div>
          <div class="value"><?= htmlspecialchars((string) $cntFAIL) ?></div>
        </div>
      </div>

      <div class="disk-grid">
        <?php if (empty($rows)): ?>
          <div style="grid-column: 1 / -1; padding: 4rem; text-align: center; color: var(--fg-muted);">
            <div style="font-size: 3rem; margin-bottom: 1rem;">🔍</div>
            <p data-i18n="no_disks">No devices found / Scrutiny API error.</p>
          </div>
        <?php else:
          $idx = 0;
          foreach ($rows as $r): ?>
            <div class="disk-card <?= $r['status_class'] ?>" data-wwn="<?= htmlspecialchars($r['wwn']) ?>"
              style="--i: <?= $idx++ ?>" onclick="location.href='<?= $r['detail_url'] ?>'">
              <div class="status-edge"></div>
              <div class="drag-handle" onclick="event.stopPropagation()">⋮⋮</div>

              <div class="card-top">
                <div class="model-group">
                  <div class="protocol-row">
                    <span class="protocol-tag tag-<?= strtolower($r['protocol']) ?>"><?= $r['protocol'] ?></span>
                    <div class="status-badge-v3 <?= $r['status_class'] ?>">
                      <?= $r['status_text'] ?>
                    </div>
                  </div>
                  <div class="model-title"><?= htmlspecialchars($r['model']) ?></div>
                  <div class="serial-tag"><?= htmlspecialchars($r['serial']) ?></div>
                </div>
              </div>

              <div class="core-metrics">
                <!-- Life Metric -->
                <div class="metric-gauge">
                  <div class="metric-info">
                    <span class="metric-label" data-i18n="life">Remaining Life</span>
                    <span class="metric-value"><?= is_numeric($r['remain_pct']) ? $r['remain_pct'] . '%' : '-' ?></span>
                  </div>
                  <div class="gauge-track">
                    <?php $lW = is_numeric($r['life_bar']) ? $r['life_bar'] : 0; ?>
                    <div class="gauge-fill fill-life" style="width:<?= $lW ?>%;"></div>
                  </div>
                </div>

                <!-- Temp Metric -->
                <div class="metric-gauge">
                  <div class="metric-info">
                    <span class="metric-label" data-i18n="temp">Temperature</span>
                    <span class="metric-value"><?= htmlspecialchars($r['temp_label']) ?></span>
                  </div>
                  <div class="gauge-track">
                    <?php $tW = is_numeric($r['temp_bar']) ? $r['temp_bar'] : 0; ?>
                    <div class="gauge-fill fill-temp" style="width:<?= $tW ?>%;"></div>
                  </div>
                </div>
              </div>

              <div class="info-grid">
                <div class="info-item">
                  <span class="label" data-i18n="capacity">Total Capacity</span>
                  <span class="value"><?= htmlspecialchars($r['capacity']) ?></span>
                </div>
                <div class="info-item">
                  <span class="label" data-i18n="written">Total Written</span>
                  <span class="value"><?= explode(' (', $r['tbw'])[0] ?></span>
                  <span
                    class="sub-value"><?= strpos($r['tbw'], '(') !== false ? '(' . explode(' (', $r['tbw'])[1] : '' ?></span>
                </div>
                <div class="info-item">
                  <span class="label" data-i18n="power_on">Power On Time</span>
                  <span class="value"><?= explode(' (', $r['poh'])[0] ?></span>
                  <span
                    class="sub-value"><?= strpos($r['poh'], '(') !== false ? '(' . explode(' (', $r['poh'])[1] : '' ?></span>
                </div>
                <div class="info-item">
                  <span class="label" data-i18n="spare">Spare Area</span>
                  <span class="value"><?= is_numeric($r['spare_pct']) ? $r['spare_pct'] . '%' : '-' ?></span>
                </div>
                <div class="info-item">
                  <span class="label" data-i18n="eol">Estimated EOL</span>
                  <span class="value"
                    style="color: <?= ($r['eol_days'] && $r['eol_days'] < 365) ? 'var(--warn)' : 'inherit' ?>;">
                    <?= $r['eol_date'] ?: '-' ?>
                  </span>
                  <span class="sub-value"><?= $r['eol_days'] ? number_format($r['eol_days']) . ' days left' : '' ?></span>
                </div>
              </div>
            </div>
          <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- ANALYTICS VIEW -->
    <div id="analytics-view">
      <div class="analytics-controls">
        <span style="font-weight:700;" data-i18n="disk_label">Disk:</span>
        <select id="disk-selector" onchange="loadAnalytics(this.value)">
          <?php foreach ($rows as $r): ?>
            <option value="<?= htmlspecialchars($r['wwn']) ?>"><?= htmlspecialchars($r['model']) ?>
              (<?= htmlspecialchars($r['serial']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <div class="view-toggle" id="period-toggle">
          <button onclick="changePeriod(1)" data-p="1" data-i18n="p_1d">1D</button>
          <button onclick="changePeriod(7)" class="active" data-p="7" data-i18n="p_1w">1W</button>
          <button onclick="changePeriod(30)" data-p="30" data-i18n="p_1m">1M</button>
          <button onclick="changePeriod(365)" data-p="365" data-i18n="p_1y">1Y</button>
        </div>
      </div>

      <div class="analytics-grid">
        <div class="chart-card full">
          <div class="chart-header">
            <div class="chart-title" data-i18n="chart_cum">Cumulative Usage (TBW)</div>
          </div>
          <canvas id="cumChart" height="100"></canvas>
        </div>
        <div class="chart-card">
          <div class="chart-header">
            <div class="chart-title" data-i18n="chart_delta">Usage Delta (GB)</div>
          </div>
          <canvas id="deltaChart" height="180"></canvas>
        </div>
        <div class="chart-card">
          <div class="chart-header">
            <div class="chart-title" data-i18n="chart_life">Life Intensity (%)</div>
          </div>
          <canvas id="lifeChart" height="180"></canvas>
        </div>
      </div>
    </div>

    <footer>
      <div><span data-i18n="updated_at">Updated at </span><?= htmlspecialchars(date('H:i:s')) ?></div>
      <div>
        <span data-i18n="source">Source: </span><a href="<?= htmlspecialchars($BASE) ?>"
          target="_blank"><?= htmlspecialchars($BASE) ?></a>
      </div>
    </footer>
  </div>
</body>

</html>
```