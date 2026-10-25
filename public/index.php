<?php
/**
 * Controlador frontal: único punto de entrada web de la aplicación.
 * Aplica cabeceras de seguridad, inicia la sesión, valida CSRF en todo POST,
 * despacha la ruta (?r=...) y traduce las excepciones a páginas de error.
 */
declare(strict_types=1);

use Motocons\Core\AccesoDenegado;
use Motocons\Core\Csrf;
use Motocons\Core\Database;
use Motocons\Core\NoAutenticado;
use Motocons\Core\NoEncontrado;
use Motocons\Core\Sesion;
use Motocons\Core\Vista;
use Motocons\Diagnostico\DiagnosticoController;
use Motocons\Identidad\AuthController;
use Motocons\VentasPresenciales\ClienteController;
use Motocons\VentasPresenciales\VentaController;

$config = require __DIR__ . '/../app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");

Sesion::iniciar((int) $config['app']['minutos_inactividad']);

$rutas = [
    'GET login'            => [AuthController::class, 'formulario'],
    'POST login'           => [AuthController::class, 'iniciar'],
    'POST logout'          => [AuthController::class, 'cerrar'],
    'GET ventas'           => [VentaController::class, 'listado'],
    'GET ventas/nueva'     => [VentaController::class, 'nueva'],
    'POST ventas/registrar'=> [VentaController::class, 'registrar'],
    'GET ventas/ver'       => [VentaController::class, 'ver'],
    'GET clientes/nuevo'   => [ClienteController::class, 'nuevo'],
    'POST clientes/crear'  => [ClienteController::class, 'crear'],
    'GET diagnostico'      => [DiagnosticoController::class, 'index'],
];

$ruta = is_string($_GET['r'] ?? null) ? $_GET['r'] : 'ventas';
$clave = ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . $ruta;

try {
    if (!isset($rutas[$clave])) {
        throw new NoEncontrado();
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::validar($_POST['csrf'] ?? null)) {
        Vista::render('errores/error', [
            'titulo'  => 'Solicitud no válida',
            'mensaje' => 'El formulario expiró o no proviene de esta aplicación. Recargue la página e inténtelo de nuevo.',
        ], 400);
        exit;
    }

    [$clase, $accion] = $rutas[$clave];
    $controlador = $clase === DiagnosticoController::class
        ? new DiagnosticoController($config)        // gestiona su propia conexión para informar fallos
        : new $clase(Database::conectar($config['db']));
    $controlador->$accion();
} catch (NoAutenticado) {
    Sesion::flash('aviso', 'Inicie sesión para continuar.');
    redirigir('login');
} catch (AccesoDenegado $error) {
    Vista::render('errores/error', ['titulo' => 'Acceso denegado', 'mensaje' => $error->getMessage()], 403);
} catch (NoEncontrado $error) {
    Vista::render('errores/error', ['titulo' => 'No encontrado', 'mensaje' => $error->getMessage()], 404);
} catch (PDOException $error) {
    registrar_error($error);
    Vista::render('errores/error', [
        'titulo'  => 'Error de base de datos',
        'mensaje' => 'No fue posible completar la operación con la base de datos. No se guardó ningún cambio parcial.'
            . ' Si el problema continúa, abra el diagnóstico de la instalación.',
        'detalle' => $config['app']['debug'] ? $error->getMessage() : null,
        'enlaceDiagnostico' => true,
    ], 503);
} catch (Throwable $error) {
    registrar_error($error);
    Vista::render('errores/error', [
        'titulo'  => 'Error inesperado',
        'mensaje' => 'Ocurrió un error inesperado. El detalle quedó registrado en storage/logs/app.log.',
        'detalle' => $config['app']['debug'] ? (string) $error : null,
    ], 500);
}
