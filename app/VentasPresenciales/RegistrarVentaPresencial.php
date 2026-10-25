<?php
declare(strict_types=1);

namespace Motocons\VentasPresenciales;

use Motocons\Core\AccesoDenegado;
use Motocons\Core\ErrorValidacion;
use Motocons\Identidad\EmpleadoRepositorio;
use Motocons\Identidad\Roles;
use PDO;
use PDOException;
use Throwable;

/**
 * Caso de uso RF-02 / HU-05: un vendedor registra una venta asistida en la tienda física.
 *
 * Reglas que garantiza:
 *  R1  La venta queda asociada al vendedor autenticado (nunca a un id enviado por el formulario).
 *  R2  Solo un empleado con rol Vendedor puede registrarla.
 *  R3  La venta tiene al menos una moto o un producto, con cantidades enteras positivas.
 *  R4  No se vende más de lo que hay en inventario; el stock se descuenta en la misma operación.
 *  R5  Precios y descuentos se calculan en el servidor con los datos de la base de datos
 *      (precio de catálogo + mejor promoción vigente), nunca con valores del navegador.
 *  R6  La forma de pago es una de las del MR; Crédito Brilla exige el recibo de gas validado
 *      y ServiCrédito exige confirmar los requisitos (HU-06).
 *  R7  Todo se guarda en una única transacción: o se registran la venta, sus líneas, el pago
 *      y el descuento de inventario, o no se registra nada.
 */
final class RegistrarVentaPresencial
{
    public const MAX_CANTIDAD_POR_LINEA = 50;
    public const MAX_LONGITUD_SOPORTE   = 255;
    private const INTENTOS_ANTE_BLOQUEO = 3;

    private readonly CatalogoRepositorio $catalogo;
    private readonly VentaRepositorio $ventas;
    private readonly ClienteRepositorio $clientes;
    private readonly EmpleadoRepositorio $empleados;
    private readonly CalculadoraPrecios $calculadora;

    public function __construct(private readonly PDO $pdo)
    {
        $this->catalogo    = new CatalogoRepositorio($pdo);
        $this->ventas      = new VentaRepositorio($pdo);
        $this->clientes    = new ClienteRepositorio($pdo);
        $this->empleados   = new EmpleadoRepositorio($pdo);
        $this->calculadora = new CalculadoraPrecios();
    }

    /**
     * @param int    $vendedorId id del empleado autenticado (de la sesión)
     * @param array  $entrada    datos del formulario: cliente_id, motos[], productos[], metodo_pago,
     *                           soporte_recibo_gas, recibo_gas_validado, requisitos_servicredito_ok
     * @param string $fechaHoy   fecha de la venta (Y-m-d) para evaluar las promociones vigentes
     * @return int id de la venta registrada
     *
     * @throws ErrorValidacion si los datos o el inventario no permiten registrar la venta
     * @throws AccesoDenegado  si el empleado no es un vendedor
     */
    public function ejecutar(int $vendedorId, array $entrada, string $fechaHoy): int
    {
        [$solicitud, $errores] = $this->validarEntrada($entrada);
        if ($errores !== []) {
            // Para informar todos los problemas en un solo intento, también se revisan el cliente
            // y el inventario (lectura sin bloqueo: aquí no se va a guardar nada).
            [, , $erroresReferencias] = $this->cargarYVerificar($solicitud, false);
            throw new ErrorValidacion($errores + $erroresReferencias);
        }

        // Reintento ante interbloqueo (1213) o espera agotada (1205): MariaDB revierte la
        // transacción afectada y la operación puede repetirse de forma segura.
        for ($intento = 1; ; $intento++) {
            try {
                return $this->registrar($vendedorId, $solicitud, $fechaHoy);
            } catch (PDOException $error) {
                $this->revertir();
                $codigo = $error->errorInfo[1] ?? null;
                if (in_array($codigo, [1205, 1213], true) && $intento < self::INTENTOS_ANTE_BLOQUEO) {
                    continue;
                }
                throw $error;
            } catch (Throwable $error) {
                $this->revertir();
                throw $error;
            }
        }
    }

    /**
     * Validación de formato (no requiere base de datos). Reúne todos los errores
     * para mostrarlos juntos.
     *
     * @return array{0: array, 1: array<string,string>} [solicitud normalizada, errores]
     */
    private function validarEntrada(array $entrada): array
    {
        $errores = [];

        $clienteId = filter_var($entrada['cliente_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($clienteId === false) {
            $errores['cliente_id'] = 'Seleccione el cliente de la venta.';
        }

        $motos = $this->leerLineas($entrada['motos'] ?? [], 'moto', $errores);
        $productos = $this->leerLineas($entrada['productos'] ?? [], 'producto', $errores);
        if ($motos === [] && $productos === [] && !isset($errores['motos']) && !isset($errores['productos'])) {
            $errores['items'] = 'Agregue al menos una moto o un repuesto/insumo a la venta.';
        }

        $metodo = $entrada['metodo_pago'] ?? null;
        $soporte = null;
        $requisitosOk = false;
        if (!MetodoPago::esValido($metodo)) {
            $errores['metodo_pago'] = 'Seleccione una forma de pago válida.';
        } elseif ($metodo === MetodoPago::BRILLA) {
            $soporte = texto($entrada['soporte_recibo_gas'] ?? '');
            if ($soporte === '') {
                $errores['soporte_recibo_gas'] = 'Crédito Brilla: registre la referencia del recibo de gas presentado por el cliente.';
            } elseif (mb_strlen($soporte) > self::MAX_LONGITUD_SOPORTE) {
                $errores['soporte_recibo_gas'] = 'La referencia del recibo de gas no puede superar ' . self::MAX_LONGITUD_SOPORTE . ' caracteres.';
            }
            if (($entrada['recibo_gas_validado'] ?? '') !== '1') {
                $errores['recibo_gas_validado'] = 'Crédito Brilla: no se puede cerrar la venta sin confirmar que el recibo de gas fue validado.';
            }
        } elseif ($metodo === MetodoPago::SERVICREDITO) {
            $requisitosOk = ($entrada['requisitos_servicredito_ok'] ?? '') === '1';
            if (!$requisitosOk) {
                $errores['requisitos_servicredito_ok'] = 'ServiCrédito: no se puede cerrar la venta sin confirmar que el cliente cumple los requisitos.';
            }
        }

        $solicitud = [
            'cliente_id'    => $clienteId === false ? null : $clienteId,
            'motos'         => $motos,
            'productos'     => $productos,
            'metodo_pago'   => $metodo,
            'soporte'       => $soporte,
            'requisitos_ok' => $requisitosOk,
        ];
        return [$solicitud, $errores];
    }

    /**
     * Convierte las filas del formulario en [id => cantidad]. Ignora las filas vacías
     * y suma las cantidades si el mismo artículo aparece en varias filas.
     *
     * @return array<int,int>
     */
    private function leerLineas(mixed $filas, string $tipo, array &$errores): array
    {
        $campo = $tipo === 'moto' ? 'motos' : 'productos';
        if (!is_array($filas)) {
            $errores[$campo] = 'El listado de ' . $campo . ' no tiene un formato válido.';
            return [];
        }

        $lineas = [];
        foreach ($filas as $fila) {
            if (!is_array($fila) || texto($fila['id'] ?? '') === '') {
                continue; // fila sin artículo seleccionado
            }
            $id = filter_var($fila['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $cantidad = filter_var($fila['cantidad'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => self::MAX_CANTIDAD_POR_LINEA],
            ]);
            if ($id === false) {
                $errores[$campo] = "Hay una {$tipo} seleccionada que no es válida.";
                continue;
            }
            if ($cantidad === false) {
                $errores[$campo] = 'Cada cantidad debe ser un número entero entre 1 y ' . self::MAX_CANTIDAD_POR_LINEA . '.';
                continue;
            }
            $lineas[$id] = ($lineas[$id] ?? 0) + $cantidad;
        }
        ksort($lineas);
        return $lineas;
    }

    private function registrar(int $vendedorId, array $solicitud, string $fechaHoy): int
    {
        $this->pdo->beginTransaction();

        $vendedor = $this->empleados->buscarPorId($vendedorId);
        if ($vendedor === null || $vendedor['rol'] !== Roles::VENDEDOR) {
            throw new AccesoDenegado('Solo un empleado con rol Vendedor puede registrar ventas presenciales.');
        }

        // Bloqueo de las filas de inventario involucradas (ver CatalogoRepositorio::motosPorId).
        [$motos, $productos, $errores] = $this->cargarYVerificar($solicitud, true);
        if ($errores !== []) {
            throw new ErrorValidacion($errores); // ejecutar() revierte la transacción
        }

        // Cálculo en el servidor.
        $promociones = $this->catalogo->promocionesVigentes($fechaHoy);
        $lineasMotos = [];
        foreach ($solicitud['motos'] as $id => $cantidad) {
            $promocion = $this->calculadora->promocionAplicable($motos[$id], $promociones);
            $lineasMotos[] = $this->calculadora->lineaMoto($motos[$id], $cantidad, $promocion);
        }
        $lineasProductos = [];
        foreach ($solicitud['productos'] as $id => $cantidad) {
            $lineasProductos[] = $this->calculadora->lineaProducto($productos[$id], $cantidad);
        }
        $total = $this->calculadora->total(array_merge($lineasMotos, $lineasProductos));

        // Persistencia.
        $ventaId = $this->ventas->insertarVenta($solicitud['cliente_id'], $vendedorId, $total);
        foreach ($lineasMotos as $linea) {
            $this->ventas->insertarDetalleMoto($ventaId, $linea);
            $this->catalogo->descontarStockMoto($linea['moto_id'], $linea['cantidad']);
        }
        foreach ($lineasProductos as $linea) {
            $this->ventas->insertarDetalleProducto($ventaId, $linea);
            $this->catalogo->descontarStockProducto($linea['producto_id'], $linea['cantidad']);
        }
        $this->ventas->insertarPago(
            $ventaId,
            $solicitud['metodo_pago'],
            MetodoPago::estadoInicial($solicitud['metodo_pago']),
            $solicitud['soporte'],
            $solicitud['requisitos_ok']
        );

        $this->pdo->commit();
        return $ventaId;
    }

    /**
     * Verifica que el cliente y los artículos existan y que haya stock suficiente.
     *
     * @param bool $bloquear true dentro de la transacción de registro (SELECT ... FOR UPDATE)
     * @return array{0: array, 1: array, 2: array<string,string>} [motos, productos, errores]
     */
    private function cargarYVerificar(array $solicitud, bool $bloquear): array
    {
        $errores = [];
        if ($solicitud['cliente_id'] !== null && $this->clientes->buscarPorId($solicitud['cliente_id']) === null) {
            $errores['cliente_id'] = 'El cliente seleccionado no existe.';
        }

        $motos = $this->catalogo->motosPorId(array_keys($solicitud['motos']), $bloquear);
        $productos = $this->catalogo->productosPorId(array_keys($solicitud['productos']), $bloquear);

        foreach ($solicitud['motos'] as $id => $cantidad) {
            if (!isset($motos[$id])) {
                $errores["moto_{$id}"] = "La moto #{$id} no existe en el catálogo.";
            } elseif ((int) $motos[$id]['stock'] < $cantidad) {
                $m = $motos[$id];
                $errores["moto_{$id}"] = "Stock insuficiente para {$m['marca']} {$m['modelo']}: disponible {$m['stock']}, solicitado {$cantidad}.";
            }
        }
        foreach ($solicitud['productos'] as $id => $cantidad) {
            if (!isset($productos[$id])) {
                $errores["producto_{$id}"] = "El producto #{$id} no existe.";
            } elseif ((int) $productos[$id]['stock'] < $cantidad) {
                $p = $productos[$id];
                $errores["producto_{$id}"] = "Stock insuficiente para {$p['nombre']}: disponible {$p['stock']}, solicitado {$cantidad}.";
            }
        }
        return [$motos, $productos, $errores];
    }

    private function revertir(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
