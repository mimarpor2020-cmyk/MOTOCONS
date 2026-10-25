<?php
declare(strict_types=1);

namespace Motocons\Core;

/**
 * Protección de formularios.
 *
 * 1. Token CSRF de sesión: todo POST debe incluirlo; impide que otro sitio envíe
 *    formularios en nombre del usuario autenticado.
 * 2. Tokens de un solo uso por formulario: impiden registrar dos veces la misma
 *    venta por doble clic, recarga de la página o reenvío del navegador.
 */
final class Csrf
{
    private const MAX_TOKENS_POR_FORMULARIO = 20;

    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function validar(mixed $token): bool
    {
        return is_string($token)
            && isset($_SESSION['csrf'])
            && hash_equals($_SESSION['csrf'], $token);
    }

    public static function emitirTokenFormulario(string $formulario): string
    {
        $token = bin2hex(random_bytes(16));
        $_SESSION['tokens_formulario'][$formulario][$token] = time();
        // Se conservan solo los más recientes (varias pestañas abiertas a la vez).
        $_SESSION['tokens_formulario'][$formulario] = array_slice(
            $_SESSION['tokens_formulario'][$formulario],
            -self::MAX_TOKENS_POR_FORMULARIO,
            null,
            true
        );
        return $token;
    }

    /** Valida el token y lo invalida; un segundo envío con el mismo token devuelve false. */
    public static function consumirTokenFormulario(string $formulario, mixed $token): bool
    {
        if (!is_string($token) || !isset($_SESSION['tokens_formulario'][$formulario][$token])) {
            return false;
        }
        unset($_SESSION['tokens_formulario'][$formulario][$token]);
        return true;
    }
}
