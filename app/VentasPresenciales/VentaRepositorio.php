<?php
declare(strict_types=1);

namespace Motocons\VentasPresenciales;

use Motocons\Core\Dinero;
use PDO;

/**
 * Acceso a datos de las ventas presenciales:
 * venta, detalle_venta, detalle_venta_producto y transaccion_pago.
 */
final class VentaRepositorio
{
    public const TIPO_FISICA = 'fisica';

    public function __construct(private readonly PDO $pdo)
    {
    }

    // ------------------------------------------------------------------
    // Escritura (se invocan dentro de la transacción de RegistrarVentaPresencial)
    // ------------------------------------------------------------------

    public function insertarVenta(int $clienteId, int $empleadoId, int $totalCentavos): int
    {
        $consulta = $this->pdo->prepare(
            'INSERT INTO venta (tipo_venta, cliente_id, empleado_id, total) VALUES (?, ?, ?, ?)'
        );
        $consulta->execute([self::TIPO_FISICA, $clienteId, $empleadoId, Dinero::aDecimal($totalCentavos)]);
        return (int) $this->pdo->lastInsertId();
    }

    public function insertarDetalleMoto(int $ventaId, array $linea): void
    {
        $consulta = $this->pdo->prepare(
            'INSERT INTO detalle_venta
                (venta_id, moto_id, cantidad, precio_unitario, descuento_aplicado, precio_final)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $consulta->execute([
            $ventaId,
            $linea['moto_id'],
            $linea['cantidad'],
            Dinero::aDecimal($linea['precio_unitario']),
            Dinero::aDecimal($linea['descuento_aplicado']),
            Dinero::aDecimal($linea['precio_final']),
        ]);
    }

    public function insertarDetalleProducto(int $ventaId, array $linea): void
    {
        $consulta = $this->pdo->prepare(
            'INSERT INTO detalle_venta_producto
                (venta_id, producto_id, cantidad, precio_unitario, precio_final)
             VALUES (?, ?, ?, ?, ?)'
        );
        $consulta->execute([
            $ventaId,
            $linea['producto_id'],
            $linea['cantidad'],
            Dinero::aDecimal($linea['precio_unitario']),
            Dinero::aDecimal($linea['precio_final']),
        ]);
    }

    public function insertarPago(
        int $ventaId,
        string $metodo,
        string $estado,
        ?string $soporteReciboGas,
        bool $requisitosServicreditoOk
    ): void {
        $consulta = $this->pdo->prepare(
            'INSERT INTO transaccion_pago
                (venta_id, metodo_pago, estado_pago, soporte_recibo_gas, requisitos_servicedrito_ok)
             VALUES (?, ?, ?, ?, ?)'
        );
        $consulta->execute([$ventaId, $metodo, $estado, $soporteReciboGas, $requisitosServicreditoOk ? 1 : 0]);
    }

    // ------------------------------------------------------------------
    // Consulta (HU-05: filtrar por moto, cliente y forma de pago)
    // ------------------------------------------------------------------

    /**
     * Ventas presenciales que cumplen los filtros, de la más reciente a la más antigua.
     *
     * @param array    $filtros     moto_id, cliente (texto), metodo_pago, empleado_id
     * @param int|null $soloEmpleado si se indica, restringe a las ventas de ese vendedor
     * @return array{filas: array, total: int}
     */
    public function buscar(array $filtros, ?int $soloEmpleado, int $pagina, int $porPagina): array
    {
        $condiciones = ['v.tipo_venta = ?'];
        $parametros = [self::TIPO_FISICA];

        if ($soloEmpleado !== null) {
            $condiciones[] = 'v.empleado_id = ?';
            $parametros[] = $soloEmpleado;
        } elseif (!empty($filtros['empleado_id'])) {
            $condiciones[] = 'v.empleado_id = ?';
            $parametros[] = $filtros['empleado_id'];
        }
        if (!empty($filtros['moto_id'])) {
            $condiciones[] = 'EXISTS (SELECT 1 FROM detalle_venta f WHERE f.venta_id = v.id AND f.moto_id = ?)';
            $parametros[] = $filtros['moto_id'];
        }
        if (($filtros['cliente'] ?? '') !== '') {
            // Los comodines que escriba el usuario (% y _) se tratan como texto literal.
            $patron = '%' . addcslashes($filtros['cliente'], '%_\\') . '%';
            $condiciones[] = '(c.nombre LIKE ? OR c.email LIKE ?)';
            array_push($parametros, $patron, $patron);
        }
        if (!empty($filtros['metodo_pago'])) {
            $condiciones[] = 'tp.metodo_pago = ?';
            $parametros[] = $filtros['metodo_pago'];
        }

        $desde = 'FROM venta v
                  JOIN cliente c ON c.id = v.cliente_id
                  LEFT JOIN empleado e ON e.id = v.empleado_id
                  LEFT JOIN transaccion_pago tp ON tp.venta_id = v.id
                 WHERE ' . implode(' AND ', $condiciones);

        $conteo = $this->pdo->prepare("SELECT COUNT(*) {$desde}");
        $conteo->execute($parametros);
        $total = (int) $conteo->fetchColumn();

        $consulta = $this->pdo->prepare(
            "SELECT v.id, v.fecha_venta, v.total, c.nombre AS cliente, c.email AS cliente_email,
                    e.nombre AS vendedor, tp.metodo_pago, tp.estado_pago,
                    (SELECT GROUP_CONCAT(CONCAT(m.marca, ' ', m.modelo, ' ×', dv.cantidad)
                                         ORDER BY dv.id SEPARATOR ', ')
                       FROM detalle_venta dv JOIN moto m ON m.id = dv.moto_id
                      WHERE dv.venta_id = v.id) AS motos,
                    (SELECT COALESCE(SUM(dvp.cantidad), 0)
                       FROM detalle_venta_producto dvp WHERE dvp.venta_id = v.id) AS unidades_producto
             {$desde}
             ORDER BY v.fecha_venta DESC, v.id DESC
             LIMIT ? OFFSET ?"
        );
        $posicion = 1;
        foreach ($parametros as $valor) {
            $consulta->bindValue($posicion++, $valor);
        }
        $consulta->bindValue($posicion++, $porPagina, PDO::PARAM_INT);
        $consulta->bindValue($posicion, ($pagina - 1) * $porPagina, PDO::PARAM_INT);
        $consulta->execute();

        return ['filas' => $consulta->fetchAll(), 'total' => $total];
    }

    /** Venta completa con sus líneas y su pago, o null si no existe. */
    public function obtener(int $id): ?array
    {
        $consulta = $this->pdo->prepare(
            'SELECT v.id, v.tipo_venta, v.cliente_id, v.empleado_id, v.fecha_venta, v.total,
                    c.nombre AS cliente, c.email AS cliente_email, c.telefono AS cliente_telefono,
                    c.direccion AS cliente_direccion,
                    e.nombre AS vendedor,
                    tp.metodo_pago, tp.estado_pago, tp.soporte_recibo_gas, tp.requisitos_servicedrito_ok
               FROM venta v
               JOIN cliente c ON c.id = v.cliente_id
               LEFT JOIN empleado e ON e.id = v.empleado_id
               LEFT JOIN transaccion_pago tp ON tp.venta_id = v.id
              WHERE v.id = ?'
        );
        $consulta->execute([$id]);
        $venta = $consulta->fetch();
        if (!$venta) {
            return null;
        }

        $motos = $this->pdo->prepare(
            'SELECT dv.moto_id, m.marca, m.modelo, m.gama, m.tipo_propulsion,
                    dv.cantidad, dv.precio_unitario, dv.descuento_aplicado, dv.precio_final
               FROM detalle_venta dv JOIN moto m ON m.id = dv.moto_id
              WHERE dv.venta_id = ? ORDER BY dv.id'
        );
        $motos->execute([$id]);
        $venta['motos'] = $motos->fetchAll();

        $productos = $this->pdo->prepare(
            'SELECT dvp.producto_id, p.nombre, p.tipo, dvp.cantidad, dvp.precio_unitario, dvp.precio_final
               FROM detalle_venta_producto dvp JOIN producto p ON p.id = dvp.producto_id
              WHERE dvp.venta_id = ? ORDER BY dvp.id'
        );
        $productos->execute([$id]);
        $venta['productos'] = $productos->fetchAll();

        return $venta;
    }
}
