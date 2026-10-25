<?php
declare(strict_types=1);

namespace Motocons\VentasPresenciales;

/**
 * Formas de pago de una venta (transaccion_pago.metodo_pago) y sus reglas.
 *
 * Los valores son los definidos en el MR: contado, tarjeta, servicedrito, brilla.
 * Se conserva la grafía 'servicedrito' del documento para que los demás módulos
 * (RF-01, RF-03, RF-09) lean y escriban exactamente el mismo valor.
 */
final class MetodoPago
{
    public const CONTADO      = 'contado';
    public const TARJETA      = 'tarjeta';
    public const SERVICREDITO = 'servicedrito';
    public const BRILLA       = 'brilla';

    public const ETIQUETAS = [
        self::CONTADO      => 'Contado',
        self::TARJETA      => 'Tarjeta crédito / débito',
        self::SERVICREDITO => 'ServiCrédito',
        self::BRILLA       => 'Crédito Brilla (factura de gas)',
    ];

    public static function esValido(mixed $metodo): bool
    {
        return is_string($metodo) && array_key_exists($metodo, self::ETIQUETAS);
    }

    public static function etiqueta(string $metodo): string
    {
        return self::ETIQUETAS[$metodo] ?? $metodo;
    }

    /**
     * Estado con el que se registra el pago de una venta presencial cerrada.
     * Contado y tarjeta: el dinero ya se recibió en caja ("Pagado").
     * ServiCrédito y Brilla: la entidad aprobó el crédito, pero el desembolso a la
     * tienda llega después y lo concilia el módulo de pagos ("Aprobado").
     */
    public static function estadoInicial(string $metodo): string
    {
        return in_array($metodo, [self::CONTADO, self::TARJETA], true) ? 'Pagado' : 'Aprobado';
    }
}
