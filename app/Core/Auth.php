<?php
declare(strict_types=1);

namespace Motocons\Core;

/**
 * Autenticación (quién es el usuario) y autorización (qué puede hacer) basadas
 * en la sesión. Los usuarios del módulo son los registros de la tabla `empleado`.
 */
final class Auth
{
    /** Empleado autenticado: ['id', 'nombre', 'email', 'rol'] o null. */
    public static function usuario(): ?array
    {
        return $_SESSION['empleado'] ?? null;
    }

    public static function iniciar(array $empleado): void
    {
        // Nuevo identificador de sesión al autenticarse: evita la fijación de sesión.
        session_regenerate_id(true);
        $_SESSION['empleado'] = [
            'id'     => (int) $empleado['id'],
            'nombre' => $empleado['nombre'],
            'email'  => $empleado['email'],
            'rol'    => $empleado['rol'],
        ];
    }

    public static function cerrar(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    /**
     * Exige un usuario autenticado con alguno de los roles indicados.
     *
     * @throws NoAutenticado  si no hay sesión
     * @throws AccesoDenegado si el rol no está autorizado
     */
    public static function exigirRol(string ...$roles): array
    {
        $usuario = self::usuario();
        if ($usuario === null) {
            throw new NoAutenticado();
        }
        if (!in_array($usuario['rol'], $roles, true)) {
            throw new AccesoDenegado("Su rol ({$usuario['rol']}) no tiene permiso para esta acción.");
        }
        return $usuario;
    }

    public static function tieneRol(string $rol): bool
    {
        return (self::usuario()['rol'] ?? null) === $rol;
    }
}
