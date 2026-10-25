<?php
declare(strict_types=1);

namespace Motocons\Core;

/**
 * Renderiza una plantilla PHP de app/Vistas dentro del diseño general (layout.php).
 * Las plantillas solo presentan datos: no consultan la base de datos ni aplican reglas.
 */
final class Vista
{
    public static function render(string $plantilla, array $datos = [], int $estadoHttp = 200): void
    {
        http_response_code($estadoHttp);
        $contenido = self::capturar($plantilla, $datos);
        echo self::capturar('layout', $datos + ['contenido' => $contenido]);
    }

    private static function capturar(string $plantilla, array $datos): string
    {
        $archivo = MOTOCONS_RAIZ . '/app/Vistas/' . $plantilla . '.php';
        extract($datos, EXTR_SKIP);
        ob_start();
        try {
            require $archivo;
            return (string) ob_get_clean();
        } catch (\Throwable $error) {
            ob_end_clean();
            throw $error;
        }
    }
}
