<?php
/**
 * Auditoría del duelo KlamPanter — corre las 5 pruebas de la rúbrica sobre un proyecto Laravel.
 *   php auditoria.php /ruta/a/tu-proyecto
 * Usa una base SQLite TEMPORAL: no toca la base del proyecto.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

$args = $argv;
$child = ($args[1] ?? '') === '--child';
$dir = realpath($child ? $args[2] : ($args[1] ?? getcwd())) ?: die("Proyecto no encontrado\n");

function boot(string $dir, string $db): void {
    chdir($dir);
    require $dir.'/vendor/autoload.php';
    $app = require $dir.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $db,
            'database.connections.sqlite.busy_timeout' => 5000, 'database.connections.sqlite.foreign_key_constraints' => true]);
    DB::purge('sqlite'); DB::reconnect('sqlite');
}

/* ---------- modo hijo: crea UNA solicitud al mismo tiempo que los demás ---------- */
if ($child) {
    [$db, $userId, $start] = [$args[3], (int) $args[4], (float) $args[5]];
    try {
        boot($dir, $db);
        time_sleep_until($start);
        $s = App\Models\Solicitud::create(['user_id' => $userId, 'tipo_constancia' => 'laboral', 'motivo' => 'concurrencia']);
        echo 'OK '.$s->folio;
    } catch (Throwable $e) { echo 'ERR '.class_basename($e).': '.strtok($e->getMessage(), "\n"); }
    exit(0);
}

/* ---------- utilidades de salida ---------- */
const G = "\033[1;32m", R = "\033[1;31m", Y = "\033[1;33m", B = "\033[1m", D = "\033[2m", X = "\033[0m";
$res = [];
function titulo($n, $t) { echo "\n".B."━━ PRUEBA {$n} · {$t}".X."\n"; }
function info($m) { echo D."   {$m}".X."\n"; }
function veredicto(&$res, $n, bool $ok, string $porque) { $res[$n] = $ok; echo ($ok ? G.'   ✓ PASA' : R.'   ✗ FALLA').X." — {$porque}\n"; }
function intenta(callable $f): ?Throwable { try { $f(); return null; } catch (Throwable $e) { return $e; } }
function corto(Throwable $e): string { return class_basename($e).': '.mb_strimwidth(strtok($e->getMessage(), "\n"), 0, 110, '…'); }

$db = sys_get_temp_dir().'/auditoria_'.getmypid().'.sqlite';
@unlink($db); touch($db);
boot($dir, $db);
$branch = trim((string) shell_exec('git -C '.escapeshellarg($dir).' branch --show-current 2>/dev/null'));
echo "\n".B."AUDITORÍA KlamPanter".X."  ·  {$dir}  ·  rama {$branch}\n".D."Base temporal: {$db}".X."\n";

$e = intenta(fn () => Artisan::call('migrate:fresh', ['--force' => true]));
info($e ? 'Migraciones: '.R.corto($e).X : 'Migraciones: OK');

/* ---------- preparación común ---------- */
$listo = null; $sol = null; $A = $Rv = null;
$prep = intenta(function () use (&$A, &$Rv, &$sol) {
    if (! class_exists(App\Models\Solicitud::class)) throw new RuntimeException('No existe App\Models\Solicitud');
    if (! method_exists(App\Models\Solicitud::class, 'transicionar')) throw new RuntimeException('No hay un método para cambiar de estado');
    $A  = App\Models\User::factory()->create(); $A->forceFill(['role' => 'solicitante'])->save();
    $Rv = App\Models\User::factory()->create(); $Rv->forceFill(['role' => 'revisor'])->save();
    $sol = App\Models\Solicitud::create(['user_id' => $A->id, 'tipo_constancia' => 'laboral', 'motivo' => 'Auditoría']);
    $sol->transicionar(App\Enums\EstadoSolicitud::from('enviada'), $A);
    $sol->transicionar(App\Enums\EstadoSolicitud::from('en_revision'), $Rv);
    $sol->refresh();
});
if ($prep) info('Preparación: '.R.corto($prep).X);
else info("Solicitud {$sol->folio} en estado ".$sol->estado->value.' · solicitante #'.$A->id.' · revisor #'.$Rv->id);

/* 1 */ titulo(1, 'El solicitante intenta aprobar su propia solicitud');
if ($prep) veredicto($res, 1, false, 'no implementado ('.corto($prep).')');
else {
    $e = intenta(fn () => $sol->transicionar(App\Enums\EstadoSolicitud::from('aprobada'), $A));
    $sol->refresh();
    info('Respuesta: '.($e ? corto($e) : 'sin error'));
    veredicto($res, 1, $e !== null && $sol->estado->value === 'en_revision', $e ? 'bloqueado, sigue en '.$sol->estado->value : 'la aprobó');
}

/* 2 */ titulo(2, 'El revisor rechaza sin escribir motivo');
if ($prep) veredicto($res, 2, false, 'no implementado');
else {
    $e1 = intenta(fn () => $sol->transicionar(App\Enums\EstadoSolicitud::from('rechazada'), $Rv, null));
    $e2 = intenta(fn () => $sol->transicionar(App\Enums\EstadoSolicitud::from('rechazada'), $Rv, '   '));
    $sol->refresh();
    info('null → '.($e1 ? corto($e1) : 'sin error')); info('"   " → '.($e2 ? corto($e2) : 'sin error'));
    veredicto($res, 2, $e1 && $e2 && $sol->estado->value === 'en_revision', $e1 && $e2 ? 'exige motivo' : 'permitió rechazar sin motivo');
}

/* 3 */ titulo(3, 'Intento editar y borrar la bitácora');
$e = intenta(function () use (&$res) {
    if (! class_exists(App\Models\SolicitudMovimiento::class)) throw new RuntimeException('No existe el modelo de bitácora');
    $m = App\Models\SolicitudMovimiento::query()->orderBy('id')->firstOrFail();
    $antes = json_encode(DB::table('solicitud_movimientos')->where('id', $m->id)->first());
    $n0 = DB::table('solicitud_movimientos')->count();
    $intentos = [
        'Eloquent update'  => fn () => $m->update(['ip' => '6.6.6.6']),
        'Eloquent delete'  => fn () => $m->delete(),
        'Query builder update' => fn () => DB::table('solicitud_movimientos')->where('id', $m->id)->update(['ip' => '6.6.6.6']),
        'Query builder delete' => fn () => DB::table('solicitud_movimientos')->where('id', $m->id)->delete(),
        'SQL directo'      => fn () => DB::statement("UPDATE solicitud_movimientos SET ip = '6.6.6.6'"),
    ];
    $bloqueados = 0;
    foreach ($intentos as $nombre => $f) { $x = intenta($f); $bloqueados += $x ? 1 : 0; info(str_pad($nombre, 22).($x ? G.'bloqueado'.X : R.'¡PERMITIDO!'.X)); }
    $despues = json_encode(DB::table('solicitud_movimientos')->where('id', $m->id)->first());
    $intacto = $antes === $despues && $n0 === DB::table('solicitud_movimientos')->count();
    veredicto($res, 3, $bloqueados === count($intentos) && $intacto, "{$bloqueados}/".count($intentos).' intentos bloqueados · registro '.($intacto ? 'intacto' : 'MODIFICADO'));
});
if ($e) veredicto($res, 3, false, 'no implementado ('.corto($e).')');

/* 4 */ titulo(4, 'Folio con 10 solicitudes creadas al mismo tiempo');
if ($prep) veredicto($res, 4, false, 'no implementado');
else {
    $n = 10; $start = microtime(true) + 3; $procs = [];
    for ($i = 0; $i < $n; $i++) {
        $cmd = [PHP_BINARY, __FILE__, '--child', $dir, $db, (string) $A->id, (string) $start];
        $procs[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    $salidas = [];
    foreach ($procs as $i => $p) { $salidas[] = trim(stream_get_contents($pipes[$i][1])); proc_close($p); }
    $folios = []; $errores = [];
    foreach ($salidas as $s) str_starts_with($s, 'OK ') ? $folios[] = substr($s, 3) : $errores[] = $s ?: 'sin salida';
    sort($folios);
    info('Folios: '.implode(' ', array_map(fn ($f) => substr($f, -6), $folios)));
    foreach (array_unique($errores) as $er) info(R.'Error en proceso: '.$er.X);
    $unicos = count(array_unique($folios)) === count($folios);
    $nums = array_map(fn ($f) => (int) substr($f, -6), $folios);
    $consec = $nums && max($nums) - min($nums) + 1 === count($nums);
    $formato = $folios && preg_match('/^CONST-\d{4}-\d{6}$/', $folios[0]);
    veredicto($res, 4, count($folios) === $n && $unicos && $consec && $formato,
        count($folios)."/{$n} creadas · ".($unicos ? 'sin duplicados' : 'DUPLICADOS').' · '.($consec ? 'consecutivos' : 'con huecos').' · formato '.($formato ? 'OK' : 'incorrecto'));
}

/* 5 */ titulo(5, 'Código limpio: compila, sin dd(), suite completa en verde');
$archivos = array_filter(explode("\n", trim((string) shell_exec('cd '.escapeshellarg($dir).' && { git diff --name-only; git ls-files --others --exclude-standard; } | grep "\.php$"'))));
$malos = [];
foreach ($archivos as $f) { exec(PHP_BINARY.' -l '.escapeshellarg("{$dir}/{$f}").' 2>&1', $o, $rc); if ($rc) $malos[] = $f; $o = []; }
$dd = trim((string) shell_exec('cd '.escapeshellarg($dir).' && grep -rnE "\b(dd|dump|var_dump|ray)\(" app/ 2>/dev/null | head -3'));
// Entorno limpio (proc_open con env explícito): el arranque de Laravel arriba exportó el .env (putenv);
// si se hereda, phpunit.xml no puede forzar la base :memory: y la suite corre contra la base real.
$proc = proc_open([PHP_BINARY, 'artisan', 'test'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tp, $dir,
    ['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin', 'HOME' => getenv('HOME') ?: '/tmp', 'LANG' => 'C.UTF-8']);
$salida = preg_replace('/\e\[[\d;]*m/', '', stream_get_contents($tp[1]).stream_get_contents($tp[2])); proc_close($proc);
preg_match_all('/^\s*Tests:.*$/m', $salida, $mm); $tests = end($mm[0]) ?: '';
preg_match_all('/FAILED\s+(.+)$/m', $salida, $ff);
foreach (array_slice(array_unique($ff[1]), 0, 3) as $f) info(R.'Falla: '.mb_strimwidth(trim($f), 0, 110, '…').X);
$verde = $tests !== '' && ! str_contains($tests, 'failed');
info(count($archivos).' archivos PHP nuevos/modificados · '.(count($malos) ? R.count($malos).' NO COMPILAN: '.implode(', ', $malos).X : 'todos compilan'));
info('dd()/dump(): '.($dd ? R.$dd.X : 'ninguno'));
info('Suite: '.trim(preg_replace('/\e\[[\d;]*m/', '', $tests)));
$vacio = count($archivos) === 0;
veredicto($res, 5, ! $vacio && ! $malos && ! $dd && $verde, $vacio ? 'no entregó código' : (! $malos && ! $dd && $verde ? 'limpio' : 'con problemas'));

/* ---------- marcador ---------- */
$ok = count(array_filter($res));
echo "\n".B.'━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━'.X."\n";
echo B.'   RESULTADO: '.($ok === 5 ? G : ($ok ? Y : R))."{$ok} / 5".X.B.' pruebas de auditoría'.X."\n";
echo B.'━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━'.X."\n\n";
@unlink($db);
