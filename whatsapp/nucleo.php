<?php
/**
 * Aiman Motors — utilidades compartidas del asistente de WhatsApp.
 * Este archivo no se abre desde el navegador (lo bloquea .htaccess).
 *
 * Las claves NO están aquí: se leen de «aiman_config.php», un archivo privado
 * que está en el servidor, en la carpeta superior a public_html (no accesible desde internet).
 */

date_default_timezone_set('Europe/Madrid');

const AM_DESTINO   = 'aimanmotorsg@gmail.com';
const AM_REMITENTE = 'web@aimanmotors.com';

/** Configuración privada, con valores por defecto. */
function am_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $porDefecto = [
        'd360_api_key'      => '',
        'anthropic_api_key' => '',
        'modelo'            => 'claude-haiku-5-5',
        'clave_admin'       => '',
        'horas_pausa'       => 12,      // horas que el asistente calla tras pasar el chat o contestar Aimán desde el móvil
        'activo'            => true,    // false = el asistente no responde a nadie
        'avisar_email'      => true,
        'url_360'           => 'https://waba-v2.360dialog.io',
        'url_anthropic'     => 'https://api.anthropic.com',
    ];
    $rutas = [getenv('AIMAN_CONFIG') ?: '', dirname(__DIR__, 2) . '/aiman_config.php'];
    $cfg = $porDefecto;
    foreach ($rutas as $r) {
        if ($r !== '' && is_file($r)) {
            $leido = include $r;
            if (is_array($leido)) $cfg = array_merge($porDefecto, $leido);
            break;
        }
    }
    return $cfg;
}

/** Carpeta privada de datos (la misma que usa enviar.php). */
function am_datos(string $sub = ''): ?string {
    $candidatas = [getenv('AIMAN_DATOS') ?: '', dirname(__DIR__, 2) . '/aiman_datos', dirname(__DIR__) . '/datos-privados'];
    foreach ($candidatas as $dir) {
        if ($dir === '') continue;
        $d = $sub === '' ? $dir : "$dir/$sub";
        if (!is_dir($d)) @mkdir($d, 0700, true);
        if (is_dir($d) && is_writable($d)) {
            if (str_starts_with($dir, dirname(__DIR__)) && !is_file("$dir/.htaccess")) {
                @file_put_contents("$dir/.htaccess", "Require all denied\nDeny from all\n");
            }
            return $d;
        }
    }
    return null;
}

function am_registrar(string $fichero, array $fila): bool {
    $dir = am_datos();
    if ($dir === null) return false;
    return @file_put_contents("$dir/$fichero", json_encode($fila, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX) !== false;
}

function am_log(string $txt): void {
    $dir = am_datos('whatsapp');
    if ($dir) @file_put_contents("$dir/errores.log", date('c') . ' ' . $txt . "\n", FILE_APPEND | LOCK_EX);
}

function am_id(string $prefijo): string {
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $r = '';
    for ($i = 0; $i < 4; $i++) $r .= $abc[random_int(0, strlen($abc) - 1)];
    return $prefijo . '-' . date('ymd') . '-' . $r;
}

function am_email(string $asunto, string $cuerpo): bool {
    if (!am_config()['avisar_email']) return true;
    $cab  = 'From: =?UTF-8?B?' . base64_encode('Asistente WhatsApp Aiman Motors') . '?= <' . AM_REMITENTE . ">\r\n";
    $cab .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
    return mail(AM_DESTINO, '=?UTF-8?B?' . base64_encode($asunto) . '?=',
        chunk_split(base64_encode(str_replace("\n", "\r\n", $cuerpo))), $cab, '-f' . AM_REMITENTE);
}

/** Petición JSON por HTTPS. Devuelve [código, cuerpo decodificado o null]. */
function am_http(string $metodo, string $url, array $cabeceras, ?array $cuerpo = null, int $timeout = 30): array {
    $ch = curl_init($url);
    $h = ['Content-Type: application/json'];
    foreach ($cabeceras as $k => $v) $h[] = "$k: $v";
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($cuerpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cuerpo, JSON_UNESCAPED_UNICODE));
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($res === false) am_log("HTTP $url: " . curl_error($ch));
    curl_close($ch);
    return [$code, $res === false ? null : json_decode($res, true)];
}

/** Envía un texto por WhatsApp (360dialog). */
function am_wa_enviar(string $para, string $texto): bool {
    $cfg = am_config();
    $ok = true;
    foreach (str_split_utf8($texto, 3900) as $trozo) {
        [$code, $res] = am_http('POST', rtrim($cfg['url_360'], '/') . '/messages', ['D360-API-KEY' => $cfg['d360_api_key']], [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $para,
            'type'              => 'text',
            'text'              => ['body' => $trozo, 'preview_url' => false],
        ], 20);
        if ($code < 200 || $code >= 300) { am_log("Envío a $para falló ($code): " . json_encode($res)); $ok = false; }
    }
    return $ok;
}

function str_split_utf8(string $s, int $max): array {
    if (mb_strlen($s) <= $max) return [$s];
    $out = [];
    while (mb_strlen($s) > $max) {
        $corte = mb_strrpos(mb_substr($s, 0, $max), "\n") ?: mb_strrpos(mb_substr($s, 0, $max), ' ') ?: $max;
        $out[] = trim(mb_substr($s, 0, $corte));
        $s = trim(mb_substr($s, $corte));
    }
    if ($s !== '') $out[] = $s;
    return $out;
}

function am_wa_link(string $tel): string { return 'https://wa.me/' . preg_replace('/\D/', '', $tel); }
