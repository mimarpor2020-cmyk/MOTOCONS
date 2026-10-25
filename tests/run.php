<?php
/**
 * Pruebas unitarias y de integración del módulo RF-02.
 *
 * Uso (con MySQL iniciado en XAMPP):
 *   C:\xampp\php\php.exe tests\run.php
 *
 * Crea y usa la base motocons_test; no modifica motocons_db.
 */
declare(strict_types=1);

use Motocons\Core\AccesoDenegado;
use Motocons\Core\Dinero;
use Motocons\Core\ErrorValidacion;
use Motocons\VentasPresenciales\CalculadoraPrecios;
use Motocons\VentasPresenciales\RegistrarCliente;
use Motocons\VentasPresenciales\RegistrarVentaPresencial;
use Motocons\VentasPresenciales\VentaRepositorio;

require __DIR__ . '/lib.php';

const VENDEDOR_CARLOS = 2;
const VENDEDOR_ANA = 3;
const ADMINISTRADOR = 1;
const MECANICO = 4;

// =====================================================================
seccion('Unitarias: aritmética de dinero (Dinero)');
// =====================================================================

prueba('Convierte DECIMAL de la base a centavos y de vuelta', function () {
    afirmarIgual(899000000, Dinero::aCentavos('8990000.00'));
    afirmarIgual(1050, Dinero::aCentavos('10.5'));
    afirmarIgual(7, Dinero::aCentavos('0.07'));
    afirmarIgual('8990000.00', Dinero::aDecimal(899000000));
    afirmarIgual('0.07', Dinero::aDecimal(7));
});

prueba('Rechaza montos con formato inválido', function () {
    afirmarExcepcion(InvalidArgumentException::class, fn () => Dinero::aCentavos('12,50'));
    afirmarExcepcion(InvalidArgumentException::class, fn () => Dinero::aCentavos('1e5'));
    afirmarExcepcion(InvalidArgumentException::class, fn () => Dinero::aCentavos('10.123'));
});

prueba('Aplica porcentajes con redondeo al centavo más cercano', function () {
    afirmarIgual(128900000, Dinero::aplicarPorcentaje(1289000000, 1000), '10 % de 12.890.000:');
    afirmarIgual(26450000, Dinero::aplicarPorcentaje(529000000, 500), '5 % de 5.290.000:');
    afirmarIgual(42, Dinero::aplicarPorcentaje(333, 1250), '12,5 % de 3,33 = 0,41625 -> 0,42:');
    afirmarIgual(41, Dinero::aplicarPorcentaje(331, 1250), '12,5 % de 3,31 = 0,41375 -> 0,41:');
});

prueba('Formatea pesos colombianos', function () {
    afirmarIgual('$ 8.990.000', Dinero::formatear(899000000));
    afirmarIgual('$ 1.234,50', Dinero::formatear(123450));
});

// =====================================================================
seccion('Unitarias: promociones y cálculo de líneas (CalculadoraPrecios)');
// =====================================================================

$calc = new CalculadoraPrecios();
$motoMediaElectrica = ['id' => 6, 'gama' => 'Media', 'tipo_propulsion' => 'Electrica', 'precio' => '17990000.00'];
$promo = static fn (int $id, string $pct, ?string $gama, ?string $tipo, ?int $moto): array => [
    'id' => $id, 'nombre' => "P{$id}", 'porcentaje_descuento' => $pct,
    'gama_aplicable' => $gama, 'tipo_propulsion_aplicable' => $tipo, 'moto_id' => $moto,
];

prueba('Elige la promoción aplicable de mayor porcentaje (no acumula)', function () use ($calc, $motoMediaElectrica, $promo) {
    $elegida = $calc->promocionAplicable($motoMediaElectrica, [
        $promo(1, '10.00', 'Media', null, null),
        $promo(2, '12.50', null, 'Electrica', null),
        $promo(3, '30.00', 'Alta', null, null),      // otra gama: no aplica
        $promo(4, '40.00', null, null, 99),          // otra moto: no aplica
    ]);
    afirmarIgual(2, (int) $elegida['id']);
    afirmarIgual(1250, $elegida['puntos_basicos']);
});

prueba('Exige que coincidan todos los criterios definidos de la promoción', function () use ($calc, $motoMediaElectrica, $promo) {
    afirmarIgual(null, $calc->promocionAplicable($motoMediaElectrica, [$promo(1, '10.00', 'Media', 'Combustible', null)]));
    afirmarIgual(5, (int) $calc->promocionAplicable($motoMediaElectrica, [$promo(5, '8.00', 'Media', 'Electrica', 6)])['id']);
});

prueba('Ignora promociones con porcentaje fuera de 0–100 %', function () use ($calc, $motoMediaElectrica, $promo) {
    afirmarIgual(null, $calc->promocionAplicable($motoMediaElectrica, [$promo(1, '0.00', null, null, null), $promo(2, '150.00', null, null, null)]));
});

prueba('Calcula precio unitario, descuento de la línea y precio final', function () use ($calc, $motoMediaElectrica, $promo) {
    $p = $calc->promocionAplicable($motoMediaElectrica, [$promo(2, '12.50', null, 'Electrica', null)]);
    $linea = $calc->lineaMoto($motoMediaElectrica, 2, $p);
    afirmarIgual(1799000000, $linea['precio_unitario']);
    afirmarIgual(449750000, $linea['descuento_aplicado'], '2 × 2.248.750:');
    afirmarIgual(3148250000, $linea['precio_final'], '2 × 17.990.000 − 4.497.500:');
    $sinPromo = $calc->lineaMoto($motoMediaElectrica, 1, null);
    afirmarIgual(0, $sinPromo['descuento_aplicado']);
    afirmarIgual(1799000000, $sinPromo['precio_final']);
});

// =====================================================================
seccion('Integración: registro de ventas presenciales (base motocons_test)');
// =====================================================================

$pdo = reiniciarBaseDePruebas();
$hoy = (string) valor($pdo, 'SELECT CURDATE()');
$servicio = new RegistrarVentaPresencial($pdo);
$registrar = static fn (int $vendedor, array $entrada): int => $servicio->ejecutar($vendedor, $entrada, $hoy);
$stock = static fn (string $tabla, int $id): int => (int) valor($pdo, "SELECT stock FROM {$tabla} WHERE id = ?", [$id]);
$conteoVentas = static fn (): int => (int) valor($pdo, 'SELECT COUNT(*) FROM venta');

prueba('Registra venta de contado asociada al vendedor, con promoción y descuento de stock', function () use ($pdo, $registrar, $stock) {
    $id = $registrar(VENDEDOR_CARLOS, [
        'cliente_id' => '1',
        'motos' => [['id' => '2', 'cantidad' => '1']],   // Pulsar NS 200: promociones de 10 % y 7 % -> 10 %
        'metodo_pago' => 'contado',
    ]);
    $venta = $pdo->query("SELECT * FROM venta WHERE id = {$id}")->fetch();
    afirmarIgual('fisica', $venta['tipo_venta']);
    afirmarIgual(VENDEDOR_CARLOS, $venta['empleado_id'], 'Vendedor asociado:');
    afirmarIgual(1, $venta['cliente_id']);
    afirmarIgual('10341000.00', $venta['total']);

    $detalle = $pdo->query("SELECT * FROM detalle_venta WHERE venta_id = {$id}")->fetch();
    afirmarIgual('11490000.00', $detalle['precio_unitario']);
    afirmarIgual('1149000.00', $detalle['descuento_aplicado']);
    afirmarIgual('10341000.00', $detalle['precio_final']);
    afirmarIgual(4, $stock('moto', 2), 'Stock de la Pulsar:');

    $pago = $pdo->query("SELECT * FROM transaccion_pago WHERE venta_id = {$id}")->fetch();
    afirmarIgual('contado', $pago['metodo_pago']);
    afirmarIgual('Pagado', $pago['estado_pago']);
    afirmarIgual(null, $pago['soporte_recibo_gas']);
    afirmarIgual(0, $pago['requisitos_servicedrito_ok']);
});

prueba('Registra venta mixta (moto + repuestos/insumos) y el total es la suma de las líneas', function () use ($pdo, $registrar, $stock) {
    $id = $registrar(VENDEDOR_CARLOS, [
        'cliente_id' => '2',
        'motos' => [['id' => '1', 'cantidad' => '2']],   // NKD 125: 5 % -> 2 × 5.025.500 = 10.051.000
        'productos' => [['id' => '1', 'cantidad' => '3'], ['id' => '2', 'cantidad' => '1']], // 114.000 + 25.000
        'metodo_pago' => 'tarjeta',
    ]);
    afirmarIgual('10190000.00', valor($pdo, 'SELECT total FROM venta WHERE id = ?', [$id]));
    afirmarIgual('529000.00', valor($pdo, 'SELECT descuento_aplicado FROM detalle_venta WHERE venta_id = ?', [$id]));
    $sumaLineas = valor($pdo, 'SELECT (SELECT SUM(precio_final) FROM detalle_venta WHERE venta_id = ?)
                                    + (SELECT SUM(precio_final) FROM detalle_venta_producto WHERE venta_id = ?)', [$id, $id]);
    afirmarIgual('10190000.00', $sumaLineas, 'Suma de líneas = total:');
    afirmarIgual(6, $stock('moto', 1));
    afirmarIgual(37, $stock('producto', 1));
    afirmarIgual(24, $stock('producto', 2));
    afirmarIgual('Pagado', valor($pdo, 'SELECT estado_pago FROM transaccion_pago WHERE venta_id = ?', [$id]));
});

prueba('No aplica una promoción vencida', function () use ($pdo, $registrar) {
    $id = $registrar(VENDEDOR_CARLOS, ['cliente_id' => '1', 'motos' => [['id' => '3', 'cantidad' => '1']], 'metodo_pago' => 'contado']);
    afirmarIgual('0.00', valor($pdo, 'SELECT descuento_aplicado FROM detalle_venta WHERE venta_id = ?', [$id]));
    afirmarIgual('26990000.00', valor($pdo, 'SELECT total FROM venta WHERE id = ?', [$id]));
});

prueba('Agrupa en una sola línea el mismo artículo repetido en el formulario', function () use ($pdo, $registrar) {
    $id = $registrar(VENDEDOR_CARLOS, [
        'cliente_id' => '1',
        'motos' => [['id' => '1', 'cantidad' => '1'], ['id' => '', 'cantidad' => '1'], ['id' => '1', 'cantidad' => '1']],
        'metodo_pago' => 'contado',
    ]);
    afirmarIgual(1, (int) valor($pdo, 'SELECT COUNT(*) FROM detalle_venta WHERE venta_id = ?', [$id]));
    afirmarIgual(2, (int) valor($pdo, 'SELECT cantidad FROM detalle_venta WHERE venta_id = ?', [$id]));
});

prueba('Ignora un empleado_id enviado en el formulario: la venta es del vendedor autenticado', function () use ($pdo, $registrar) {
    $id = $registrar(VENDEDOR_ANA, [
        'cliente_id' => '1', 'empleado_id' => (string) VENDEDOR_CARLOS,
        'motos' => [['id' => '1', 'cantidad' => '1']], 'metodo_pago' => 'contado',
    ]);
    afirmarIgual(VENDEDOR_ANA, valor($pdo, 'SELECT empleado_id FROM venta WHERE id = ?', [$id]));
});

prueba('Ignora precios enviados por el navegador: usa el precio del catálogo', function () use ($pdo, $registrar) {
    $id = $registrar(VENDEDOR_CARLOS, [
        'cliente_id' => '1', 'total' => '1.00',
        'motos' => [['id' => '3', 'cantidad' => '1', 'precio' => '1.00']], 'metodo_pago' => 'contado',
    ]);
    afirmarIgual('26990000.00', valor($pdo, 'SELECT total FROM venta WHERE id = ?', [$id]));
});

prueba('Crédito Brilla: exige referencia del recibo de gas y confirmación de validación', function () use ($pdo, $registrar, $conteoVentas) {
    $antes = $conteoVentas();
    $base = ['cliente_id' => '2', 'motos' => [['id' => '4', 'cantidad' => '1']], 'metodo_pago' => 'brilla'];
    $e = afirmarExcepcion(ErrorValidacion::class, fn () => $registrar(VENDEDOR_CARLOS, $base));
    afirmar(isset($e->errores()['soporte_recibo_gas'], $e->errores()['recibo_gas_validado']), 'Debe reportar ambos requisitos.');
    afirmarExcepcion(ErrorValidacion::class, fn () => $registrar(VENDEDOR_CARLOS, $base + ['soporte_recibo_gas' => 'FAC-123']));
    afirmarExcepcion(ErrorValidacion::class, fn () => $registrar(VENDEDOR_CARLOS, $base + ['soporte_recibo_gas' => '   ', 'recibo_gas_validado' => '1']));
    afirmarIgual($antes, $conteoVentas(), 'No debe registrarse ninguna venta:');

    $id = $registrar(VENDEDOR_CARLOS, $base + ['soporte_recibo_gas' => ' FAC-2026-778812 ', 'recibo_gas_validado' => '1']);
    $pago = $pdo->query("SELECT * FROM transaccion_pago WHERE venta_id = {$id}")->fetch();
    afirmarIgual('brilla', $pago['metodo_pago']);
    afirmarIgual('Aprobado', $pago['estado_pago']);
    afirmarIgual('FAC-2026-778812', $pago['soporte_recibo_gas']);
});

prueba('ServiCrédito: exige confirmar los requisitos; se guarda con el valor del MR', function () use ($pdo, $registrar) {
    $base = ['cliente_id' => '3', 'motos' => [['id' => '5', 'cantidad' => '1']], 'metodo_pago' => 'servicedrito'];
    $e = afirmarExcepcion(ErrorValidacion::class, fn () => $registrar(VENDEDOR_ANA, $base));
    afirmar(isset($e->errores()['requisitos_servicredito_ok']), 'Debe reportar los requisitos pendientes.');

    $id = $registrar(VENDEDOR_ANA, $base + ['requisitos_servicredito_ok' => '1', 'soporte_recibo_gas' => 'no-aplica']);
    $pago = $pdo->query("SELECT * FROM transaccion_pago WHERE venta_id = {$id}")->fetch();
    afirmarIgual('servicedrito', $pago['metodo_pago']);
    afirmarIgual(1, $pago['requisitos_servicedrito_ok']);
    afirmarIgual(null, $pago['soporte_recibo_gas'], 'El soporte de gas solo se guarda en Brilla:');
    afirmarIgual('5678750.00', valor($pdo, 'SELECT total FROM venta WHERE id = ?', [$id]), 'Starker Fly con 12,5 %:');
});

prueba('Rechaza forma de pago inexistente, venta sin artículos y cantidades inválidas', function () use ($registrar, $conteoVentas) {
    $antes = $conteoVentas();
    $moto = [['id' => '1', 'cantidad' => '1']];
    afirmar(isset(afirmarExcepcion(ErrorValidacion::class, fn () => $registrar(VENDEDOR_CARLOS, ['cliente_id' => '1', 'motos' => $moto, 'metodo_pago' => 'bitcoin']))->errores()['metodo_pago']), 'metodo_pago');
    afirmar(isset(afirmarExcepcion(ErrorValidacion::class, fn () => $registrar(VENDEDOR_CARLOS, ['cliente_id' => '1', 'motos' => [['id' => '', 'cantidad' => '1']], 'metodo_pago' => 'contado']))->errores()['items']), 'items');
    foreach (['0', '-1', 'abc', '1.5', '51'] as $cantidad) {
        $e = afirmarExcepcion(ErrorValidacion::class, fn () => $registrar(VENDEDOR_CARLOS, ['cliente_id' => '1', 'motos' => [['id' => '1', 'cantidad' => $cantidad]], 'metodo_pago' => 'contado']));
        afirmar(isset($e->errores()['motos']), "cantidad {$cantidad}");
    }
    afirmarExcepcion(ErrorValidacion::class, fn () => $registrar(VENDEDOR_CARLOS, ['cliente_id' => '1', 'motos' => 'no-es-lista', 'metodo_pago' => 'contado']));
    afirmarIgual($antes, $conteoVentas());
});

prueba('Rechaza cliente inexistente y moto inexistente', function () use ($registrar) {
    $e = afirmarExcepcion(ErrorValidacion::class, fn () => $registrar(VENDEDOR_CARLOS, ['cliente_id' => '999', 'motos' => [['id' => '1', 'cantidad' => '1']], 'metodo_pago' => 'contado']));
    afirmar(isset($e->errores()['cliente_id']), 'cliente_id');
    $e = afirmarExcepcion(ErrorValidacion::class, fn () => $registrar(VENDEDOR_CARLOS, ['cliente_id' => '1', 'motos' => [['id' => '999', 'cantidad' => '1']], 'metodo_pago' => 'contado']));
    afirmar(isset($e->errores()['moto_999']), 'moto_999');
});

prueba('Stock insuficiente: rechaza la venta completa y no modifica nada (atomicidad)', function () use ($pdo, $registrar, $stock, $conteoVentas) {
    $antes = ['ventas' => $conteoVentas(), 'moto2' => $stock('moto', 2), 'liquido' => $stock('producto', 5)];
    $e = afirmarExcepcion(ErrorValidacion::class, fn () => $registrar(VENDEDOR_CARLOS, [
        'cliente_id' => '1',
        'motos' => [['id' => '2', 'cantidad' => '1']],          // disponible
        'productos' => [['id' => '5', 'cantidad' => '3']],      // líquido de frenos: stock 2
        'metodo_pago' => 'contado',
    ]));
    afirmar(str_contains($e->getMessage(), 'disponible 2, solicitado 3'), 'Mensaje: ' . $e->getMessage());
    afirmarIgual($antes, ['ventas' => $conteoVentas(), 'moto2' => $stock('moto', 2), 'liquido' => $stock('producto', 5)]);
    afirmar(!$pdo->inTransaction(), 'La transacción debe quedar cerrada.');
});

prueba('Informa en un solo intento los errores de formato, de cliente y de inventario', function () use ($registrar) {
    $e = afirmarExcepcion(ErrorValidacion::class, fn () => $registrar(VENDEDOR_CARLOS, [
        'cliente_id' => '999',
        'productos' => [['id' => '5', 'cantidad' => '3']],
        'metodo_pago' => 'brilla',
    ]));
    afirmarIgual(['soporte_recibo_gas', 'recibo_gas_validado', 'cliente_id', 'producto_5'], array_keys($e->errores()));
});

prueba('Moto agotada (stock 0) no se puede vender', function () use ($registrar) {
    $e = afirmarExcepcion(ErrorValidacion::class, fn () => $registrar(VENDEDOR_CARLOS, ['cliente_id' => '1', 'motos' => [['id' => '7', 'cantidad' => '1']], 'metodo_pago' => 'contado']));
    afirmar(isset($e->errores()['moto_7']), 'moto_7');
});

prueba('Solo un empleado con rol Vendedor puede registrar (Administrador, Mecánico e inexistente no)', function () use ($registrar, $conteoVentas) {
    $antes = $conteoVentas();
    $entrada = ['cliente_id' => '1', 'motos' => [['id' => '1', 'cantidad' => '1']], 'metodo_pago' => 'contado'];
    foreach ([ADMINISTRADOR, MECANICO, 999] as $empleado) {
        afirmarExcepcion(AccesoDenegado::class, fn () => $registrar($empleado, $entrada));
    }
    afirmarIgual($antes, $conteoVentas());
});

// =====================================================================
seccion('Integración: restricciones en la base de datos (02_ajustes_rf02.sql)');
// =====================================================================

prueba('La base rechaza una venta física sin vendedor', function () use ($pdo) {
    $e = afirmarExcepcion(PDOException::class, fn () => $pdo->exec("INSERT INTO venta (tipo_venta, cliente_id, empleado_id, total) VALUES ('fisica', 1, NULL, 100)"));
    afirmar(str_contains($e->getMessage(), 'chk_venta_fisica_con_vendedor'), $e->getMessage());
});

prueba('La base rechaza un pago Brilla sin soporte y un método de pago desconocido', function () use ($pdo) {
    $pdo->exec("INSERT INTO venta (tipo_venta, cliente_id, empleado_id, total) VALUES ('fisica', 1, 2, 100)");
    $venta = (int) $pdo->lastInsertId();
    afirmarExcepcion(PDOException::class, fn () => $pdo->exec("INSERT INTO transaccion_pago (venta_id, metodo_pago, estado_pago, soporte_recibo_gas, requisitos_servicedrito_ok) VALUES ({$venta}, 'brilla', 'Aprobado', NULL, 0)"));
    afirmarExcepcion(PDOException::class, fn () => $pdo->exec("INSERT INTO transaccion_pago (venta_id, metodo_pago, estado_pago, soporte_recibo_gas, requisitos_servicedrito_ok) VALUES ({$venta}, 'efectivo', 'Pagado', NULL, 0)"));
    $pdo->exec("DELETE FROM venta WHERE id = {$venta}");
});

prueba('La base impide stock negativo y cantidades no positivas', function () use ($pdo) {
    afirmarExcepcion(PDOException::class, fn () => $pdo->exec('UPDATE moto SET stock = -1 WHERE id = 1'));
    afirmarExcepcion(PDOException::class, fn () => $pdo->exec('INSERT INTO detalle_venta (venta_id, moto_id, cantidad, precio_unitario, descuento_aplicado, precio_final) SELECT MIN(id), 1, 0, 1, 0, 1 FROM venta'));
});

prueba('La fecha de la venta no cambia al actualizar la fila (sin ON UPDATE)', function () use ($pdo) {
    $id = (int) valor($pdo, 'SELECT MIN(id) FROM venta');
    $pdo->exec("UPDATE venta SET fecha_venta = '2026-01-15 10:00:00' WHERE id = {$id}");
    $pdo->exec("UPDATE venta SET total = total + 1 WHERE id = {$id}");
    afirmarIgual('2026-01-15 10:00:00', valor($pdo, 'SELECT fecha_venta FROM venta WHERE id = ?', [$id]));
    $pdo->exec("UPDATE venta SET total = total - 1 WHERE id = {$id}");
});

// =====================================================================
seccion('Integración: concurrencia (dos vendedores, una sola unidad)');
// =====================================================================

prueba('Dos ventas simultáneas de la última unidad: solo una se registra y el stock queda en 0', function () use ($pdo, $stock) {
    afirmarIgual(1, $stock('moto', 6), 'Precondición, stock de la Super Soco:');
    $instante = sprintf('%.3f', microtime(true) + 1.5);
    $procesos = [];
    foreach ([VENDEDOR_CARLOS, VENDEDOR_ANA] as $vendedor) {
        $comando = [PHP_BINARY, __DIR__ . '/worker_compra.php', (string) $vendedor, '6', $instante];
        $proceso = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias);
        $procesos[] = [$proceso, $tuberias];
    }
    $salidas = [];
    foreach ($procesos as [$proceso, $tuberias]) {
        $salidas[] = trim(stream_get_contents($tuberias[1]) . stream_get_contents($tuberias[2]));
        proc_close($proceso);
    }
    $exitosas = array_filter($salidas, static fn ($s) => str_starts_with($s, 'OK '));
    $rechazadas = array_filter($salidas, static fn ($s) => str_starts_with($s, 'RECHAZADA ') && str_contains($s, 'Stock insuficiente'));
    afirmarIgual(1, count($exitosas), 'Ventas exitosas (' . implode(' | ', $salidas) . '):');
    afirmarIgual(1, count($rechazadas), 'Ventas rechazadas por stock:');
    afirmarIgual(0, $stock('moto', 6), 'Stock final:');
    afirmarIgual(1, (int) valor($pdo, 'SELECT COALESCE(SUM(cantidad),0) FROM detalle_venta WHERE moto_id = 6'), 'Unidades vendidas:');
});

prueba('Bloqueo de fila: una venta espera a la otra y valida el stock ya actualizado (determinista)', function () use ($pdo, $stock) {
    $pdo->exec('UPDATE moto SET stock = 1 WHERE id = 6');
    // 1) Esta conexión simula una venta en curso: bloquea la fila de la moto.
    $pdo->beginTransaction();
    $pdo->query('SELECT stock FROM moto WHERE id = 6 FOR UPDATE')->fetch();
    // 2) Otro vendedor intenta vender la misma unidad en paralelo: debe quedar esperando el bloqueo.
    $comando = [PHP_BINARY, __DIR__ . '/worker_compra.php', (string) VENDEDOR_ANA, '6', '0'];
    $proceso = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias);
    usleep(1_500_000);
    afirmar(proc_get_status($proceso)['running'], 'El segundo proceso debería estar esperando el bloqueo.');
    // 3) La primera venta vende la unidad y confirma.
    $pdo->exec('UPDATE moto SET stock = 0 WHERE id = 6');
    $pdo->commit();
    // 4) El segundo proceso continúa, lee el stock actualizado (0) y rechaza la venta con un mensaje claro.
    $salida = trim(stream_get_contents($tuberias[1]) . stream_get_contents($tuberias[2]));
    proc_close($proceso);
    afirmar(str_starts_with($salida, 'RECHAZADA') && str_contains($salida, 'disponible 0'), "Salida del segundo proceso: {$salida}");
    afirmarIgual(0, $stock('moto', 6));
});

// =====================================================================
seccion('Integración: consulta y filtros (HU-05)');
// =====================================================================

$pdo = reiniciarBaseDePruebas();
$servicio = new RegistrarVentaPresencial($pdo);
$repo = new VentaRepositorio($pdo);
$v1 = $servicio->ejecutar(VENDEDOR_CARLOS, ['cliente_id' => '1', 'motos' => [['id' => '1', 'cantidad' => '1']], 'metodo_pago' => 'contado'], $hoy);
$v2 = $servicio->ejecutar(VENDEDOR_CARLOS, ['cliente_id' => '2', 'motos' => [['id' => '2', 'cantidad' => '1']], 'metodo_pago' => 'brilla', 'soporte_recibo_gas' => 'FAC-1', 'recibo_gas_validado' => '1'], $hoy);
$v3 = $servicio->ejecutar(VENDEDOR_ANA, ['cliente_id' => '3', 'motos' => [['id' => '4', 'cantidad' => '1']], 'metodo_pago' => 'servicedrito', 'requisitos_servicredito_ok' => '1'], $hoy);
$v4 = $servicio->ejecutar(VENDEDOR_ANA, ['cliente_id' => '1', 'productos' => [['id' => '3', 'cantidad' => '1']], 'metodo_pago' => 'tarjeta'], $hoy);
$ids = static fn (array $r): array => array_map(static fn ($f) => (int) $f['id'], $r['filas']);
$sinFiltros = ['moto_id' => null, 'cliente' => '', 'metodo_pago' => null, 'empleado_id' => null];

prueba('Filtra por moto', function () use ($repo, $ids, $sinFiltros, $v1) {
    afirmarIgual([$v1], $ids($repo->buscar(['moto_id' => 1] + $sinFiltros, null, 1, 20)));
});

prueba('Filtra por cliente (nombre o correo, sin distinguir tildes ni mayúsculas)', function () use ($repo, $ids, $sinFiltros, $v2, $v3) {
    afirmarIgual([$v2], $ids($repo->buscar(['cliente' => 'ANDRES felipe'] + $sinFiltros, null, 1, 20)));
    afirmarIgual([$v3], $ids($repo->buscar(['cliente' => 'luisa.martinez@'] + $sinFiltros, null, 1, 20)));
    afirmarIgual([], $ids($repo->buscar(['cliente' => '%'] + $sinFiltros, null, 1, 20)), 'El comodín % se trata como texto:');
});

prueba('Filtra por forma de pago', function () use ($repo, $ids, $sinFiltros, $v2, $v4) {
    afirmarIgual([$v2], $ids($repo->buscar(['metodo_pago' => 'brilla'] + $sinFiltros, null, 1, 20)));
    afirmarIgual([$v4], $ids($repo->buscar(['metodo_pago' => 'tarjeta'] + $sinFiltros, null, 1, 20)));
});

prueba('Un vendedor solo obtiene sus ventas, aun combinando filtros', function () use ($repo, $ids, $sinFiltros, $v1, $v2, $v3, $v4) {
    afirmarIgual([$v2, $v1], $ids($repo->buscar($sinFiltros, VENDEDOR_CARLOS, 1, 20)));
    afirmarIgual([$v4, $v3], $ids($repo->buscar($sinFiltros, VENDEDOR_ANA, 1, 20)));
    afirmarIgual([], $ids($repo->buscar(['moto_id' => 1] + $sinFiltros, VENDEDOR_ANA, 1, 20)));
    afirmarIgual([$v4, $v3], $ids($repo->buscar(['empleado_id' => VENDEDOR_ANA] + $sinFiltros, null, 1, 20)), 'Filtro por vendedor (administrador):');
});

prueba('Pagina los resultados y cuenta el total', function () use ($repo, $sinFiltros, $v3) {
    $r = $repo->buscar($sinFiltros, null, 2, 1);
    afirmarIgual(4, $r['total']);
    afirmarIgual(1, count($r['filas']));
    afirmarIgual($v3, (int) $r['filas'][0]['id']);
});

prueba('Obtiene el detalle completo de una venta', function () use ($repo, $v2) {
    $venta = $repo->obtener($v2);
    afirmarIgual('Carlos Pérez', $venta['vendedor']);
    afirmarIgual('brilla', $venta['metodo_pago']);
    afirmarIgual(1, count($venta['motos']));
    afirmarIgual('1149000.00', $venta['motos'][0]['descuento_aplicado']);
    afirmarIgual(null, $repo->obtener(99999));
});

// =====================================================================
seccion('Integración: registro rápido de cliente');
// =====================================================================

prueba('Registra un cliente válido con contraseña aleatoria cifrada', function () use ($pdo) {
    $id = (new RegistrarCliente($pdo))->ejecutar(['nombre' => 'Pedro Gómez', 'email' => ' Pedro@Correo.test ', 'telefono' => '300 111 2233']);
    $c = $pdo->query("SELECT * FROM cliente WHERE id = {$id}")->fetch();
    afirmarIgual('pedro@correo.test', $c['email']);
    afirmar(str_starts_with($c['password'], '$2y$'), 'La contraseña debe guardarse como hash bcrypt.');
    afirmarIgual(null, $c['direccion']);
});

prueba('Rechaza correo duplicado, correo inválido, nombre vacío y teléfono inválido', function () use ($pdo) {
    $servicio = new RegistrarCliente($pdo);
    afirmar(isset(afirmarExcepcion(ErrorValidacion::class, fn () => $servicio->ejecutar(['nombre' => 'X', 'email' => 'maria.lopez@correo.test']))->errores()['email']), 'duplicado');
    $e = afirmarExcepcion(ErrorValidacion::class, fn () => $servicio->ejecutar(['nombre' => '', 'email' => 'no-es-correo', 'telefono' => 'abc']));
    afirmarIgual(['nombre', 'email', 'telefono'], array_keys($e->errores()));
});

$servidor = Motocons\Core\Database::conectar($GLOBALS['config']['db'], false);
$servidor->exec('DROP DATABASE IF EXISTS `' . BD_PRUEBAS . '`');

resumenYSalir();
