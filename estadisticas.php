<?php
/**
 * Aiman Motors — panel de estadísticas (privado, con contraseña).
 * aimanmotors.com/estadisticas.php
 * La primera vez pide crear la contraseña. Se guarda cifrada en la carpeta privada.
 */
date_default_timezone_set('Europe/Madrid');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; form-action 'self'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');

session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Strict']);
session_name('am_panel');
session_start();

function carpeta(): ?string {
    foreach ([getenv('AIMAN_DATOS') ?: '', dirname(__DIR__) . '/aiman_datos', __DIR__ . '/datos-privados'] as $base) {
        if ($base === '') continue;
        $dir = "$base/estadisticas";
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        if (is_dir($dir) && is_writable($dir)) return $dir;
    }
    return null;
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$dir = carpeta();
$claveF = $dir ? "$dir/.clave" : null;
$hayClave = $claveF && is_file($claveF);
$error = '';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrfOk = fn() => hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''));

/* --- Freno a intentos --- */
function demasiados(?string $dir): bool {
    if (!$dir) return false;
    $f = "$dir/.intentos";
    $l = is_file($f) ? array_filter(array_map('intval', explode(',', (string)file_get_contents($f))), fn($t) => $t > time() - 900) : [];
    return count($l) >= 6;
}
function apuntarFallo(?string $dir): void {
    if (!$dir) return;
    $f = "$dir/.intentos";
    $l = is_file($f) ? array_filter(array_map('intval', explode(',', (string)file_get_contents($f))), fn($t) => $t > time() - 900) : [];
    $l[] = time();
    file_put_contents($f, implode(',', $l));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $dir) {
    if (!$csrfOk()) {
        $error = 'La sesión ha caducado. Vuelve a intentarlo.';
    } elseif (isset($_POST['salir'])) {
        $_SESSION = []; session_destroy(); header('Location: estadisticas.php'); exit;
    } elseif (!$hayClave && isset($_POST['nueva'])) {
        $n = (string)$_POST['nueva'];
        if (mb_strlen($n) < 8) $error = 'La contraseña debe tener al menos 8 caracteres.';
        elseif ($n !== (string)($_POST['repite'] ?? '')) $error = 'Las contraseñas no coinciden.';
        else {
            file_put_contents($claveF, password_hash($n, PASSWORD_DEFAULT), LOCK_EX);
            @chmod($claveF, 0600);
            session_regenerate_id(true);
            $_SESSION['ok'] = true; $hayClave = true;
        }
    } elseif ($hayClave && isset($_POST['clave'])) {
        if (demasiados($dir)) $error = 'Demasiados intentos. Espera 15 minutos.';
        elseif (password_verify((string)$_POST['clave'], (string)file_get_contents($claveF))) {
            session_regenerate_id(true); $_SESSION['ok'] = true;
        } else { apuntarFallo($dir); sleep(1); $error = 'Contraseña incorrecta.'; }
    }
}
$dentro = !empty($_SESSION['ok']) && $hayClave;

/* --- Datos --- */
$rango = (int)($_GET['dias'] ?? 30);
if (!in_array($rango, [7, 30, 90], true)) $rango = 30;
$dias = []; $tot = ['visitas' => 0, 'unicos' => 0]; $agr = ['paginas' => [], 'fuentes' => [], 'dispositivos' => [], 'acciones' => []];
if ($dentro && $dir) {
    $meses = [];
    for ($i = $rango - 1; $i >= 0; $i--) {
        $f = date('Y-m-d', strtotime("-$i days"));
        $m = substr($f, 0, 7);
        if (!isset($meses[$m])) $meses[$m] = is_file("$dir/$m.json") ? (json_decode((string)file_get_contents("$dir/$m.json"), true) ?: []) : [];
        $d = $meses[$m][$f] ?? null;
        $dias[$f] = $d['visitas'] ?? 0;
        if ($d) {
            $tot['visitas'] += $d['visitas']; $tot['unicos'] += $d['unicos'];
            foreach ($agr as $k => $_) foreach (($d[$k] ?? []) as $n => $v) $agr[$k][$n] = ($agr[$k][$n] ?? 0) + $v;
        }
    }
    foreach ($agr as $k => $_) arsort($agr[$k]);
}
$acc = $agr['acciones'];
$enviadas = ($acc['envio_preevaluacion'] ?? 0) + ($acc['envio_busqueda'] ?? 0) + ($acc['envio_recomendacion'] ?? 0);
$nombresAcc = [
    'whatsapp' => 'Clics en WhatsApp', 'abrir_preevaluacion' => 'Abren «Alquilar mi coche»', 'envio_preevaluacion' => 'Envían preevaluación',
    'abrir_busqueda' => 'Abren «Buscar mi coche»', 'envio_busqueda' => 'Envían búsqueda', 'uso_simulador' => 'Usan el simulador',
    'envio_recomendacion' => 'Crean enlace de recomendación', 'abrir_disponibilidad' => 'Abren «Actualizar disponibilidad»',
    'envio_disponibilidad' => 'Actualizan disponibilidad', 'instagram' => 'Clics en Instagram', 'facebook' => 'Clics en Facebook',
    'llamada' => 'Clics en el email', 'resenas' => 'Ven las reseñas de Google', 'dejar_resena' => 'Pulsan «Déjanos tu reseña»',
];
$nombresPag = ['/' => 'Portada', '/aviso-legal.html' => 'Aviso legal', '/privacidad.html' => 'Privacidad', '/cookies.html' => 'Cookies'];

function barras(array $datos, array $nombres = [], int $max = 8): string {
    if (!$datos) return '<p class="vacio">Todavía no hay datos.</p>';
    $top = max($datos) ?: 1; $total = array_sum($datos) ?: 1; $o = '';
    foreach (array_slice($datos, 0, $max, true) as $k => $v) {
        $n = htmlspecialchars($nombres[$k] ?? $k, ENT_QUOTES, 'UTF-8');
        $o .= '<div class="bar"><div class="bar-top"><span>' . $n . '</span><b>' . $v . ' <small>' . round($v / $total * 100) . ' %</small></b></div><div class="bar-bg"><i style="width:' . round($v / $top * 100) . '%"></i></div></div>';
    }
    return $o;
}
?><!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Estadísticas | Aiman Motors</title>
<style>
:root{--bg:#070707;--card:#111;--line:#262626;--muted:#9a9a9a}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:#f4f4f4;font-family:Arial,Helvetica,sans-serif;line-height:1.45}
main{width:min(1100px,92%);margin:0 auto;padding:40px 0 70px}
header{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:26px}
h1{margin:0;font-size:2rem;letter-spacing:-.03em}
.sub{color:var(--muted);margin:4px 0 0}
.rango{display:flex;gap:6px}
.rango a{color:#ccc;text-decoration:none;border:1px solid #333;border-radius:999px;padding:9px 15px;font-weight:700;font-size:.9rem}
.rango a.on{background:#fff;color:#000;border-color:#fff}
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.kpi{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:20px}
.kpi small{color:var(--muted);display:block}
.kpi strong{display:block;font-size:2.4rem;letter-spacing:-.04em;margin-top:6px}
.card{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:22px;margin-top:12px}
.card h2{margin:0 0 16px;font-size:1.05rem}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.grid2 .card{margin-top:0}
.chart{display:flex;align-items:flex-end;gap:3px;height:170px}
.chart div{flex:1;background:#fff;border-radius:4px 4px 0 0;min-height:2px;position:relative}
.chart div.zero{background:#2a2a2a}
.chart-x{display:flex;justify-content:space-between;color:var(--muted);font-size:.8rem;margin-top:8px}
.bar{margin-bottom:12px}
.bar-top{display:flex;justify-content:space-between;font-size:.92rem;margin-bottom:5px}
.bar-top small{color:var(--muted);font-weight:400}
.bar-bg{height:8px;background:#222;border-radius:9px;overflow:hidden}
.bar-bg i{display:block;height:100%;background:#fff;border-radius:9px}
.vacio{color:var(--muted);margin:0}
.nota{color:var(--muted);font-size:.85rem;margin-top:18px}
form.login{max-width:420px;margin:60px auto;background:var(--card);border:1px solid var(--line);border-radius:20px;padding:26px}
label{display:block;font-weight:700;font-size:.9rem;margin:14px 0 6px}
input[type=password]{width:100%;border:1px solid #2b2b2b;background:#080808;color:#fff;border-radius:12px;padding:13px;font:inherit}
button{margin-top:16px;width:100%;border:0;border-radius:999px;padding:14px;background:#fff;color:#000;font:inherit;font-weight:800;cursor:pointer}
.salir{background:none;border:1px solid #333;color:#ccc;width:auto;padding:9px 15px;margin:0;font-size:.9rem}
.err{color:#ff8a80;font-weight:700}
@media(max-width:800px){.kpis{grid-template-columns:1fr 1fr}.grid2{grid-template-columns:1fr}}
</style></head><body><main>
<?php if (!$dir): ?>
  <p class="err">No se puede acceder a la carpeta privada de datos.</p>
<?php elseif (!$hayClave): ?>
  <form class="login" method="post">
    <h1>Crea tu contraseña</h1>
    <p class="sub">Es la primera vez que entras. Elige la contraseña del panel de estadísticas.</p>
    <?php if ($error): ?><p class="err"><?= $h($error) ?></p><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <label for="nueva">Contraseña (mínimo 8 caracteres)</label><input id="nueva" name="nueva" type="password" autocomplete="new-password" required minlength="8">
    <label for="repite">Repítela</label><input id="repite" name="repite" type="password" autocomplete="new-password" required minlength="8">
    <button type="submit">CREAR Y ENTRAR</button>
  </form>
<?php elseif (!$dentro): ?>
  <form class="login" method="post">
    <h1>Estadísticas</h1>
    <p class="sub">Aimán Motors Spain</p>
    <?php if ($error): ?><p class="err"><?= $h($error) ?></p><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <label for="clave">Contraseña</label><input id="clave" name="clave" type="password" autocomplete="current-password" required>
    <button type="submit">ENTRAR</button>
  </form>
<?php else: ?>
  <header>
    <div><h1>Estadísticas de la web</h1><p class="sub">Últimos <?= $rango ?> días · aimanmotors.com</p></div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <nav class="rango"><?php foreach ([7, 30, 90] as $r): ?><a href="?dias=<?= $r ?>" class="<?= $r === $rango ? 'on' : '' ?>"><?= $r ?> días</a><?php endforeach; ?></nav>
      <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>"><button class="salir" name="salir" value="1">Salir</button></form>
    </div>
  </header>

  <section class="kpis">
    <div class="kpi"><small>Visitas</small><strong><?= $tot['visitas'] ?></strong></div>
    <div class="kpi"><small>Visitantes</small><strong><?= $tot['unicos'] ?></strong></div>
    <div class="kpi"><small>Clics en WhatsApp</small><strong><?= $acc['whatsapp'] ?? 0 ?></strong></div>
    <div class="kpi"><small>Solicitudes enviadas</small><strong><?= $enviadas ?></strong></div>
  </section>

  <section class="card">
    <h2>Visitas por día</h2>
    <?php $top = max($dias ?: [0]) ?: 1; ?>
    <div class="chart"><?php foreach ($dias as $f => $v): ?><div class="<?= $v ? '' : 'zero' ?>" style="height:<?= max(1, round($v / $top * 100)) ?>%" title="<?= $h(date('d/m', strtotime($f)) . ': ' . $v) ?>"></div><?php endforeach; ?></div>
    <div class="chart-x"><span><?= $h(date('d/m', strtotime(array_key_first($dias)))) ?></span><span>Hoy</span></div>
  </section>

  <div class="grid2" style="margin-top:12px">
    <section class="card"><h2>De dónde llegan</h2><?= barras($agr['fuentes']) ?></section>
    <section class="card"><h2>Qué hacen en la web</h2><?= barras($acc, $nombresAcc, 12) ?></section>
    <section class="card"><h2>Dispositivo</h2><?= barras($agr['dispositivos']) ?></section>
    <section class="card"><h2>Páginas más vistas</h2><?= barras($agr['paginas'], $nombresPag) ?></section>
  </div>
  <p class="nota">Datos anónimos y sin cookies: no se guarda la IP ni datos personales. Cada acción cuenta una vez por visitante y día. Los visitantes se cuentan por día, así que en periodos largos una misma persona puede contar varias veces.</p>
<?php endif; ?>
</main></body></html>
