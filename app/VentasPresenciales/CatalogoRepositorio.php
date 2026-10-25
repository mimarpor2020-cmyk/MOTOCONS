<?php
declare(strict_types=1);

namespace Motocons\VentasPresenciales;

use PDO;
use RuntimeException;

/**
 * Lectura del catálogo (motos, productos, promociones) y descuento de inventario.
 *
 * El catálogo y las promociones los administran otros módulos (RF-04, RF-07, RF-08);
 * RF-02 solo los consulta y descuenta el stock de lo vendido.
 */
final class CatalogoRepositorio
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function motos(): array
    {
        return $this->pdo->query(
            'SELECT id, marca, modelo, gama, tipo_propulsion, precio, stock
               FROM moto ORDER BY marca, modelo'
        )->fetchAll();
    }

    public function productos(): array
    {
        return $this->pdo->query(
            'SELECT id, nombre, tipo, precio_unitario, stock FROM producto ORDER BY nombre'
        )->fetchAll();
    }

    /** Promociones cuyo rango de fechas incluye la fecha indicada (Y-m-d). */
    public function promocionesVigentes(string $fecha): array
    {
        $consulta = $this->pdo->prepare(
            'SELECT id, nombre, porcentaje_descuento, gama_aplicable, tipo_propulsion_aplicable, moto_id
               FROM promocion
              WHERE fecha_inicio <= ? AND fecha_fin >= ?'
        );
        $consulta->execute([$fecha, $fecha]);
        return $consulta->fetchAll();
    }

    /**
     * Lee las motos indicadas. Con $bloquear = true (dentro de una transacción) usa
     * SELECT ... FOR UPDATE: las filas quedan BLOQUEADAS hasta el COMMIT o ROLLBACK y otra
     * venta que intente vender las mismas motos espera, así el stock leído no puede quedar
     * desactualizado. Se ordena por id para que todas las transacciones bloqueen en el
     * mismo orden y no se produzcan interbloqueos.
     *
     * @param int[] $ids
     * @return array<int,array> filas indexadas por id
     */
    public function motosPorId(array $ids, bool $bloquear): array
    {
        return $this->porId(
            'SELECT id, marca, modelo, gama, tipo_propulsion, precio, stock FROM moto',
            $ids,
            $bloquear
        );
    }

    /** @see motosPorId() */
    public function productosPorId(array $ids, bool $bloquear): array
    {
        return $this->porId('SELECT id, nombre, tipo, precio_unitario, stock FROM producto', $ids, $bloquear);
    }

    private function porId(string $select, array $ids, bool $bloquear): array
    {
        if ($ids === []) {
            return [];
        }
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $sql = "{$select} WHERE id IN ({$marcadores}) ORDER BY id" . ($bloquear ? ' FOR UPDATE' : '');
        $consulta = $this->pdo->prepare($sql);
        $consulta->execute(array_values($ids));

        $filas = [];
        foreach ($consulta->fetchAll() as $fila) {
            $filas[(int) $fila['id']] = $fila;
        }
        return $filas;
    }

    public function descontarStockMoto(int $id, int $cantidad): void
    {
        $this->descontar('moto', $id, $cantidad);
    }

    public function descontarStockProducto(int $id, int $cantidad): void
    {
        $this->descontar('producto', $id, $cantidad);
    }

    /**
     * La condición "stock >= ?" es una segunda barrera: aunque una validación previa
     * fallara, el UPDATE no deja el inventario en negativo.
     */
    private function descontar(string $tabla, int $id, int $cantidad): void
    {
        $consulta = $this->pdo->prepare(
            "UPDATE {$tabla} SET stock = stock - ? WHERE id = ? AND stock >= ?"
        );
        $consulta->execute([$cantidad, $id, $cantidad]);
        if ($consulta->rowCount() !== 1) {
            throw new RuntimeException("No fue posible descontar el inventario de {$tabla} #{$id}.");
        }
    }
}
