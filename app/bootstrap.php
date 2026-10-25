<?php
/**
 * Arranque común para la web (public/index.php), el instalador y las pruebas.
 * Registra el autoload de clases, carga las funciones de ayuda y devuelve la configuración.
 */
declare(strict_types=1);

define('MOTOCONS_RAIZ', dirname(__DIR__));

// Autoload: Motocons\VentasPresenciales\VentaController -> app/VentasPresenciales/VentaController.php
spl_autoload_register(static function (string $clase): void {
    $prefijo = 'Motocons\\';
    if (strncmp($clase, $prefijo, strlen($prefijo)) !== 0) {
        return;
    }
    $archivo = __DIR__ . '/' . str_replace('\\', '/', substr($clase, strlen($prefijo))) . '.php';
    if (is_file($archivo)) {
        require $archivo;
    }
});

require __DIR__ . '/Core/funciones.php';

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['app']['zona_horaria']);

return $config;
