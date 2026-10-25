<?php
/**
 * Mini marco de pruebas sin dependencias (no requiere Composer ni PHPUnit).
 * Las pruebas de integración usan una base de datos aparte, motocons_test,
 * que se crea desde cero con los mismos scripts SQL de la instalación.
 */
declare(strict_types=1);

use Motocons\Core\Database;
use Motocons\Core\ScriptSql;

$GLOBALS['config'] = require __DIR__ . '/../app/bootstrap.php';
$GLOBALS['resultados'] = ['ok' => 0, 'fallos' => []];

const BD_PRUEBAS = 'motocons_test';

final class FalloPrueba extends RuntimeException
{
}

function configuracionPruebas(): array
{
    return ['nombre' => BD_PRUEBAS] + $GLOBALS['config']['db'];
}

/** Crea motocons_test desde cero (01 + 02 + 03) y devuelve una conexión a ella. */
function reiniciarBaseDePruebas(): PDO
{
    $servidor = Database::conectar($GLOBALS['config']['db'], false);
    $servidor->exec('DROP DATABASE IF EXISTS `' . BD_PRUEBAS . '`');
    $servidor->exec('CREATE DATABASE `' . BD_PRUEBAS . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    $servidor->exec('USE `' . BD_PRUEBAS . '`');
    foreach (['01_motocons_db.sql', '02_ajustes_rf02.sql', '03_datos_prueba.sql'] as $script) {
        ScriptSql::ejecutar($servidor, __DIR__ . '/../database/' . $script);
    }
    return Database::conectar(configuracionPruebas());
}

function seccion(string $titulo): void
{
    echo PHP_EOL . "== {$titulo} ==" . PHP_EOL;
}

function prueba(string $nombre, callable $cuerpo): void
{
    try {
        $cuerpo();
        $GLOBALS['resultados']['ok']++;
        echo "  ✔ {$nombre}" . PHP_EOL;
    } catch (Throwable $error) {
        $GLOBALS['resultados']['fallos'][] = $nombre;
        $tipo = $error instanceof FalloPrueba ? '' : get_class($error) . ': ';
        echo "  ✘ {$nombre}" . PHP_EOL . "      {$tipo}{$error->getMessage()}" . PHP_EOL;
    }
}

function afirmar(bool $condicion, string $mensaje): void
{
    if (!$condicion) {
        throw new FalloPrueba($mensaje);
    }
}

function afirmarIgual(mixed $esperado, mixed $real, string $mensaje = ''): void
{
    if ($esperado !== $real) {
        throw new FalloPrueba(trim("{$mensaje} Esperado: " . var_export($esperado, true) . ', obtenido: ' . var_export($real, true)));
    }
}

/** Ejecuta $accion y exige que lance $clase; devuelve la excepción para inspeccionarla. */
function afirmarExcepcion(string $clase, callable $accion): Throwable
{
    try {
        $accion();
    } catch (Throwable $error) {
        if ($error instanceof $clase) {
            return $error;
        }
        throw new FalloPrueba("Se esperaba {$clase} y se lanzó " . get_class($error) . ': ' . $error->getMessage());
    }
    throw new FalloPrueba("Se esperaba {$clase} y no se lanzó ninguna excepción.");
}

function valor(PDO $pdo, string $sql, array $parametros = []): mixed
{
    $consulta = $pdo->prepare($sql);
    $consulta->execute($parametros);
    return $consulta->fetchColumn();
}

function resumenYSalir(): never
{
    $ok = $GLOBALS['resultados']['ok'];
    $fallos = $GLOBALS['resultados']['fallos'];
    echo PHP_EOL . str_repeat('-', 60) . PHP_EOL;
    echo 'Resultado: ' . $ok . ' correctas, ' . count($fallos) . ' fallidas.' . PHP_EOL;
    foreach ($fallos as $nombre) {
        echo "  - FALLÓ: {$nombre}" . PHP_EOL;
    }
    exit($fallos === [] ? 0 : 1);
}
