<?php
/**
 * Funciones de ayuda globales usadas por controladores y vistas.
 */
declare(strict_types=1);

use Motocons\Core\Dinero;

/** Escapa un valor para imprimirlo en HTML (previene XSS). */
function e(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Valor de entrada (GET/POST) como texto sin espacios extremos. Si el cliente envía
 * un arreglo u otro tipo donde se espera texto (petición manipulada), devuelve ''.
 */
function texto(mixed $valor): string
{
    return is_string($valor) || is_int($valor) ? trim((string) $valor) : '';
}

/** Ruta base pública de la aplicación, por ejemplo "/motocons/public". */
function ruta_base(): string
{
    $base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return rtrim($base, '/');
}

/** URL de una ruta de la aplicación: url('ventas/ver', ['id' => 5]). */
function url(string $ruta = '', array $parametros = []): string
{
    $consulta = $ruta === '' ? [] : ['r' => $ruta];
    $consulta += $parametros;
    return ruta_base() . '/index.php' . ($consulta ? '?' . http_build_query($consulta) : '');
}

/** URL de un archivo estático dentro de public/. */
function recurso(string $ruta): string
{
    return ruta_base() . '/' . ltrim($ruta, '/');
}

/** Redirige a una ruta de la aplicación y termina la petición (patrón POST/Redirect/GET). */
function redirigir(string $ruta, array $parametros = []): never
{
    header('Location: ' . url($ruta, $parametros), true, 302);
    exit;
}

/** Formatea un valor en centavos como pesos colombianos. */
function pesos(int $centavos): string
{
    return Dinero::formatear($centavos);
}

/** Texto para mostrar de moto.tipo_propulsion (el ENUM del modelo se guarda sin tildes). */
function propulsion(string $valor): string
{
    return ['Electrica' => 'Eléctrica', 'Combustible' => 'Combustión'][$valor] ?? $valor;
}

/** Formatea una fecha y hora de la base de datos (Y-m-d H:i:s) para mostrarla. */
function fecha_hora(string $valor): string
{
    $fecha = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $valor);
    return $fecha ? $fecha->format('d/m/Y h:i a') : $valor;
}

/** Registra un error en storage/logs/app.log. */
function registrar_error(Throwable $error): void
{
    $directorio = MOTOCONS_RAIZ . '/storage/logs';
    if (!is_dir($directorio)) {
        @mkdir($directorio, 0775, true);
    }
    error_log('[' . date('c') . '] ' . $error . PHP_EOL, 3, $directorio . '/app.log');
}
