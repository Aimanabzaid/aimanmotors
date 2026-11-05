<?php
/**
 * Aiman Motors — recepción de formularios (Fase 1).
 *
 * Tipos de solicitud:
 *   propietario     Preevaluación de un vehículo (con fotos y calendario opcionales).
 *   busqueda        Búsqueda de coche a la carta.
 *   referido        Alta de un propietario que recomienda: genera un código y un enlace.
 *   disponibilidad  Actualización del calendario de una solicitud ya enviada.
 *
 * Cada solicitud se registra en ficheros privados (JSON por líneas) FUERA de la carpeta
 * pública de la web, y se notifica por email. Las fotos solo viajan por email:
 * no se guardan en el servidor. Nada se aprueba automáticamente.
 */

const DESTINO      = 'aimanmotorsg@gmail.com';
const REMITENTE    = 'web@aimanmotors.com';   // dirección del propio dominio (mejor entrega)
const NOMBRE_WEB   = 'Web Aiman Motors';
const WEB          = 'https://aimanmotors.com';
const MAX_FOTOS    = 6;
const MAX_FOTO_MB  = 8;
const MAX_TOTAL_MB = 20;
const MAX_DIAS     = 400;     // fechas como máximo por calendario
const MAX_ENVIOS   = 8;       // por IP...
const VENTANA_SEG  = 600;     // ...cada 10 minutos
const ORIGENES     = ['aimanmotors.com', 'www.aimanmotors.com', '127.0.0.1', 'localhost'];

date_default_timezone_set('Europe/Madrid');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function responder(bool $ok, string $msg = '', int $code = 200, array $extra = []): void {
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'mensaje' => $msg] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responder(false, 'Método no permitido.', 405);
}

/* --- Solo se aceptan envíos desde la propia web --- */
$origen = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origen !== '' && !in_array(parse_url($origen, PHP_URL_HOST), ORIGENES, true)) {
    responder(false, 'Origen no permitido.', 403);
}

/* --- Antispam: campo trampa y tiempo mínimo de relleno --- */
if (!empty($_POST['website'])) {
    responder(true, '', 200, ['referencia' => 'AM-OK']); // bot: fingimos éxito y no hacemos nada
}
$inicio = (int)($_POST['t'] ?? 0);
if ($inicio > 0 && (microtime(true) * 1000 - $inicio) < 2000) {
    responder(true, '', 200, ['referencia' => 'AM-OK']);
}

/* --- Límite de envíos por IP (solo se guarda un hash, nunca la IP) --- */
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
function anotarEnvio(string $rl, array $marcas): void {
    $marcas[] = time();
    @file_put_contents($rl, implode(',', $marcas), LOCK_EX);
}

/* ================= Utilidades ================= */

function campo(string $k, int $max = 200, bool $multilinea = false): string {
    $v = trim((string)($_POST[$k] ?? ''));
    $v = $multilinea ? str_replace("\r", '', $v) : preg_replace('/[\r\n\t]+/', ' ', $v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr($v, 0, $max);
}
function opcion(string $k, array $validas): string {
    $v = campo($k, 60);
    return in_array($v, $validas, true) ? $v : '';
}
function soloDigitos(string $s): string { return preg_replace('/\D/', '', $s) ?? ''; }
function telefonoValido(string $t): bool { return strlen(soloDigitos($t)) >= 9; }
function enlaceWa(string $t): string {
    $d = soloDigitos($t);
    if (strlen($d) === 9) $d = '34' . $d;
    return "https://wa.me/$d";
}
function sv(string $s): string { return $s === '' ? 'Sin especificar' : $s; }

/** Lista de fechas AAAA-MM-DD válidas, sin duplicados, ordenadas. */
function fechas(string $k): array {
    $out = [];
    foreach (explode(',', campo($k, 12 * MAX_DIAS)) as $f) {
        $f = trim($f);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) continue;
        [$y, $m, $d] = array_map('intval', explode('-', $f));
        if (checkdate($m, $d, $y)) $out[$f] = true;
        if (count($out) >= MAX_DIAS) break;
    }
    $out = array_keys($out);
    sort($out);
    return $out;
}
/** «noviembre 2026: 7, 8, 14» por cada mes. */
function fechasTexto(array $f): string {
    if (!$f) return 'Ninguno';
    $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $g = [];
    foreach ($f as $x) {
        [$y, $m, $d] = array_map('intval', explode('-', $x));
        $g["{$meses[$m]} $y"][] = $d;
    }
    $l = [];
    foreach ($g as $mes => $dias) $l[] = "  $mes: " . implode(', ', $dias);
    return count($f) . (count($f) === 1 ? " día\n" : " días\n") . implode("\n", $l);
}

/** Identificador corto y difícil de adivinar, p. ej. AM-261106-K7QX. */
function nuevoId(string $prefijo, bool $conFecha = true, int $len = 4): string {
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $r = '';
    for ($i = 0; $i < $len; $i++) $r .= $abc[random_int(0, strlen($abc) - 1)];
    return $prefijo . '-' . ($conFecha ? date('ymd') . '-' : '') . $r;
}

/* --- Registro privado: carpeta hermana de public_html (no accesible desde la web) --- */
function carpetaDatos(): ?string {
    $candidatas = [dirname(__DIR__) . '/aiman_datos', __DIR__ . '/datos-privados'];
    foreach ($candidatas as $dir) {
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        if (!is_dir($dir) || !is_writable($dir)) continue;
        if (str_starts_with($dir, __DIR__)) { // alternativa dentro de la web: se bloquea el acceso
            if (!is_file("$dir/.htaccess")) @file_put_contents("$dir/.htaccess", "Require all denied\nDeny from all\n");
            if (!is_file("$dir/index.html")) @file_put_contents("$dir/index.html", '');
        }
        return $dir;
    }
    return null;
}
function registrar(string $fichero, array $fila): bool {
    $dir = carpetaDatos();
    if ($dir === null) return false;
    $linea = json_encode($fila, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    return @file_put_contents("$dir/$fichero", $linea, FILE_APPEND | LOCK_EX) !== false;
}
/** Devuelve la última fila del fichero que cumple la condición. */
function buscar(string $fichero, callable $cond): ?array {
    $dir = carpetaDatos();
    if ($dir === null || !is_file("$dir/$fichero")) return null;
    $hallada = null;
    $fh = @fopen("$dir/$fichero", 'r');
    if (!$fh) return null;
    while (($l = fgets($fh)) !== false) {
        $f = json_decode($l, true);
        if (is_array($f) && $cond($f)) $hallada = $f;
    }
    fclose($fh);
    return $hallada;
}

/* --- Email --- */
function enviarEmail(string $asunto, string $cuerpo, array $adjuntos = []): bool {
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
    return mail(DESTINO, mb_encode_mimeheader($asunto, 'UTF-8'), $msg, $cab, '-f' . REMITENTE);
}
function pie(string $telefono): string {
    return "\n—\nResponder por WhatsApp: " . enlaceWa($telefono) . "\n"
         . "Enviado desde aimanmotors.com el " . date('d/m/Y H:i') . "\n";
}

/* --- Fotos (opcionales, solo por email) --- */
function leerFotos(): array {
    if (empty($_FILES['fotos']['name']) || !is_array($_FILES['fotos']['name'])) return [];
    $permitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic', 'image/heif' => 'heif'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $total = 0;
    $adj = [];
    $n = count($_FILES['fotos']['name']);
    for ($i = 0; $i < $n && count($adj) < MAX_FOTOS; $i++) {
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
        $adj[] = ['nombre' => sprintf('foto-%d.%s', count($adj) + 1, $permitidos[$mime]), 'mime' => $mime, 'datos' => file_get_contents($tmp)];
    }
    return $adj;
}

/* ================= Tipos de solicitud ================= */

$tipo = campo('tipo', 20);
if (!in_array($tipo, ['propietario', 'busqueda', 'referido', 'disponibilidad'], true)) {
    responder(false, 'Solicitud no válida.', 400);
}
if (campo('privacidad', 5) !== '1') {
    responder(false, 'Debes aceptar la política de privacidad.', 400);
}
$telefono = campo('telefono', 30);
if (!telefonoValido($telefono)) {
    responder(false, 'Revisa tu número de teléfono.', 400);
}
$ahora = date('c');

/* ---------- 1. Preevaluación de vehículo ---------- */
if ($tipo === 'propietario') {
    $nombre = campo('nombre', 100);
    $d = [
        'marca'          => campo('marca', 60),
        'modelo'         => campo('modelo', 60),
        'ano'            => campo('ano', 10),
        'km'             => campo('km', 20),
        'ciudad'         => campo('ciudad', 60),
        'combustible'    => opcion('combustible', ['Gasolina', 'Diésel', 'Híbrido', 'Híbrido enchufable', 'Eléctrico', 'GLP / GNC', 'Otro']),
        'estado'         => opcion('estado', ['Muy bueno', 'Bueno', 'Regular', 'Necesita reparaciones']),
        'itv'            => opcion('itv', ['Sí', 'No', 'No lo sé']),
        'seguro'         => opcion('seguro', ['Sí', 'No']),
        'danos'          => opcion('danos', ['No', 'Sí']),
        'danos_detalle'  => campo('danos_detalle', 1000, true),
        'disponibilidad' => campo('disponibilidad', 80),
        'comentarios'    => campo('comentarios', 2000, true),
    ];
    foreach (['marca', 'modelo', 'ciudad', 'combustible', 'estado', 'itv', 'seguro', 'danos', 'disponibilidad'] as $r) {
        if ($d[$r] === '' || $nombre === '') responder(false, 'Completa todos los campos obligatorios.', 400);
    }
    if ($d['danos'] === 'Sí' && $d['danos_detalle'] === '') {
        responder(false, 'Cuéntanos brevemente qué averías o daños tiene.', 400);
    }
    $dias   = fechas('dias');
    $diasNo = fechas('dias_no');
    $ref    = strtoupper(campo('ref', 20));
    $refOk  = preg_match('/^R-[A-Z2-9]{6}$/', $ref) ? $ref : '';
    $fotos  = leerFotos();
    $id     = nuevoId('AM');

    // Preevaluación automática: solo señala puntos a revisar, nunca aprueba ni rechaza.
    $puntos = [];
    if ($d['itv'] !== 'Sí')    $puntos[] = $d['itv'] === 'No' ? 'ITV no en vigor' : 'ITV: no sabe si está en vigor';
    if ($d['seguro'] !== 'Sí') $puntos[] = 'Seguro no vigente';
    if ($d['danos'] === 'Sí')  $puntos[] = 'Declara averías o daños';
    if (in_array($d['estado'], ['Regular', 'Necesita reparaciones'], true)) $puntos[] = 'Estado general: ' . mb_strtolower($d['estado']);
    if (!$fotos)               $puntos[] = 'Sin fotos: pedirlas por WhatsApp';
    if (!$dias)                $puntos[] = 'Sin días marcados en el calendario';

    $recomendador = $refOk ? buscar('referidos.jsonl', fn($f) => ($f['codigo'] ?? '') === $refOk) : null;

    $registrada = registrar('solicitudes.jsonl', [
        'id' => $id, 'fecha' => $ahora, 'estado' => 'pendiente de evaluación',
        'nombre' => $nombre, 'telefono' => $telefono,
        'vehiculo' => $d, 'dias_disponibles' => $dias, 'dias_no_disponibles' => $diasNo,
        'fotos' => count($fotos), 'referido_por' => $refOk ?: null, 'puntos_a_revisar' => $puntos,
    ]);

    $c  = "NUEVA PREEVALUACIÓN DE VEHÍCULO\nReferencia: $id\nEstado: pendiente de evaluación (nada se aprueba automáticamente)\n\n";
    $c .= "PROPIETARIO\nNombre: $nombre\nTeléfono: $telefono\n";
    if ($refOk) {
        $c .= "Recomendado por: $refOk" . ($recomendador ? " — {$recomendador['nombre']} ({$recomendador['telefono']})" : ' (código no encontrado en el registro)') . "\n";
    }
    $c .= "\nVEHÍCULO\nMarca y modelo: {$d['marca']} {$d['modelo']}\nAño de matriculación: " . sv($d['ano'])
        . "\nKilometraje: " . sv($d['km']) . "\nCiudad: {$d['ciudad']}\nCombustible: {$d['combustible']}\n";
    $c .= "\nESTADO\nEstado general: {$d['estado']}\nITV en vigor: {$d['itv']}\nSeguro vigente: {$d['seguro']}\nAverías o daños: {$d['danos']}\n";
    if ($d['danos'] === 'Sí') $c .= "Detalle: {$d['danos_detalle']}\n";
    $c .= "\nDISPONIBILIDAD\nGeneral: {$d['disponibilidad']}\nDías disponibles: " . fechasTexto($dias) . "\nDías NO disponibles: " . fechasTexto($diasNo) . "\n";
    $c .= "\nPUNTOS A REVISAR\n" . ($puntos ? '- ' . implode("\n- ", $puntos) : 'Ninguno detectado en las respuestas') . "\n";
    $c .= "\nComentarios:\n" . sv($d['comentarios']) . "\n";
    $c .= "\nFotos adjuntas: " . count($fotos) . "\n";
    if (!$registrada) $c .= "\n⚠ No se pudo guardar en el registro del servidor. Esta solicitud solo consta en este email.\n";
    $c .= pie($telefono);

    if (!enviarEmail("[$id] Preevaluación: {$d['marca']} {$d['modelo']} ({$d['ciudad']})", $c, $fotos)) {
        responder(false, 'No hemos podido enviar tu solicitud ahora mismo. Escríbenos por WhatsApp y te atendemos enseguida.', 500);
    }
    anotarEnvio($rl, $marcas);
    responder(true, 'Solicitud recibida.', 200, ['referencia' => $id]);
}

/* ---------- 2. Búsqueda de coche a la carta ---------- */
if ($tipo === 'busqueda') {
    $nombre = campo('nombre', 100);
    $d = [
        'Marca' => campo('marca', 60), 'Modelo' => campo('modelo', 60),
        'Presupuesto' => campo('presupuesto', 30), 'Año mínimo' => campo('ano', 10),
        'Km máximos' => campo('km', 20), 'Búsqueda' => campo('origen', 30),
    ];
    if ($nombre === '' || $d['Marca'] === '' || $d['Modelo'] === '') {
        responder(false, 'Completa todos los campos obligatorios.', 400);
    }
    $notas = campo('notas', 2000, true);
    $id = nuevoId('BC');
    $registrada = registrar('busquedas.jsonl', ['id' => $id, 'fecha' => $ahora, 'nombre' => $nombre, 'telefono' => $telefono, 'busqueda' => $d, 'notas' => $notas]);
    $c = "NUEVA SOLICITUD — COCHE A LA CARTA\nReferencia: $id\n\nNombre: $nombre\nTeléfono: $telefono\n\n";
    foreach ($d as $k => $val) $c .= "$k: " . sv($val) . "\n";
    $c .= "\nPreferencias:\n" . sv($notas) . "\n";
    if (!$registrada) $c .= "\n⚠ No se pudo guardar en el registro del servidor.\n";
    $c .= pie($telefono);
    if (!enviarEmail("[$id] Búsqueda de coche: {$d['Marca']} {$d['Modelo']}", $c)) {
        responder(false, 'No hemos podido enviar tu solicitud ahora mismo. Escríbenos por WhatsApp y te atendemos enseguida.', 500);
    }
    anotarEnvio($rl, $marcas);
    responder(true, 'Solicitud recibida.', 200, ['referencia' => $id]);
}

/* ---------- 3. Alta de propietario que recomienda ---------- */
if ($tipo === 'referido') {
    $nombre = campo('nombre', 100);
    if ($nombre === '') responder(false, 'Escribe tu nombre.', 400);
    $solicitud = strtoupper(campo('solicitud', 20));
    $solicitud = preg_match('/^AM-\d{6}-[A-Z2-9]{4}$/', $solicitud) ? $solicitud : '';

    // Si este teléfono ya tiene código, se reutiliza en lugar de crear otro.
    $previo = buscar('referidos.jsonl', fn($f) => soloDigitos($f['telefono'] ?? '') === soloDigitos($telefono));
    $codigo = $previo['codigo'] ?? nuevoId('R', false, 6);
    $enlace = WEB . '/?ref=' . $codigo . '#propietarios';

    if (!$previo) {
        $registrada = registrar('referidos.jsonl', [
            'codigo' => $codigo, 'fecha' => $ahora, 'nombre' => $nombre, 'telefono' => $telefono,
            'solicitud_propia' => $solicitud ?: null, 'estado' => 'activo', 'recompensa' => null,
        ]);
        $c  = "NUEVO PROPIETARIO EN EL PROGRAMA DE RECOMENDACIONES\nCódigo: $codigo\n\nNombre: $nombre\nTeléfono: $telefono\n";
        $c .= 'Su propia solicitud: ' . ($solicitud ?: 'No indicada') . "\nEnlace que compartirá: $enlace\n";
        $c .= "\nCuando alguien llegue con este código, la preevaluación indicará «Recomendado por: $codigo».\n";
        if (!$registrada) $c .= "\n⚠ No se pudo guardar en el registro del servidor.\n";
        $c .= pie($telefono);
        if (!enviarEmail("[$codigo] Nuevo recomendador: $nombre", $c)) {
            responder(false, 'No hemos podido crear tu código ahora mismo. Inténtalo más tarde o escríbenos por WhatsApp.', 500);
        }
    }
    anotarEnvio($rl, $marcas);
    responder(true, $previo ? 'Ya tenías un código: te lo mostramos de nuevo.' : 'Código creado.', 200, ['codigo' => $codigo, 'enlace' => $enlace]);
}

/* ---------- 4. Actualización de disponibilidad ---------- */
if ($tipo === 'disponibilidad') {
    $id = strtoupper(campo('referencia', 20));
    if (!preg_match('/^AM-\d{6}-[A-Z2-9]{4}$/', $id)) {
        responder(false, 'Revisa tu referencia. Tiene este formato: AM-261106-K7QX.', 400);
    }
    $dias   = fechas('dias');
    $diasNo = fechas('dias_no');
    if (!$dias && !$diasNo) responder(false, 'Marca al menos un día en el calendario.', 400);

    $dir = carpetaDatos();
    $verificada = false;
    if ($dir !== null) {
        $sol = buscar('solicitudes.jsonl', fn($f) => ($f['id'] ?? '') === $id);
        if (!$sol || soloDigitos($sol['telefono'] ?? '') !== soloDigitos($telefono)) {
            responder(false, 'No encontramos ninguna solicitud con esa referencia y ese teléfono.', 404);
        }
        $verificada = true;
    }
    registrar('disponibilidad.jsonl', ['id' => $id, 'fecha' => $ahora, 'dias_disponibles' => $dias, 'dias_no_disponibles' => $diasNo]);

    $c  = "ACTUALIZACIÓN DE DISPONIBILIDAD\nReferencia: $id\nTeléfono: $telefono\n";
    $c .= $verificada ? "Verificada: la referencia y el teléfono coinciden con la solicitud.\n" : "⚠ Sin verificar: el registro del servidor no está disponible.\n";
    $c .= "\nEste calendario sustituye al anterior.\nDías disponibles: " . fechasTexto($dias) . "\nDías NO disponibles: " . fechasTexto($diasNo) . "\n";
    $c .= pie($telefono);
    if (!enviarEmail("[$id] Disponibilidad actualizada", $c)) {
        responder(false, 'No hemos podido guardar el cambio ahora mismo. Escríbenos por WhatsApp.', 500);
    }
    anotarEnvio($rl, $marcas);
    responder(true, 'Disponibilidad actualizada.', 200, ['referencia' => $id]);
}
