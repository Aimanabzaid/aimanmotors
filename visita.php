<?php
/**
 * Aiman Motors — estadísticas anónimas de la web (sin cookies).
 *
 * Recibe avisos de la web (visitas y acciones) y guarda solo totales por día:
 * páginas, de dónde llegan (Google, Instagram…), tipo de dispositivo y acciones
 * (clics en WhatsApp, formularios). No se guarda la IP ni ningún dato personal.
 * Para contar visitantes únicos se usa una huella diaria cifrada que se borra al día siguiente.
 */

date_default_timezone_set('Europe/Madrid');
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }

$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
if ($ua === '' || preg_match('/bot|crawl|spider|slurp|headless|lighthouse|preview|facebookexternalhit|whatsapp|curl|wget|python/i', $ua)) {
    http_response_code(204); exit;
}
$origen = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origen !== '' && !in_array(parse_url($origen, PHP_URL_HOST), ['aimanmotors.com', 'www.aimanmotors.com', '127.0.0.1', 'localhost'], true)) {
    http_response_code(204); exit;
}

$raw = file_get_contents('php://input', false, null, 0, 2048);
$d = json_decode((string)$raw, true);
if (!is_array($d)) { http_response_code(204); exit; }

/* --- Carpeta privada (la misma que el resto de la web) --- */
function carpeta(): ?string {
    foreach ([getenv('AIMAN_DATOS') ?: '', dirname(__DIR__) . '/aiman_datos', __DIR__ . '/datos-privados'] as $base) {
        if ($base === '') continue;
        $dir = "$base/estadisticas";
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        if (is_dir($dir) && is_writable($dir)) return $dir;
    }
    return null;
}
$dir = carpeta();
if ($dir === null) { http_response_code(204); exit; }

$hoy = date('Y-m-d');
$limpio = fn($s, $max = 40) => mb_substr(preg_replace('/[^\p{L}\p{N}_\-\/\.]/u', '', (string)$s), 0, $max);

/* --- Qué ha pasado --- */
$tipo = ($d['t'] ?? '') === 'e' ? 'e' : 'v';
$evento = $tipo === 'e' ? $limpio($d['e'] ?? '', 40) : '';
if ($tipo === 'e' && $evento === '') { http_response_code(204); exit; }

$pagina = $limpio(parse_url((string)($d['p'] ?? '/'), PHP_URL_PATH) ?: '/', 60);
if ($pagina === '' || $pagina === '/index.html') $pagina = '/';

// De dónde llega: parámetro utm_source o la web de procedencia
function fuente(string $utm, string $ref): string {
    $u = strtolower($utm);
    $h = strtolower((string)parse_url($ref, PHP_URL_HOST));
    $h = preg_replace('/^(www\.|m\.|l\.|lm\.)/', '', $h);
    $mapa = ['google' => 'Google', 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'fb' => 'Facebook',
             'whatsapp' => 'WhatsApp', 'wa' => 'WhatsApp', 'tiktok' => 'TikTok', 'bing' => 'Bing',
             'youtube' => 'YouTube', 'linkedin' => 'LinkedIn', 'twitter' => 'X', 't.co' => 'X', 'x.com' => 'X'];
    foreach ($mapa as $k => $v) if ($u !== '' && str_contains($u, $k)) return $v;
    if ($h === '' || str_contains($h, 'aimanmotors.com')) return 'Directo';
    foreach ($mapa as $k => $v) if (str_contains($h, $k)) return $v;
    return 'Otras webs';
}
$fuente = fuente((string)($d['s'] ?? ''), (string)($d['r'] ?? ''));
$ancho = (int)($d['w'] ?? 0);
$disp = $ancho > 0 && $ancho < 768 ? 'Móvil' : ($ancho >= 768 && $ancho < 1100 ? 'Tablet' : 'Ordenador');

/* --- Visitante único del día (huella cifrada que solo vale hoy) --- */
$salF = "$dir/.sal-$hoy";
if (!is_file($salF)) {
    foreach (glob("$dir/.sal-*") ?: [] as $f) @unlink($f);
    foreach (glob("$dir/unicos-*.txt") ?: [] as $f) if (!str_ends_with($f, "unicos-$hoy.txt")) @unlink($f);
    @file_put_contents($salF, bin2hex(random_bytes(16)));
}
$huella = substr(hash('sha256', (string)@file_get_contents($salF) . ($_SERVER['REMOTE_ADDR'] ?? '') . $ua), 0, 20);

/* --- Guardar totales del mes --- */
$fichero = "$dir/" . date('Y-m') . '.json';
$fh = fopen($fichero, 'c+');
if (!$fh) { http_response_code(204); exit; }
flock($fh, LOCK_EX);
$datos = json_decode(stream_get_contents($fh) ?: '{}', true) ?: [];
$dia = $datos[$hoy] ?? ['visitas' => 0, 'unicos' => 0, 'paginas' => [], 'fuentes' => [], 'dispositivos' => [], 'acciones' => []];

if ($tipo === 'v') {
    $dia['visitas']++;
    $dia['paginas'][$pagina] = ($dia['paginas'][$pagina] ?? 0) + 1;
    $dia['fuentes'][$fuente] = ($dia['fuentes'][$fuente] ?? 0) + 1;
    $dia['dispositivos'][$disp] = ($dia['dispositivos'][$disp] ?? 0) + 1;
    $uF = "$dir/unicos-$hoy.txt";
    $vistos = is_file($uF) ? file($uF, FILE_IGNORE_NEW_LINES) : [];
    if (!in_array($huella, $vistos, true)) {
        @file_put_contents($uF, $huella . "\n", FILE_APPEND);
        $dia['unicos']++;
    }
} else {
    // Cada acción cuenta una vez por visitante y día (evita inflar con clics repetidos)
    $aF = "$dir/unicos-$hoy.txt";
    $marca = "$huella:$evento";
    $vistos = is_file($aF) ? file($aF, FILE_IGNORE_NEW_LINES) : [];
    if (!in_array($marca, $vistos, true)) {
        @file_put_contents($aF, $marca . "\n", FILE_APPEND);
        $dia['acciones'][$evento] = ($dia['acciones'][$evento] ?? 0) + 1;
    }
}

$datos[$hoy] = $dia;
ksort($datos);
ftruncate($fh, 0); rewind($fh);
fwrite($fh, json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
flock($fh, LOCK_UN); fclose($fh);
http_response_code(204);
