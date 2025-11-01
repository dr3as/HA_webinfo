<?php
session_start();
include 'config.php';

// --- Session timeout ---
if (!isset($_SESSION['last_activity'])) {
    $_SESSION['last_activity'] = time();
} elseif (time() - $_SESSION['last_activity'] > $SESSION_TIMEOUT) {
    session_unset();
    session_destroy();
}
$_SESSION['last_activity'] = time();

// --- Logg ut ---
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit;
}

// --- Håndter login ---
$err = '';
$users = [
    "dr3as" => "pass123",
    "nanette" => "pass456",
    "eline" => "pass789"
];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';
    if (isset($users[$user]) && $users[$user] === $pass) {
        $_SESSION['user'] = $user;
        header("Location: index.php");
        exit;
    } else {
        $err = "Feil brukernavn eller passord";
    }
}

// --- Sjekk login ---
if (!isset($_SESSION['user'])) {
    ?>
    <!DOCTYPE html>
    <html lang="no">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Logg inn</title>
        <link rel="stylesheet" href="style.css">
    </head>
    <body>
        <div class="box">
            <h2>Logg inn</h2>
            <?php if($err) echo "<div class='err'>$err</div>"; ?>
            <form method="post">
                <label>Brukernavn<input type="text" name="username" required autofocus></label>
                <label>Passord<input type="password" name="password" required></label>
                <button type="submit">Logg inn</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// --- Funksjoner for HA API ---
function ha_get($sensor_id){
    global $HA_URL, $HA_TOKEN;
    $url = $HA_URL.$sensor_id;
    $opts = ['http'=>[
        'method'=>"GET",
        'header'=>"Authorization: Bearer $HA_TOKEN\r\nContent-Type: application/json\r\n",
        'timeout'=>5
    ]];
    $context = stream_context_create($opts);
    $res = @file_get_contents($url,false,$context);
    if($res===false) return ['state'=>'N/A','attributes'=>[]];
    $data = json_decode($res,true);
    return ['state'=>$data['state'] ?? 'N/A','attributes'=>$data['attributes'] ?? []];
}

function temp_color($temp_str){
    $temp=floatval($temp_str);
    if($temp<=0) return "#2196f3";
    elseif($temp<=15) return "#9c27b0";
    else return "#f44336";
}

function format_time($iso){
    if(!$iso) return '';
    $dt = date_create($iso);
    if(!$dt) return $iso;
    return date_format($dt,"H:i");
}

// --- Hilsen basert på tid ---
$hour = intval(date('H'));
if ($hour >= 6 && $hour <= 11) {
    $greeting = "God morgen";
} elseif ($hour >= 12 && $hour <= 13) {
    $greeting = "God formiddag";
} elseif ($hour >= 14 && $hour <= 18) {
    $greeting = "God ettermiddag";
} elseif ($hour >= 19 && $hour <= 23) {
    $greeting = "God kveld";
} else {
    $greeting = "God natt";
}
$user_display = ucfirst($_SESSION['user']);
$full_greeting = "$greeting $user_display";

// --- Hent sensorer ---
$sensors = [
    "kontor_temp"=>"sensor.kontor_temperature",
    "ute_temp"=>"sensor.kontor_ute_temperature",
    "condition_today"=>"sensor.condition_today",
    "precipitation_today"=>"sensor.precipitation_today",
    "precipitation_probability_today"=>"sensor.precipitation_probability_today",
    "temperature_high_today"=>"sensor.temperature_high_today",
    "temperature_low_today"=>"sensor.temperature_low_today"
];

$data=[];
foreach($sensors as $key=>$sensor){
    $res = ha_get($sensor);
    $data[$key]=$res['state'];
}
$data['temperature_high_color']=temp_color($data['temperature_high_today']);
$data['temperature_low_color']=temp_color($data['temperature_low_today']);

// --- Kalender ---
$calendar_events=[];
for($i=0;$i<5;$i++){
    $sensor_id="sensor.ical_familie_event_$i";
    $res=ha_get($sensor_id);
    $attrs=$res['attributes'];
    $eta = $attrs['eta'] ?? 0;
    if(strval($eta)==="1"){
        $calendar_events[]= [
            'title'=>$attrs['friendly_name'] ?? $res['state'],
            'start'=>format_time($attrs['start'] ?? ''),
            'end'=>format_time($attrs['end'] ?? '')
        ];
    }
}

// --- Toginfo ---
$train_sensors = ["sensor.vy_avvik_vyg_line_l2"];
$train_info = [];
foreach($train_sensors as $sensor_id){
    $res = ha_get($sensor_id);
    $attrs = $res['attributes'];
    $train_info[] = [
        'state' => $res['state'],
        'description' => $attrs['description'] ?? '',
        'icon' => $attrs['icon'] ?? 'mdi:train',
        'friendly_name' => $attrs['friendly_name'] ?? $sensor_id
    ];
}

$entur_sensors = [
    "sensor.entur_oppegard_stasjon_platform_1",
    "sensor.entur_oppegard_stasjon_platform_2"
];
$entur_delays = [];
foreach($entur_sensors as $sensor_id){
    $res = ha_get($sensor_id);
    $attrs = $res['attributes'];
    $delay = intval($attrs['delay'] ?? 0);
    $next_delay = intval($attrs['next_delay'] ?? 0);
    if($delay !== 0 || $next_delay !== 0){
        $entur_delays[] = [
            'name' => $attrs['friendly_name'] ?? $sensor_id,
            'delay' => $delay,
            'next_delay' => $next_delay,
            'due_at' => $attrs['due_at'] ?? '',
            'next_due_at' => $attrs['next_due_at'] ?? ''
        ];
    }
}

// --- Komprimert Togstatus ---
$avvik_status = "Nei";
if(count($train_info) && $train_info[0]['state'] !== "Normal service"){
    $avvik_status = $train_info[0]['state'];
}
$forsinkelse_status = "Nei";
if(count($entur_delays)){
    $forsinkelse_status = "Ja";
}

?>
<!DOCTYPE html>
<html lang="no">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Min Startside</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<a class="logout" href="index.php?logout=1">Logg ut</a>
<h1><?= htmlspecialchars($full_greeting) ?>!</h1>

<!-- Temperaturer -->
<div class="category">
    <h2>Temperaturer</h2>
    <div class="item-container">
        <div class="item">
            <span class="icon">🌡️</span>
            <span>Kontor: <?=$data['kontor_temp']?> °C</span>
        </div>
        <div class="item">
            <span class="icon">🌡️</span>
            <span>Ute: <?=$data['ute_temp']?> °C</span>
        </div>
    </div>
</div>

<!-- Vær -->
<div class="category">
    <h2>Vær i dag</h2>
    <div class="item-container">
        <div class="item">
            <span class="icon">☁️</span>
            <span>Forhold: <?=$data['condition_today']?></span>
        </div>
        <div class="item">
            <span class="icon">💧</span>
            <span>Nedbør: <?=$data['precipitation_today']?> mm (<?=$data['precipitation_probability_today']?>%)</span>
        </div>
        <div class="item">
            <span class="icon" style="color:#f44336;">&#9650;</span>
            <span style="color:#f44336;"> Høy temp: <?=$data['temperature_high_today']?> °C</span>
        </div>
        <div class="item">
            <span class="icon" style="color:#2196f3;">&#9660;</span>
            <span style="color:#2196f3;"> Lav temp: <?=$data['temperature_low_today']?> °C</span>
        </div>
    </div>
</div>

<!-- Kalender -->
<div class="category">
    <h2>Kalender i dag</h2>
    <div class="item-container">
    <?php if(count($calendar_events)):
        foreach($calendar_events as $event): ?>
        <div class="item">
            <span class="icon">📅</span>
            <span><strong><?=$event['title']?></strong></span>
            <?php if($event['start'] || $event['end']): ?>
                <p class="subinfo"><?=$event['start']?> - <?=$event['end']?></p>
            <?php endif; ?>
        </div>
    <?php endforeach;
    else: ?>
        <div class="item"><span>Ingen oppføringer i dag.</span></div>
    <?php endif; ?>
    </div>
</div>

<!-- Togstatus -->
<div class="category">
    <h2>Togstatus</h2>
    <div class="item-container">
        <div class="item">
            <span class="icon">🚆</span>
            <p><strong>Avvik:</strong> <?=$avvik_status?></p>
            <p><strong>Forsinkelser:</strong> <?=$forsinkelse_status?></p>
        </div>
    </div>
</div>

</body>
</html>
