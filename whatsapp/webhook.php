<?php
/**
 * Aiman Motors — asistente de WhatsApp (webhook de 360dialog).
 *
 * 360dialog llama a esta URL con cada mensaje. El asistente:
 *  - responde dudas con IA (Claude) usando conocimiento.php,
 *  - recoge preevaluaciones de coches y búsquedas a la carta (las registra y te avisa por email),
 *  - pasa el chat a Aimán cuando hace falta y se calla durante unas horas,
 *  - se calla también si Aimán contesta él mismo desde la app de WhatsApp Business.
 */

require __DIR__ . '/nucleo.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
$cfg = am_config();

/* ---------- 1. Autenticación del webhook ---------- */
$sec = am_datos('whatsapp') ? json_decode((string)@file_get_contents(am_datos('whatsapp') . '/webhook.json'), true) : null;
if (!is_array($sec) || empty($sec['clave'])) { http_response_code(503); exit('no configurado'); }
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$okBasic = $auth !== '' && hash_equals('Basic ' . base64_encode($sec['usuario'] . ':' . $sec['clave']), $auth);
$okPhp   = isset($_SERVER['PHP_AUTH_PW']) && hash_equals($sec['clave'], (string)$_SERVER['PHP_AUTH_PW']);
$okToken = isset($_GET['t']) && hash_equals($sec['clave'], (string)$_GET['t']);
if (!$okBasic && !$okPhp && !$okToken) { http_response_code(401); exit('no autorizado'); }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { exit('ok'); }

/* ---------- 2. Responder 200 enseguida y seguir trabajando ---------- */
$raw = file_get_contents('php://input');
ignore_user_abort(true);
@set_time_limit(90);
echo 'ok';
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
else { header('Content-Length: 2'); header('Connection: close'); @ob_end_flush(); flush(); }

$data = json_decode($raw, true);
if (!is_array($data)) exit;

/* ---------- 3. Recorrer el aviso ---------- */
foreach (($data['entry'] ?? []) as $entry) {
    foreach (($entry['changes'] ?? []) as $change) {
        $v = $change['value'] ?? [];
        // Mensajes que Aimán envía desde la app (coexistencia): el asistente se aparta.
        foreach (($v['message_echoes'] ?? []) as $echo) {
            if (!empty($echo['to'])) am_pausar((string)$echo['to'], 'Aimán ha contestado desde el móvil', false);
        }
        $nombres = [];
        foreach (($v['contacts'] ?? []) as $c) $nombres[$c['wa_id'] ?? ''] = $c['profile']['name'] ?? '';
        foreach (($v['messages'] ?? []) as $m) {
            $de = (string)($m['from'] ?? '');
            $negocio = preg_replace('/\D/', '', (string)($v['metadata']['display_phone_number'] ?? ''));
            if ($de === '' || ($negocio !== '' && $de === $negocio)) continue;
            am_procesar($m, $nombres[$de] ?? '');
        }
    }
}
exit;

/* ================= Estado de cada chat ================= */

function am_ruta_chat(string $tel): ?string {
    $d = am_datos('whatsapp/chats');
    return $d ? "$d/" . hash('sha256', $tel) . '.json' : null;
}
function am_leer_chat(string $tel): array {
    $r = am_ruta_chat($tel);
    $c = $r && is_file($r) ? json_decode((string)file_get_contents($r), true) : null;
    return is_array($c) ? $c : ['telefono' => $tel, 'nombre' => '', 'historial' => [], 'pausa_hasta' => 0, 'ultima' => 0, 'referencias' => []];
}
function am_guardar_chat(array $c): void {
    $r = am_ruta_chat($c['telefono']);
    if ($r) @file_put_contents($r, json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}
function am_pausar(string $tel, string $motivo, bool $avisar, string $resumen = ''): void {
    $c = am_leer_chat($tel);
    $c['pausa_hasta'] = time() + (int)am_config()['horas_pausa'] * 3600;
    am_guardar_chat($c);
    if ($avisar) {
        $nombre = $c['nombre'] ?: 'Sin nombre';
        am_email("[WhatsApp] Te necesitan: $nombre", "El asistente ha pasado este chat a ti.\n\nCliente: $nombre\nTeléfono: +$tel\nMotivo: $motivo\n"
            . ($resumen !== '' ? "\nResumen:\n$resumen\n" : '')
            . "\nAbrir chat: " . am_wa_link($tel) . "\n\nEl asistente no responderá en este chat durante " . am_config()['horas_pausa'] . " horas.\n");
    }
}

/** Evita procesar dos veces el mismo mensaje (360dialog reintenta). */
function am_ya_visto(string $id): bool {
    $d = am_datos('whatsapp');
    if (!$d || $id === '') return false;
    $f = "$d/vistos.txt";
    $fh = fopen($f, 'c+');
    if (!$fh) return false;
    flock($fh, LOCK_EX);
    $ids = array_filter(explode("\n", stream_get_contents($fh)));
    $visto = in_array($id, $ids, true);
    if (!$visto) {
        $ids[] = $id;
        $ids = array_slice($ids, -1000);
        ftruncate($fh, 0); rewind($fh); fwrite($fh, implode("\n", $ids));
    }
    flock($fh, LOCK_UN); fclose($fh);
    return $visto;
}

/* ================= Un mensaje entrante ================= */

function am_procesar(array $m, string $perfil): void {
    $tel = (string)$m['from'];
    if (am_ya_visto((string)($m['id'] ?? ''))) return;

    // Un cerrojo por chat: si llegan varios mensajes seguidos, se atienden en orden.
    $lockDir = am_datos('whatsapp/chats');
    $lock = $lockDir ? fopen("$lockDir/" . hash('sha256', $tel) . '.lock', 'c') : null;
    if ($lock) flock($lock, LOCK_EX);

    try {
        $c = am_leer_chat($tel);
        if ($perfil !== '' && $c['nombre'] === '') $c['nombre'] = mb_substr($perfil, 0, 60);
        // Conversación nueva si lleva más de 7 días sin hablar.
        if ($c['ultima'] && time() - $c['ultima'] > 7 * 86400) $c['historial'] = [];
        $c['ultima'] = time();

        $texto = am_texto_mensaje($m);
        if ($texto === null) { am_guardar_chat($c); return; }
        $c['historial'][] = ['role' => 'user', 'content' => $texto];

        $cfg = am_config();
        $pausado = $c['pausa_hasta'] > time();
        if (!$cfg['activo'] || $pausado || $cfg['anthropic_api_key'] === '' || $cfg['d360_api_key'] === '') {
            am_recortar($c);
            am_guardar_chat($c);
            return; // Lo atiende Aimán desde el móvil.
        }

        $respuesta = am_ia($c);
        if ($respuesta === null) {
            $respuesta = '¡Gracias por escribir a Aimán Motors! Ahora mismo te atiende Aimán personalmente por este chat.';
            am_guardar_chat($c);
            am_pausar($tel, 'El asistente ha tenido un error técnico', true);
            $c = am_leer_chat($tel);
        }
        if (trim($respuesta) !== '') {
            am_wa_enviar($tel, $respuesta);
            $c['historial'][] = ['role' => 'assistant', 'content' => $respuesta];
        }
        am_recortar($c);
        am_guardar_chat($c);
    } catch (Throwable $e) {
        am_log('Error procesando mensaje: ' . $e->getMessage());
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

function am_texto_mensaje(array $m): ?string {
    $t = $m['type'] ?? '';
    switch ($t) {
        case 'text':        return mb_substr(trim((string)($m['text']['body'] ?? '')), 0, 2000);
        case 'button':      return (string)($m['button']['text'] ?? '');
        case 'interactive': return (string)($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? '');
        case 'image':       return '[El cliente ha enviado una foto]' . (!empty($m['image']['caption']) ? ' ' . $m['image']['caption'] : '');
        case 'video':       return '[El cliente ha enviado un vídeo]' . (!empty($m['video']['caption']) ? ' ' . $m['video']['caption'] : '');
        case 'document':    return '[El cliente ha enviado un documento]';
        case 'audio':       return '[El cliente ha enviado una nota de voz, que no puedes escuchar]';
        case 'location':    return '[El cliente ha compartido una ubicación]';
        case 'contacts':    return '[El cliente ha compartido un contacto]';
        case 'reaction':
        case 'sticker':     return null; // no requieren respuesta
        default:            return '[Mensaje de un tipo no compatible]';
    }
}

/** Mantiene el historial corto y empezando por un mensaje del cliente. */
function am_recortar(array &$c): void {
    $c['historial'] = array_slice($c['historial'], -30);
    while ($c['historial'] && $c['historial'][0]['role'] !== 'user') array_shift($c['historial']);
}

/* ================= IA ================= */

function am_herramientas(): array {
    $s = fn($d) => ['type' => 'string', 'description' => $d];
    return [
        [
            'name' => 'guardar_preevaluacion',
            'description' => 'Registra la preevaluación del coche de un propietario que quiere alquilarlo. Úsala cuando tengas al menos marca, modelo, ciudad y disponibilidad. Devuelve la referencia de la solicitud.',
            'input_schema' => ['type' => 'object', 'properties' => [
                'nombre' => $s('Nombre del propietario'), 'marca' => $s('Marca'), 'modelo' => $s('Modelo'),
                'ano' => $s('Año de matriculación'), 'km' => $s('Kilometraje'), 'ciudad' => $s('Ciudad'),
                'combustible' => $s('Combustible'), 'estado' => $s('Estado general: Muy bueno, Bueno, Regular o Necesita reparaciones'),
                'itv' => $s('ITV en vigor: Sí, No o No lo sé'), 'seguro' => $s('Seguro vigente: Sí o No'),
                'danos' => $s('¿Tiene averías o daños?: Sí o No'), 'danos_detalle' => $s('Qué averías o daños tiene'),
                'disponibilidad' => $s('Cuándo puede estar disponible'), 'fotos' => $s('Si ha enviado fotos por WhatsApp: Sí o No'),
                'comentarios' => $s('Otros datos útiles'),
            ], 'required' => ['marca', 'modelo', 'ciudad', 'disponibilidad']],
        ],
        [
            'name' => 'guardar_busqueda',
            'description' => 'Registra la búsqueda de un coche a la carta. Úsala cuando tengas al menos el tipo de coche y el presupuesto. Devuelve la referencia.',
            'input_schema' => ['type' => 'object', 'properties' => [
                'nombre' => $s('Nombre del cliente'), 'marca' => $s('Marca o tipo de coche'), 'modelo' => $s('Modelo'),
                'presupuesto' => $s('Presupuesto'), 'ano' => $s('Año mínimo'), 'km' => $s('Kilómetros máximos'),
                'origen' => $s('Nacional, importación o me da igual'), 'notas' => $s('Preferencias: motor, cambio, color, extras'),
            ], 'required' => ['marca', 'presupuesto']],
        ],
        [
            'name' => 'pasar_a_humano',
            'description' => 'Pasa la conversación a Aimán (persona). El asistente dejará de responder en este chat durante unas horas y Aimán recibirá un aviso.',
            'input_schema' => ['type' => 'object', 'properties' => [
                'motivo' => $s('Por qué hace falta una persona'),
                'resumen' => $s('Resumen breve de lo que necesita el cliente y los datos que ha dado'),
            ], 'required' => ['motivo', 'resumen']],
        ],
    ];
}

/** Ejecuta la conversación con Claude. Devuelve el texto a enviar o null si falla. */
function am_ia(array &$c): ?string {
    $cfg = am_config();
    $sistema = (require __DIR__ . '/conocimiento.php')
        . "\n\n# Contexto de este chat\nFecha: " . date('d/m/Y H:i') . "\nNombre de perfil de WhatsApp: " . ($c['nombre'] ?: 'desconocido')
        . "\nTeléfono: lo tenemos (no lo pidas).\n"
        . ($c['referencias'] ? 'Referencias ya creadas en este chat: ' . implode(', ', $c['referencias']) . "\n" : '');
    $mensajes = array_map(fn($h) => ['role' => $h['role'], 'content' => $h['content']], $c['historial']);
    $textoFinal = '';

    for ($vuelta = 0; $vuelta < 4; $vuelta++) {
        [$code, $res] = am_http('POST', rtrim($cfg['url_anthropic'], '/') . '/v1/messages', [
            'x-api-key' => $cfg['anthropic_api_key'],
            'anthropic-version' => '2023-06-01',
        ], [
            'model' => $cfg['modelo'],
            'max_tokens' => 700,
            'system' => $sistema,
            'tools' => am_herramientas(),
            'messages' => $mensajes,
        ], 45);
        if ($code !== 200 || !is_array($res) || empty($res['content'])) {
            am_log("IA falló ($code): " . json_encode($res));
            return null;
        }
        $mensajes[] = ['role' => 'assistant', 'content' => $res['content']];
        $resultados = [];
        foreach ($res['content'] as $b) {
            if (($b['type'] ?? '') === 'text') $textoFinal .= ($textoFinal !== '' ? "\n" : '') . trim($b['text']);
            if (($b['type'] ?? '') === 'tool_use') {
                $resultados[] = ['type' => 'tool_result', 'tool_use_id' => $b['id'], 'content' => am_herramienta($b['name'], (array)($b['input'] ?? []), $c)];
            }
        }
        if (($res['stop_reason'] ?? '') !== 'tool_use' || !$resultados) break;
        $mensajes[] = ['role' => 'user', 'content' => $resultados];
        $textoFinal = ''; // el texto bueno es el que llega después de usar la herramienta
    }
    return $textoFinal;
}

function am_herramienta(string $nombre, array $in, array &$c): string {
    $v = fn($k, $max = 200) => mb_substr(trim((string)($in[$k] ?? '')), 0, $max);
    $sv = fn($s) => $s === '' ? 'Sin especificar' : $s;
    $tel = $c['telefono'];
    if ($v('nombre') !== '') $c['nombre'] = $v('nombre', 60);
    $nombre_c = $c['nombre'] ?: 'Sin nombre';

    if ($nombre === 'guardar_preevaluacion') {
        $id = am_id('AM');
        $d = [];
        foreach (['marca', 'modelo', 'ano', 'km', 'ciudad', 'combustible', 'estado', 'itv', 'seguro', 'danos', 'danos_detalle', 'disponibilidad', 'fotos', 'comentarios'] as $k) $d[$k] = $v($k, $k === 'comentarios' || $k === 'danos_detalle' ? 1000 : 120);
        $puntos = [];
        if ($d['itv'] !== '' && stripos($d['itv'], 'sí') !== 0 && stripos($d['itv'], 'si') !== 0) $puntos[] = 'ITV: ' . $d['itv'];
        if ($d['seguro'] !== '' && stripos($d['seguro'], 'no') === 0) $puntos[] = 'Seguro no vigente';
        if ($d['danos'] !== '' && (stripos($d['danos'], 'sí') === 0 || stripos($d['danos'], 'si') === 0)) $puntos[] = 'Declara averías o daños';
        if (preg_match('/regular|reparac/i', $d['estado'])) $puntos[] = 'Estado general: ' . $d['estado'];
        foreach (['ano' => 'año', 'km' => 'kilometraje', 'estado' => 'estado', 'itv' => 'ITV', 'seguro' => 'seguro'] as $k => $t) if ($d[$k] === '') $puntos[] = "Falta $t";
        am_registrar('solicitudes.jsonl', ['id' => $id, 'fecha' => date('c'), 'origen' => 'whatsapp', 'estado' => 'pendiente de evaluación',
            'nombre' => $nombre_c, 'telefono' => '+' . $tel, 'vehiculo' => $d, 'puntos_a_revisar' => $puntos]);
        $c['referencias'][] = $id;
        $txt = "NUEVA PREEVALUACIÓN POR WHATSAPP\nReferencia: $id\nEstado: pendiente de evaluación (nada se aprueba automáticamente)\n\n"
            . "Propietario: $nombre_c\nTeléfono: +$tel\n\n"
            . "Coche: {$d['marca']} {$d['modelo']}\nAño: {$sv($d['ano'])}\nKilometraje: {$sv($d['km'])}\nCiudad: {$d['ciudad']}\nCombustible: {$sv($d['combustible'])}\n"
            . "Estado: {$sv($d['estado'])}\nITV en vigor: {$sv($d['itv'])}\nSeguro vigente: {$sv($d['seguro'])}\nAverías o daños: {$sv($d['danos'])}"
            . ($d['danos_detalle'] !== '' ? " — {$d['danos_detalle']}" : '') . "\nDisponibilidad: {$d['disponibilidad']}\nFotos por WhatsApp: {$sv($d['fotos'])}\n"
            . "\nPUNTOS A REVISAR\n" . ($puntos ? '- ' . implode("\n- ", $puntos) : 'Ninguno detectado') . "\n"
            . "\nComentarios: {$sv($d['comentarios'])}\n\nAbrir chat: " . am_wa_link($tel) . "\n";
        am_email("[$id] Preevaluación por WhatsApp: {$d['marca']} {$d['modelo']} ({$d['ciudad']})", $txt);
        return "Registrada con la referencia $id. Dásela al cliente y dile que Aimán revisará el caso y le contactará. No digas que está aceptado.";
    }

    if ($nombre === 'guardar_busqueda') {
        $id = am_id('BC');
        $d = [];
        foreach (['marca', 'modelo', 'presupuesto', 'ano', 'km', 'origen', 'notas'] as $k) $d[$k] = $v($k, $k === 'notas' ? 1000 : 120);
        am_registrar('busquedas.jsonl', ['id' => $id, 'fecha' => date('c'), 'origen' => 'whatsapp', 'nombre' => $nombre_c, 'telefono' => '+' . $tel, 'busqueda' => $d]);
        $c['referencias'][] = $id;
        am_email("[$id] Búsqueda por WhatsApp: {$d['marca']} {$d['modelo']}",
            "NUEVA BÚSQUEDA DE COCHE POR WHATSAPP\nReferencia: $id\n\nCliente: $nombre_c\nTeléfono: +$tel\n\n"
            . "Coche: {$d['marca']} {$sv($d['modelo'])}\nPresupuesto: {$d['presupuesto']}\nAño mínimo: {$sv($d['ano'])}\nKm máximos: {$sv($d['km'])}\n"
            . "Nacional / importación: {$sv($d['origen'])}\nPreferencias: {$sv($d['notas'])}\n\nAbrir chat: " . am_wa_link($tel) . "\n");
        return "Registrada con la referencia $id. Dásela al cliente y dile que Aimán le contactará con opciones.";
    }

    if ($nombre === 'pasar_a_humano') {
        am_guardar_chat($c);
        am_pausar($tel, $v('motivo', 300), true, $v('resumen', 1500));
        $c['pausa_hasta'] = am_leer_chat($tel)['pausa_hasta'];
        return 'Hecho: Aimán ha recibido el aviso y atenderá este chat personalmente. Despídete diciendo que Aimán le escribirá por aquí lo antes posible. No hagas más preguntas.';
    }

    return 'Herramienta desconocida.';
}
