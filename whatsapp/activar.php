<?php
/**
 * Aiman Motors — activar y comprobar el asistente de WhatsApp.
 * Se abre una vez desde el navegador: aimanmotors.com/whatsapp/activar.php
 * Protegido con la «clave_admin» del archivo privado aiman_config.php.
 */
require __DIR__ . '/nucleo.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'");

$cfg = am_config();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$filas = [];
$fila = function (bool $ok, string $txt) use (&$filas) { $filas[] = [$ok, $txt]; };

$dirWa = am_datos('whatsapp');
$hayConfig = $cfg['clave_admin'] !== '';
$entrar = false;
$error = '';

if ($hayConfig && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Freno a intentos repetidos
    $f = $dirWa ? "$dirWa/intentos.json" : null;
    $int = $f && is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $int = array_filter($int, fn($t) => $t > time() - 900);
    if (count($int) >= 5) {
        $error = 'Demasiados intentos. Espera 15 minutos.';
    } elseif (!hash_equals((string)$cfg['clave_admin'], (string)($_POST['clave'] ?? ''))) {
        $int[] = time();
        if ($f) file_put_contents($f, json_encode(array_values($int)));
        sleep(1);
        $error = 'Clave incorrecta.';
    } else {
        $entrar = true;
    }
}

if ($entrar) {
    // 1. Archivo privado y carpeta de datos
    $fila(true, 'Archivo privado aiman_config.php encontrado.');
    $fila($dirWa !== null, $dirWa ? 'Carpeta privada de datos lista.' : 'No se puede escribir en la carpeta de datos.');

    // 2. Clave de Anthropic (IA)
    if ($cfg['anthropic_api_key'] === '') {
        $fila(false, 'Falta la clave de Anthropic (anthropic_api_key).');
    } else {
        [$code, $res] = am_http('POST', rtrim($cfg['url_anthropic'], '/') . '/v1/messages',
            ['x-api-key' => $cfg['anthropic_api_key'], 'anthropic-version' => '2023-06-01'],
            ['model' => $cfg['modelo'], 'max_tokens' => 10, 'messages' => [['role' => 'user', 'content' => 'Di OK']]], 30);
        $fila($code === 200, $code === 200 ? 'La IA responde correctamente (' . $h($cfg['modelo']) . ').'
            : 'La IA no responde (código ' . $code . '). ' . $h($res['error']['message'] ?? 'Revisa la clave y que haya saldo en tu cuenta de Anthropic.'));
    }

    // 3. Clave de 360dialog y webhook
    if ($cfg['d360_api_key'] === '') {
        $fila(false, 'Falta la clave de 360dialog (d360_api_key).');
    } elseif ($dirWa) {
        $secF = "$dirWa/webhook.json";
        $sec = is_file($secF) ? json_decode((string)file_get_contents($secF), true) : null;
        if (!is_array($sec) || empty($sec['clave']) || !empty($_POST['nueva'])) {
            $sec = ['usuario' => 'aiman', 'clave' => bin2hex(random_bytes(24))];
            file_put_contents($secF, json_encode($sec), LOCK_EX);
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'aimanmotors.com';
        $url = 'https://' . $host . dirname($_SERVER['SCRIPT_NAME']) . '/webhook.php?t=' . $sec['clave'];
        [$code, $res] = am_http('POST', rtrim($cfg['url_360'], '/') . '/v1/configs/webhook', ['D360-API-KEY' => $cfg['d360_api_key']], [
            'url' => $url,
            'headers' => ['Authorization' => 'Basic ' . base64_encode($sec['usuario'] . ':' . $sec['clave'])],
        ], 30);
        $ok = $code >= 200 && $code < 300;
        $fila($ok, $ok ? 'WhatsApp conectado: 360dialog enviará los mensajes a tu web.'
            : 'No se pudo conectar con 360dialog (código ' . $code . '). Revisa la clave d360_api_key.');
    }

    $fila((bool)$cfg['activo'], $cfg['activo'] ? 'El asistente está ENCENDIDO.' : 'El asistente está APAGADO (activo => false).');
}
?><!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Asistente de WhatsApp | Aiman Motors</title>
<style>
body{margin:0;background:#070707;color:#f4f4f4;font-family:Arial,Helvetica,sans-serif;line-height:1.5}
main{width:min(560px,92%);margin:60px auto}
h1{font-size:2rem;letter-spacing:-.03em;margin:0 0 8px}
p{color:#aaa}
.card{border:1px solid #272727;background:#111;border-radius:20px;padding:24px;margin-top:22px}
label{display:block;font-weight:700;font-size:.9rem;margin-bottom:8px}
input[type=password]{width:100%;box-sizing:border-box;border:1px solid #2b2b2b;background:#080808;color:#fff;border-radius:12px;padding:13px;font:inherit}
button{margin-top:14px;width:100%;border:0;border-radius:999px;padding:14px;background:#fff;color:#000;font:inherit;font-weight:800;cursor:pointer}
.check{display:flex;gap:8px;align-items:center;color:#aaa;font-size:.88rem;margin-top:12px}
ul{list-style:none;padding:0;margin:0}
li{padding:10px 0;border-bottom:1px solid #1f1f1f;display:flex;gap:10px}
.ok{color:#7ee787}.no{color:#ff8a80}
.err{color:#ff8a80;font-weight:700}
</style></head><body><main>
<h1>Asistente de WhatsApp</h1>
<p>Activa y comprueba el asistente de Aimán Motors.</p>
<?php if (!$hayConfig): ?>
  <div class="card"><p class="err">Falta el archivo privado <strong>aiman_config.php</strong> (o no tiene «clave_admin»).</p>
  <p>Créalo en la carpeta que está <strong>al lado</strong> de public_html, con el contenido que te pasó Claude.</p></div>
<?php elseif ($entrar): ?>
  <div class="card"><ul>
  <?php foreach ($filas as [$ok, $txt]): ?>
    <li><span class="<?= $ok ? 'ok' : 'no' ?>"><?= $ok ? '✓' : '✗' ?></span><span><?= $txt ?></span></li>
  <?php endforeach; ?>
  </ul></div>
  <p>Para probarlo, escribe a tu WhatsApp de empresa desde <strong>otro</strong> teléfono.</p>
<?php else: ?>
  <form class="card" method="post">
    <?php if ($error): ?><p class="err"><?= $h($error) ?></p><?php endif; ?>
    <label for="clave">Clave de administración</label>
    <input id="clave" name="clave" type="password" autocomplete="current-password" required>
    <label class="check"><input type="checkbox" name="nueva" value="1"> Generar una nueva clave de conexión</label>
    <button type="submit">ACTIVAR Y COMPROBAR</button>
  </form>
<?php endif; ?>
</main></body></html>
