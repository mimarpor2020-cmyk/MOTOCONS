<?php
/**
 * Proceso auxiliar de la prueba de concurrencia: intenta vender una moto en el
 * instante indicado, para que dos procesos compitan por la última unidad.
 *
 * Uso: php worker_compra.php <vendedor_id> <moto_id> <instante_unix_con_decimales>
 * Imprime "OK <venta_id>" o "RECHAZADA <mensaje>".
 */
declare(strict_types=1);

use Motocons\Core\Database;
use Motocons\Core\ErrorValidacion;
use Motocons\VentasPresenciales\RegistrarVentaPresencial;

require __DIR__ . '/lib.php';

[, $vendedorId, $motoId, $instante] = $argv;
$pdo = Database::conectar(configuracionPruebas());
$fecha = (string) valor($pdo, 'SELECT CURDATE()');

// Espera activa hasta el instante acordado para que ambos procesos arranquen a la vez.
while (microtime(true) < (float) $instante) {
    usleep(500);
}

try {
    $id = (new RegistrarVentaPresencial($pdo))->ejecutar((int) $vendedorId, [
        'cliente_id'  => '1',
        'motos'       => [['id' => $motoId, 'cantidad' => '1']],
        'metodo_pago' => 'contado',
    ], $fecha);
    echo "OK {$id}";
} catch (ErrorValidacion $error) {
    echo 'RECHAZADA ' . $error->getMessage();
}
