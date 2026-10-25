<?php
declare(strict_types=1);

namespace Motocons\Core;

use InvalidArgumentException;

/**
 * Aritmética de dinero en centavos (enteros).
 *
 * Los montos DECIMAL(12,2) de la base de datos llegan como texto ("8990000.00").
 * Se convierten a centavos enteros para que los cálculos no tengan errores de
 * redondeo de punto flotante y se vuelven a texto decimal al guardarlos.
 */
final class Dinero
{
    /** "8990000.00" -> 899000000 */
    public static function aCentavos(string|int $valor): int
    {
        $texto = trim((string) $valor);
        if (!preg_match('/^(-?)(\d{1,13})(?:\.(\d{1,2}))?$/', $texto, $partes)) {
            throw new InvalidArgumentException("Monto inválido: {$texto}");
        }
        $centavos = (int) $partes[2] * 100 + (int) str_pad($partes[3] ?? '', 2, '0');
        return $partes[1] === '-' ? -$centavos : $centavos;
    }

    /** 899000000 -> "8990000.00" (formato que espera una columna DECIMAL). */
    public static function aDecimal(int $centavos): string
    {
        $signo = $centavos < 0 ? '-' : '';
        $absoluto = abs($centavos);
        return sprintf('%s%d.%02d', $signo, intdiv($absoluto, 100), $absoluto % 100);
    }

    /**
     * Porcentaje DECIMAL(5,2) expresado en puntos básicos: "12.50" -> 1250.
     * Es la misma conversión que de pesos a centavos.
     */
    public static function porcentajeEnPuntosBasicos(string|int $porcentaje): int
    {
        return self::aCentavos($porcentaje);
    }

    /** Calcula un porcentaje de un monto en centavos, redondeando al centavo más cercano. */
    public static function aplicarPorcentaje(int $centavos, int $puntosBasicos): int
    {
        if ($centavos < 0 || $puntosBasicos < 0) {
            throw new InvalidArgumentException('El monto y el porcentaje deben ser positivos.');
        }
        return intdiv($centavos * $puntosBasicos + 5000, 10000);
    }

    /** 899000000 -> "$ 8.990.000"; 12345 -> "$ 123,45". */
    public static function formatear(int $centavos): string
    {
        $decimales = $centavos % 100 === 0 ? 0 : 2;
        return '$ ' . number_format($centavos / 100, $decimales, ',', '.');
    }
}
