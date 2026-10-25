<?php
/**
 * Instalador de la base de datos por consola (alternativa a importar en phpMyAdmin).
 *
 * Uso, desde la carpeta del proyecto:
 *   C:\xampp\php\php.exe database\instalar.php              crea motocons_db si no existe
 *   C:\xampp\php\php.exe database\instalar.php --recrear    BORRA motocons_db y la crea de nuevo
 *   C:\xampp\php\php.exe database\instalar.php --sin-datos  no carga 03_datos_prueba.sql
 *
 * Usa los datos de conexión de app/config.php.
 */
declare(strict_types=1);

use Motocons\Core\Database;
use Motocons\Core\ScriptSql;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require __DIR__ . '/../app/bootstrap.php';
$db = $config['db'];
$recrear = in_array('--recrear', $argv, true);
$sinDatos = in_array('--sin-datos', $argv, true);

try {
    $servidor = Database::conectar($db, false);
} catch (PDOException $error) {
    fwrite(STDERR, "No se pudo conectar a MySQL/MariaDB en {$db['host']}:{$db['puerto']} con el usuario {$db['usuario']}.\n");
    fwrite(STDERR, "Inicie MySQL en el panel de XAMPP y revise app/config.php.\nDetalle: {$error->getMessage()}\n");
    exit(1);
}

$nombre = $db['nombre'];
if (!preg_match('/^[A-Za-z0-9_]+$/', $nombre)) {
    fwrite(STDERR, "Nombre de base de datos no permitido: {$nombre}\n");
    exit(1);
}

$existe = $servidor->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
$existe->execute([$nombre]);
if ((int) $existe->fetchColumn() > 0) {
    if (!$recrear) {
        fwrite(STDERR, "La base de datos {$nombre} ya existe. No se modificó.\n");
        fwrite(STDERR, "Para borrarla y crearla de nuevo (se pierden sus datos) use --recrear.\n");
        exit(1);
    }
    $servidor->exec("DROP DATABASE `{$nombre}`");
    echo "Base de datos {$nombre} eliminada.\n";
}

$servidor->exec("CREATE DATABASE `{$nombre}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$servidor->exec("USE `{$nombre}`");
echo "Base de datos {$nombre} creada.\n";

$scripts = ['01_motocons_db.sql', '02_ajustes_rf02.sql'];
if (!$sinDatos) {
    $scripts[] = '03_datos_prueba.sql';
}
foreach ($scripts as $script) {
    $n = ScriptSql::ejecutar($servidor, __DIR__ . '/' . $script);
    echo "  ✔ {$script} ({$n} sentencias)\n";
}
echo "Instalación terminada. Abra http://localhost/motocons/public/index.php?r=diagnostico para comprobarla.\n";
