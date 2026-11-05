<?php
/**
 * Aiman Motors — envío de formularios por email.
 * Recibe las solicitudes de la web (propietarios y búsqueda de coche)
 * y las manda a la dirección de destino, con las fotos adjuntas si las hay.
 */

const DESTINO      = 'aimanmotorsg@gmail.com';
const REMITENTE    = 'web@aimanmotors.com';      // dirección del propio dominio (mejor entrega)
const NOMBRE_WEB   = 'Web Aiman Motors';
const MAX_FOTOS    = 6;
const MAX_FOTO_MB  = 8;
const MAX_TOTAL_MB = 20;
const MAX_ENVIOS   = 5;      // por IP...
const VENTANA_SEG  = 600;    // ...cada 10 minutos

date_default_timezone_set('Europe/Madrid');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function responder(bool $ok, string $msg = '', int $code = 200): void {
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'mensaje' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responder(false, 'Método no permitido.', 405);
}

/* --- Antispam: campo trampa y tiempo mínimo de relleno --- */
if (!empty($_POST['website'])) {
    responder(true); // bot: fingimos éxito y no enviamos nada
}
$inicio = (int)($_POST['t'] ?? 0);
if ($inicio > 0 && (microtime(true) * 1000 - $inicio) < 2000) {
    responder(true);
}

/* --- Límite de envíos por IP --- */
$ip = $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
$rl = sys_get_temp_dir() . '/aiman_rl_' . hash('sha256', $ip . __FILE__);
$marcas = [];
if (is_file($rl)) {
    $marcas = array_filter(
        array_map('intval', explode(',', (string)@file_get_contents($rl))),
        fn($m) => $m > time() - VENTANA_SEG
    );
}
if (count($marcas) >= MAX_ENVIOS) {
    responder(false, 'Has enviado varias solicitudes seguidas. Espera unos minutos o escríbenos por WhatsApp.', 429);
}

/* --- Lectura y limpieza de campos --- */
function campo(string $k, int $max = 200, bool $multilinea = false): string {
    $v = trim((string)($_POST[$k] ?? ''));
    $v = $multilinea ? str_replace("\r", '', $v) : preg_replace('/[\r\n\t]+/', ' ', $v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr($v, 0, $max);
}

$tipo = campo('tipo', 20);
if (!in_array($tipo, ['propietario', 'busqueda'], true)) {
    responder(false, 'Solicitud no válida.', 400);
}
if (campo('privacidad', 5) !== '1') {
    responder(false, 'Debes aceptar la política de privacidad.', 400);
}

$nombre   = campo('nombre', 100);
$telefono = campo('telefono', 30);
if ($nombre === '' || !preg_match('/\d{6,}/', preg_replace('/\D/', '', $telefono) ?: '')) {
    responder(false, 'Revisa tu nombre y tu teléfono.', 400);
}

$v = fn($s) => $s === '' ? 'Sin especificar' : $s;

if ($tipo === 'propietario') {
    $d = [
        'Marca'          => campo('marca', 60),
        'Modelo'         => campo('modelo', 60),
        'Año'            => campo('ano', 10),
        'Kilometraje'    => campo('km', 20),
        'Ciudad'         => campo('ciudad', 60),
        'Disponibilidad' => campo('disponibilidad', 80),
    ];
    foreach (['Marca', 'Modelo', 'Ciudad', 'Disponibilidad'] as $r) {
        if ($d[$r] === '') responder(false, 'Completa todos los campos obligatorios.', 400);
    }
    $comentarios = campo('comentarios', 2000, true);
    $asunto = "Nuevo propietario: {$d['Marca']} {$d['Modelo']} ({$d['Ciudad']})";
    $cuerpo = "NUEVA SOLICITUD DE PROPIETARIO — ALQUILER DE VEHÍCULO\n\n"
            . "Nombre: $nombre\nTeléfono: $telefono\n\n";
    foreach ($d as $k => $val) $cuerpo .= "$k: " . $v($val) . "\n";
    $cuerpo .= "\nComentarios:\n" . $v($comentarios) . "\n";
} else {
    $d = [
        'Marca'        => campo('marca', 60),
        'Modelo'       => campo('modelo', 60),
        'Presupuesto'  => campo('presupuesto', 30),
        'Año mínimo'   => campo('ano', 10),
        'Km máximos'   => campo('km', 20),
        'Búsqueda'     => campo('origen', 30),
    ];
    if ($d['Marca'] === '' || $d['Modelo'] === '') {
        responder(false, 'Completa todos los campos obligatorios.', 400);
    }
    $notas = campo('notas', 2000, true);
    $asunto = "Búsqueda de coche: {$d['Marca']} {$d['Modelo']}";
    $cuerpo = "NUEVA SOLICITUD — COCHE A LA CARTA\n\n"
            . "Nombre: $nombre\nTeléfono: $telefono\n\n";
    foreach ($d as $k => $val) $cuerpo .= "$k: " . $v($val) . "\n";
    $cuerpo .= "\nPreferencias:\n" . $v($notas) . "\n";
}

$waTel = preg_replace('/\D/', '', $telefono);
if (strlen($waTel) === 9) $waTel = '34' . $waTel;
$cuerpo .= "\n—\nResponder por WhatsApp: https://wa.me/$waTel\n"
         . "Enviado desde aimanmotors.com el " . date('d/m/Y H:i') . "\n";

/* --- Fotos (solo propietarios, opcionales) --- */
$adjuntos = [];
if ($tipo === 'propietario' && !empty($_FILES['fotos']['name']) && is_array($_FILES['fotos']['name'])) {
    $permitidos = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'image/heic' => 'heic', 'image/heif' => 'heif',
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $total = 0;
    $n = count($_FILES['fotos']['name']);
    for ($i = 0; $i < $n && count($adjuntos) < MAX_FOTOS; $i++) {
        $err = $_FILES['fotos']['error'][$i];
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err !== UPLOAD_ERR_OK) responder(false, 'No se pudo subir una de las fotos. Prueba con menos o más pequeñas.', 400);
        $tmp  = $_FILES['fotos']['tmp_name'][$i];
        $size = (int)$_FILES['fotos']['size'][$i];
        if (!is_uploaded_file($tmp)) continue;
        if ($size > MAX_FOTO_MB * 1048576) responder(false, 'Alguna foto es demasiado grande (máximo ' . MAX_FOTO_MB . ' MB).', 400);
        $mime = $finfo->file($tmp) ?: '';
        if (!isset($permitidos[$mime])) responder(false, 'Solo se admiten fotos (JPG, PNG, WEBP o HEIC).', 400);
        $total += $size;
        if ($total > MAX_TOTAL_MB * 1048576) responder(false, 'Las fotos ocupan demasiado. Envía menos fotos.', 400);
        $adjuntos[] = [
            'nombre' => sprintf('foto-%d.%s', count($adjuntos) + 1, $permitidos[$mime]),
            'mime'   => $mime,
            'datos'  => file_get_contents($tmp),
        ];
    }
    if ($adjuntos) $cuerpo .= "\nFotos adjuntas: " . count($adjuntos) . "\n";
}

/* --- Montaje del correo --- */
$limite = 'am_' . bin2hex(random_bytes(12));
$cab  = 'From: ' . mb_encode_mimeheader(NOMBRE_WEB, 'UTF-8') . ' <' . REMITENTE . ">\r\n";
$cab .= "MIME-Version: 1.0\r\n";
$cab .= "X-Mailer: aimanmotors.com\r\n";
$cab .= "Content-Type: multipart/mixed; boundary=\"$limite\"";

$msg  = "--$limite\r\n";
$msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
$msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
$msg .= chunk_split(base64_encode(str_replace("\n", "\r\n", $cuerpo)));
foreach ($adjuntos as $a) {
    $msg .= "--$limite\r\n";
    $msg .= "Content-Type: {$a['mime']}; name=\"{$a['nombre']}\"\r\n";
    $msg .= "Content-Transfer-Encoding: base64\r\n";
    $msg .= "Content-Disposition: attachment; filename=\"{$a['nombre']}\"\r\n\r\n";
    $msg .= chunk_split(base64_encode($a['datos']));
}
$msg .= "--$limite--\r\n";

$ok = mail(DESTINO, mb_encode_mimeheader($asunto, 'UTF-8'), $msg, $cab, '-f' . REMITENTE);

if (!$ok) {
    responder(false, 'No hemos podido enviar tu solicitud ahora mismo. Escríbenos por WhatsApp y te atendemos enseguida.', 500);
}

$marcas[] = time();
@file_put_contents($rl, implode(',', $marcas), LOCK_EX);
responder(true, '¡Recibido! Te contactamos lo antes posible.');
