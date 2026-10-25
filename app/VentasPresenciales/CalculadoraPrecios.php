<?php
declare(strict_types=1);

namespace Motocons\VentasPresenciales;

use Motocons\Core\Dinero;

/**
 * Cálculo de las líneas y el total de una venta. No accede a la base de datos,
 * por eso puede probarse de forma aislada.
 *
 * Convenciones (ver docs, decisión D6):
 * - Todos los montos se manejan en centavos (enteros).
 * - precio_unitario: precio de lista de una unidad.
 * - descuento_aplicado: descuento total de la línea (descuento por unidad × cantidad).
 * - precio_final: valor total de la línea (precio_unitario × cantidad − descuento_aplicado).
 * - Si varias promociones vigentes aplican a una moto, se usa la de mayor porcentaje;
 *   las promociones no se acumulan.
 * - Los repuestos e insumos no tienen promociones en el modelo (promocion solo referencia motos).
 */
final class CalculadoraPrecios
{
    /**
     * Mejor promoción aplicable a una moto entre las promociones vigentes.
     *
     * Una promoción aplica cuando cada criterio que define (moto_id, gama_aplicable,
     * tipo_propulsion_aplicable) coincide con la moto; un criterio NULL no restringe.
     *
     * @param array $moto                  fila de `moto`
     * @param array $promocionesVigentes   filas de `promocion` vigentes en la fecha de la venta
     * @return array|null la promoción elegida con la clave adicional 'puntos_basicos'
     */
    public function promocionAplicable(array $moto, array $promocionesVigentes): ?array
    {
        $mejor = null;
        foreach ($promocionesVigentes as $promocion) {
            if (!$this->aplica($promocion, $moto)) {
                continue;
            }
            $puntos = Dinero::porcentajeEnPuntosBasicos((string) $promocion['porcentaje_descuento']);
            if ($puntos <= 0 || $puntos > 10000) {
                continue; // porcentaje fuera de 0–100 %: configuración inválida, se ignora
            }
            $esMejor = $mejor === null
                || $puntos > $mejor['puntos_basicos']
                || ($puntos === $mejor['puntos_basicos'] && (int) $promocion['id'] < (int) $mejor['id']);
            if ($esMejor) {
                $mejor = $promocion + ['puntos_basicos' => $puntos];
            }
        }
        return $mejor;
    }

    private function aplica(array $promocion, array $moto): bool
    {
        if ($promocion['moto_id'] !== null && (int) $promocion['moto_id'] !== (int) $moto['id']) {
            return false;
        }
        if ($promocion['gama_aplicable'] !== null && $promocion['gama_aplicable'] !== $moto['gama']) {
            return false;
        }
        if ($promocion['tipo_propulsion_aplicable'] !== null
            && $promocion['tipo_propulsion_aplicable'] !== $moto['tipo_propulsion']) {
            return false;
        }
        return true;
    }

    public function lineaMoto(array $moto, int $cantidad, ?array $promocion): array
    {
        $precioUnitario = Dinero::aCentavos((string) $moto['precio']);
        $descuentoUnitario = $promocion === null
            ? 0
            : Dinero::aplicarPorcentaje($precioUnitario, $promocion['puntos_basicos']);
        $descuento = $descuentoUnitario * $cantidad;

        return [
            'moto_id'            => (int) $moto['id'],
            'cantidad'           => $cantidad,
            'precio_unitario'    => $precioUnitario,
            'descuento_aplicado' => $descuento,
            'precio_final'       => $precioUnitario * $cantidad - $descuento,
            'promocion'          => $promocion === null ? null : [
                'id'     => (int) $promocion['id'],
                'nombre' => $promocion['nombre'],
                'porcentaje' => $promocion['porcentaje_descuento'],
            ],
        ];
    }

    public function lineaProducto(array $producto, int $cantidad): array
    {
        $precioUnitario = Dinero::aCentavos((string) $producto['precio_unitario']);
        return [
            'producto_id'     => (int) $producto['id'],
            'cantidad'        => $cantidad,
            'precio_unitario' => $precioUnitario,
            'precio_final'    => $precioUnitario * $cantidad,
        ];
    }

    /** Total de la venta: suma de precio_final de todas las líneas (motos y productos). */
    public function total(array $lineas): int
    {
        return array_sum(array_column($lineas, 'precio_final'));
    }
}
