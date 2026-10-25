<?php
declare(strict_types=1);

namespace Motocons\Core;

/**
 * Sesión PHP con cookies seguras, cierre por inactividad y mensajes "flash"
 * (mensajes que se muestran una sola vez después de una redirección).
 */
final class Sesion
{
    public static function iniciar(int $minutosInactividad): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('MOTOCONS_SID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => ruta_base() . '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        session_start();

        $ahora = time();
        $ultima = $_SESSION['ultima_actividad'] ?? null;
        if ($ultima !== null && $ahora - $ultima > $minutosInactividad * 60 && isset($_SESSION['empleado'])) {
            $_SESSION = [];
            session_regenerate_id(true);
            self::flash('aviso', 'La sesión se cerró por inactividad. Inicie sesión de nuevo.');
        }
        $_SESSION['ultima_actividad'] = $ahora;
    }

    public static function flash(string $tipo, string $mensaje): void
    {
        $_SESSION['flash'][] = ['tipo' => $tipo, 'mensaje' => $mensaje];
    }

    /** Devuelve y elimina los mensajes pendientes. */
    public static function tomarFlash(): array
    {
        $mensajes = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $mensajes;
    }
}
