<?php
/******************************
 * Scrutiny Simple Dashboard (FINAL v2)
 * - cfg로 '계산 규칙(convert_*)' 과 '표기 순서(display_invert_*)' 분리
 * - 온도 바 20–80°C 기준, 바 위치 들뜸 방지
 ******************************/
date_default_timezone_set('Asia/Seoul');
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$BASE    = 'http://192.168.1.2:6090'; // <-- Scrutiny 주소
$TIMEOUT = 6;
$RETRY   = 1;

$TEMP_MIN = 20; // °C
$TEMP_MAX = 80; // °C

$CFG_PATH = __DIR__ . '/wear_invert.cfg';

/* ---------- 공통 유틸 ---------- */
function http_get_json(string $url, int $timeout = 5, int $retry = 0): array {
  $attempt = 0;
  do {
    $attempt++;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => $timeout, CURLOPT_TIMEOUT => $timeout,
      CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch); $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    if ($err || $code < 200 || $code >= 300 || $body === false) {
      if ($attempt <= $retry + 1) { usleep(200*1000); continue; }
      return ['_error' => "GET $url 실패 (code=$code, err=$err)"];
    }
    $json = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) return ['_error' => "JSON 파싱 실패: ".json_last_error_msg()];
    return $json ?? [];
  } while ($attempt <= $retry + 1);
}
function fmt_bytes($bytes): string {
  if ($bytes === null || $bytes === '' || !is_numeric($bytes)) return '-';
  $units=['B','KB','MB','GB','TB','PB']; $i=0; $bytes=(float)$bytes;
  while ($bytes>=1024 && $i<count($units)-1){ $bytes/=1024; $i++; }
  return sprintf(($i>=3?'%.2f %s':'%.0f %s'), $bytes, $units[$i]);
}
function fmt_hours($hours): string {
  if ($hours === null || $hours === '' || !is_numeric($hours)) return '-';
  $hours=(int)$hours; $d=intdiv($hours,24); $h=$hours%24; $y=$hours/(24*365);
  $main=($d>0)?"{$d}d {$h}h":"{$h}h"; return sprintf("%s (%.1fy)", $main, $y);
}
function find_attr(array $latest, array $metadata, array $cands): ?array {
  $attrs = $latest['attrs'] ?? []; if (!is_array($attrs) || !$attrs) return null;
  if ($metadata) {
    foreach ($metadata as $id=>$meta) {
      $name=strtolower($meta['display_name'] ?? '');
      foreach ($cands as $c){ if(isset($c['display_name']) && strtolower($c['display_name'])===$name){
        if (isset($attrs[$id]) && is_array($attrs[$id])) return $attrs[$id]+['_id'=>(string)$id,'_name'=>$meta['display_name']];
      }}
    }
  }
  foreach ($cands as $c) {
    if (isset($c['id'])) {
      $idStr=(string)$c['id']; if (isset($attrs[$idStr])) return $attrs[$idStr]+['_id'=>$idStr];
      if (isset($attrs[(int)$c['id']])) return $attrs[(int)$c['id']]+['_id'=>(string)$c['id']];
    }
  }
  return null;
}
function latest_smart(array $results): array {
  if (!is_array($results)) return [];
  usort($results, function($a,$b){
    $da=strtotime($a['date']??$a['collector_date']??'1970-01-01');
    $db=strtotime($b['date']??$b['collector_date']??'1970-01-01');
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
function load_cfg(string $path): array {
  $cfg=[
    'conv_model'=>[], 'conv_serial'=>[], 'conv_wwn'=>[],
    'conv_r_model'=>[], 'conv_r_serial'=>[], 'conv_r_wwn'=>[],
    'disp_model'=>[], 'disp_serial'=>[], 'disp_wwn'=>[],
    'disp_r_model'=>[], 'disp_r_serial'=>[], 'disp_r_wwn'=>[],
  ];
  if (!is_file($path)) return $cfg;
  $lines=@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if ($lines===false) return $cfg;

  foreach ($lines as $line) {
    $line=trim($line); if ($line==='' || str_starts_with($line,'#')) continue;
    // convert_*
    if (preg_match('/^convert_(model|serial|wwn)\s*=\s*(.+)$/i',$line,$m)) {
      $cfg['conv_'.$m[1]][] = trim($m[2]); continue;
    }
    if (preg_match('/^convert_regex_(model|serial|wwn)\s*=\s*(\/.+\/[imsxuADSUXJ]*)\s*$/i',$line,$m)) {
      $cfg['conv_r_'.$m[1]][] = trim($m[2]); continue;
    }
    // display_invert_*
    if (preg_match('/^display_invert_(model|serial|wwn)\s*=\s*(.+)$/i',$line,$m)) {
      $cfg['disp_'.$m[1]][] = trim($m[2]); continue;
    }
    if (preg_match('/^display_invert_regex_(model|serial|wwn)\s*=\s*(\/.+\/[imsxuADSUXJ]*)\s*$/i',$line,$m)) {
      $cfg['disp_r_'.$m[1]][] = trim($m[2]); continue;
    }
  }
  return $cfg;
}
function match_any(string $val, array $list, array $rlist): bool {
  if (in_array($val, $list, true)) return true;
  foreach ($rlist as $rx) { if (@preg_match($rx, $val)) return true; }
  return false;
}
function need_convert(array $dev, array $cfg): bool {
  $model=$dev['model_name'] ?? ''; $serial=$dev['serial_number'] ?? '';
  $wwn=$dev['wwn'] ?? ($dev['device']['wwn'] ?? '');
  return match_any($model,$cfg['conv_model'],$cfg['conv_r_model'])
      || match_any($serial,$cfg['conv_serial'],$cfg['conv_r_serial'])
      || match_any($wwn,$cfg['conv_wwn'],$cfg['conv_r_wwn']);
}
function need_display_invert(array $dev, array $cfg): bool {
  $model=$dev['model_name'] ?? ''; $serial=$dev['serial_number'] ?? '';
  $wwn=$dev['wwn'] ?? ($dev['device']['wwn'] ?? '');
  return match_any($model,$cfg['disp_model'],$cfg['disp_r_model'])
      || match_any($serial,$cfg['disp_serial'],$cfg['disp_r_serial'])
      || match_any($wwn,$cfg['disp_wwn'],$cfg['disp_r_wwn']);
}

/* ---------- 데이터 수집/가공 ---------- */
$cfg = load_cfg($CFG_PATH);

$summaryResp = http_get_json($BASE.'/api/summary', $TIMEOUT, $RETRY);
$summary = $summaryResp['data']['summary'] ?? ($summaryResp['summary'] ?? []);
if (!is_array($summary)) $summary = [];
$rows=[];

foreach ($summary as $wwnKey=>$entry){
  $wwn=is_string($wwnKey)?$wwnKey:($entry['device']['wwn'] ?? null);
  if (!$wwn) continue;

  $detailsResp=http_get_json($BASE.'/api/device/'.rawurlencode($wwn).'/details', $TIMEOUT, $RETRY);
  $data=$detailsResp['data'] ?? []; $device=$data['device'] ?? ($entry['device'] ?? []);
  if (!isset($device['wwn']) && $wwn) $device['wwn']=$wwn;

  $smartResults=$data['smart_results'] ?? [];
  $meta=$data['metadata'] ?? ($detailsResp['metadata'] ?? []);
  $latest=latest_smart($smartResults);

  // 상태
  $statusRaw=$latest['Status'] ?? $latest['status'] ?? null;
  $statusText='-'; $statusClass='badge-neutral'; $statusWeight=0;
  if (is_numeric($statusRaw)){
    if ($statusRaw==0){$statusText='OK';$statusClass='badge-ok';$statusWeight=0;}
    elseif($statusRaw==1){$statusText='WARN';$statusClass='badge-warn';$statusWeight=1;}
    elseif($statusRaw>=2){$statusText='FAIL';$statusClass='badge-fail';$statusWeight=2;}
  } elseif (is_string($statusRaw)){
    $s=strtolower($statusRaw);
    if (str_contains($s,'ok')||str_contains($s,'pass')) {$statusText='OK';$statusClass='badge-ok';$statusWeight=0;}
    elseif (str_contains($s,'warn')||str_contains($s,'advis')) {$statusText='WARN';$statusClass='badge-warn';$statusWeight=1;}
    elseif (str_contains($s,'fail')||str_contains($s,'crit')) {$statusText='FAIL';$statusClass='badge-fail';$statusWeight=2;}
    else {$statusText=strtoupper($statusRaw);$statusWeight=1;}
  }

  $powerOnHours=$latest['power_on_hours'] ?? null;
  $powerCycle  =$latest['power_cycle_count'] ?? null;
  $tempC       =$latest['temp'] ?? ($latest['temperature'] ?? null);

  // Wear
  $wearAttr=find_attr($latest,$meta,[
    ['display_name'=>'Wear Range Delta'], ['id'=>173], ['id'=>177], ['display_name'=>'Wear Leveling Count'],
  ]);
  $raw=$wearAttr['raw_value'] ?? ($wearAttr['value'] ?? null);
  $remain=null; $consumed=null; $text='-';
  if (is_numeric($raw)){
    $raw=(int)max(0,min(100,$raw));
    $convert = need_convert($device,$cfg); // true => 남은%=100-raw (raw=소모)
    $remain  = $convert ? (100-$raw) : $raw;
    $consumed= 100 - $remain;
    $invertDisp = need_display_invert($device,$cfg);
    $text = $invertDisp
      ? sprintf("소모 %d%% (남은 %d%%)", $consumed, $remain)
      : sprintf("남은 %d%% (소모 %d%%)", $remain, $consumed);
  }

  // TBW
  $tbwAttr=find_attr($latest,$meta,[
    ['display_name'=>'Total LBAs Written'], ['id'=>241],
    ['display_name'=>'Data Units Written'], ['display_name'=>'Host Writes'],
  ]);
  $nameLower=strtolower($tbwAttr['_name'] ?? (($tbwAttr['_id'] ?? '')!==''?('id '.$tbwAttr['_id']):''));
  $tbwRaw=$tbwAttr['raw_value'] ?? ($tbwAttr['value'] ?? null);
  $tbwFmt='-'; $tbBytes=null;
  if (is_numeric($tbwRaw)){
    if (str_contains($nameLower,'data units written')) $tbBytes=(float)$tbwRaw*512000.0;
    elseif (str_contains($nameLower,'total lbas written') || ($tbwAttr['_id'] ?? '')==='241') $tbBytes=(float)$tbwRaw*512.0;
    elseif (str_contains($nameLower,'host writes')) $tbwFmt=number_format((float)$tbwRaw).' (raw)';
    if ($tbBytes!==null){
      $gb=$tbBytes/(1024**3); $tb=$tbBytes/(1024**4);
      $tbwFmt=sprintf("%.1f GB (%.2f TB)", $gb, $tb);
    }
  }

  // Bars
  $tBar=null; if (is_numeric($tempC)){
    $tBar = ($tempC - $TEMP_MIN)/max(1,($TEMP_MAX-$TEMP_MIN))*100.0;
    $tBar = max(0,min(100,$tBar));
  }
  $lifeBar = is_numeric($remain) ? max(0,min(100,$remain)) : null;

  $rows[]=[
    'status_weight'=>$statusWeight, 'status_text'=>$statusText, 'status_class'=>$statusClass,
    'model'=>$device['model_name'] ?? '-', 'serial'=>$device['serial_number'] ?? '-', 'wwn'=>$device['wwn'] ?? '',
    'poc'=>is_null($powerCycle)?'-':number_format((int)$powerCycle), 'poh'=>fmt_hours($powerOnHours),
    'capacity'=>fmt_bytes($device['capacity'] ?? null),
    'tempC_val'=>is_numeric($tempC)?(float)$tempC:null, 'temp_bar'=>$tBar,
    'temp_label'=>is_null($tempC)?'-':(number_format((float)$tempC,0).'°C'),
    'wear_text'=>$text, 'life_bar'=>$lifeBar, 'remain_pct'=>$remain,
    'tbw'=>$tbwFmt, 'detail_url'=>$BASE.'/device/'.rawurlencode($device['wwn'] ?? ''),
  ];
}

/* ---------- 통계 ---------- */
$total=count($rows);
$cntOK=$cntWARN=$cntFAIL=0; $tAcc=0; $tCount=0; $wAcc=0; $wCount=0;
foreach($rows as $r){
  if ($r['status_class']==='badge-ok') $cntOK++;
  elseif ($r['status_class']==='badge-warn') $cntWARN++;
  elseif ($r['status_class']==='badge-fail') $cntFAIL++;
  if (is_numeric($r['tempC_val'])) { $tAcc+=$r['tempC_val']; $tCount++; }
  if (is_numeric($r['remain_pct'])) { $wAcc+=(int)$r['remain_pct']; $wCount++; }
}
$avgT=$tCount?round($tAcc/$tCount,1):'-';
$avgW=$wCount?round($wAcc/$wCount,1):'-';

?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8"><title>Scrutiny Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
  --bg:#0b0e14; --fg:#e6e8ef; --muted:#98a2b3; --card:#111624; --line:#20263a;
  --ok:#16a34a; --warn:#f59e0b; --fail:#ef4444; --chip:#1a2034; --accent:#60a5fa;
  --heatLow:#4ade80; --heatMid:#facc15; --heatHigh:#f97316; --heatCrit:#ef4444;
  --lifeGood:#60a5fa; --lifeMid:#22c55e; --lifeLow:#f97316; --lifeCrit:#ef4444;
}
*{box-sizing:border-box} html,body{height:100%}
body{background:var(--bg); color:var(--fg); font-family:system-ui,Segoe UI,Roboto,Apple SD Gothic Neo,Malgun Gothic,Apple Color Emoji,Noto Color Emoji,sans-serif; margin:0; padding:18px; line-height:1.35;}
h1{font-size:20px; margin:0 0 12px}
.card{background:var(--card); border:1px solid var(--line); border-radius:14px; padding:12px}
.cards{display:grid; grid-template-columns:repeat(6,minmax(120px,1fr)); gap:12px; margin-bottom:12px}
.kpi{padding:12px; border:1px solid var(--line); border-radius:12px; background:linear-gradient(180deg,#0e1424,transparent)}
.kpi h3{margin:0 0 6px; font-size:12px; color:var(--muted); font-weight:600}
.kpi .v{font-size:18px; font-weight:700}
.kpi.ok .v{color:#a7f3d0} .kpi.warn .v{color:#ffe9c2} .kpi.fail .v{color:#fecaca}

table{width:100%; border-collapse:separate; border-spacing:0; font-size:14px}
thead th{position:sticky; top:0; background:rgba(17,22,36,.9); backdrop-filter:blur(6px); z-index:2}
th,td{padding:10px; border-bottom:1px solid var(--line); text-align:left; white-space:nowrap; vertical-align:middle}
th{color:var(--muted); font-weight:600; user-select:none}
.badge{display:inline-block; padding:3px 8px; border-radius:999px; font-size:12px; border:1px solid var(--line); background:var(--chip)}
.badge-ok{color:#d1fae5; background:rgba(22,163,74,.15); border-color:rgba(22,163,74,.25)}
.badge-warn{color:#fff7ed; background:rgba(245,158,11,.17); border-color:rgba(245,158,11,.3)}
.badge-fail{color:#fee2e2; background:rgba(239,68,68,.18); border-color:rgba(239,68,68,.32)}
.row{transition:background .15s ease} .row:hover{background:rgba(96,165,250,.07)}
.right{text-align:right}

/* 바 정렬 고정 */
.cell-meter{display:flex; flex-direction:column; align-items:flex-end; gap:6px}
.meter{width:220px; height:8px; background:#0b1120; border:1px solid var(--line); border-radius:999px; overflow:hidden; position:relative}
.bar{position:absolute; left:0; top:0; bottom:0; width:0}
.tempbar{background:linear-gradient(90deg,var(--heatLow),var(--heatMid),var(--heatHigh),var(--heatCrit))}
.wearbar{background:linear-gradient(90deg,var(--lifeCrit),var(--lifeLow),var(--lifeMid),var(--lifeGood))}
.meter-label{font-size:12px; color:var(--muted); min-height:16px; display:flex; align-items:center; justify-content:flex-end}

.footer{color:var(--muted); font-size:12px; margin-top:10px; display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap}

@media (max-width:1200px){ .meter{width:180px} }
@media (max-width:980px){ .cards{grid-template-columns:repeat(3,1fr)} .meter{width:150px} }
@media (max-width:720px){ .cards{grid-template-columns:repeat(2,1fr)} .meter{width:120px}
  th:nth-child(4),td:nth-child(4),th:nth-child(6),td:nth-child(6){display:none} }
</style>
<script>
function reloadNow(){ location.reload(); }
setTimeout(reloadNow, 30000);
</script>
</head>
<body>
  <h1>Scrutiny 상태 대시보드</h1>

  <div class="cards">
    <div class="kpi"><h3>총 디스크</h3><div class="v"><?=htmlspecialchars((string)$total)?></div></div>
    <div class="kpi ok"><h3>OK</h3><div class="v"><?=htmlspecialchars((string)$cntOK)?></div></div>
    <div class="kpi warn"><h3>WARN</h3><div class="v"><?=htmlspecialchars((string)$cntWARN)?></div></div>
    <div class="kpi fail"><h3>FAIL</h3><div class="v"><?=htmlspecialchars((string)$cntFAIL)?></div></div>
    <div class="kpi"><h3>평균 온도</h3><div class="v"><?=is_numeric($avgT)?$avgT.'°C':'-'?></div></div>
    <div class="kpi"><h3>평균 남은 수명</h3><div class="v"><?=is_numeric($avgW)?$avgW.'%':'-'?></div></div>
  </div>

  <div class="card">
    <table>
      <thead>
        <tr>
          <th>상태</th><th>모델명</th><th>시리얼</th><th class="right">켜짐횟수</th>
          <th>켜짐시간</th><th>용량</th><th class="right">온도(°C)</th>
          <th class="right">수명(남은%)</th><th class="right">TBW</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="9" class="meter-label">표시할 디스크가 없습니다.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr class="row" ondblclick="window.open('<?=htmlspecialchars($r['detail_url'])?>','_blank')">
            <td><span class="badge <?=$r['status_class']?>"><?=$r['status_text']?></span></td>
            <td title="<?=htmlspecialchars($r['wwn'])?>"><?=htmlspecialchars($r['model'])?></td>
            <td><?=htmlspecialchars($r['serial'])?></td>
            <td class="right"><?=$r['poc']?></td>
            <td><?=$r['poh']?></td>
            <td><?=htmlspecialchars($r['capacity'])?></td>

            <td class="right">
              <div class="cell-meter">
                <div class="meter" title="<?=htmlspecialchars($r['temp_label'])?> (기준 <?=$TEMP_MIN?>–<?=$TEMP_MAX?>°C)">
                  <div class="bar tempbar" style="width:<?=is_numeric($r['temp_bar'])?$r['temp_bar']:0?>%"></div>
                </div>
                <div class="meter-label"><?=htmlspecialchars($r['temp_label'])?></div>
              </div>
            </td>

            <td class="right">
              <div class="cell-meter">
                <div class="meter" title="<?=htmlspecialchars($r['wear_text'])?>">
                  <div class="bar wearbar" style="width:<?=is_numeric($r['life_bar'])?$r['life_bar']:0?>%"></div>
                </div>
                <div class="meter-label"><?=htmlspecialchars($r['wear_text'])?></div>
              </div>
            </td>

            <td class="right"><?=$r['tbw']?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>

    <div class="footer">
      <div>갱신: <?=htmlspecialchars(date('Y-m-d H:i:s'))?></div>
      <div>원본: <?=htmlspecialchars($BASE)?> · cfg: <?=htmlspecialchars(basename($CFG_PATH))?></div>
    </div>
  </div>
</body>
</html>
