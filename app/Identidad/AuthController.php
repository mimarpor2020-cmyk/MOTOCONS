<?php
declare(strict_types=1);

namespace Motocons\Identidad;

use Motocons\Core\Auth;
use Motocons\Core\Sesion;
use Motocons\Core\Vista;
use PDO;

/** Inicio y cierre de sesión de los empleados. */
final class AuthController
{
    // Hash de una cadena cualquiera: se verifica contra él cuando el correo no existe
    // para que la respuesta tarde lo mismo y no revele qué correos están registrados.
    private const HASH_FICTICIO = '$2y$10$uTOmdTk6aS4VrpG9ep1zResW2gb4H2K2tBlznCPxRzVvGa8AfJB7O';

    private readonly EmpleadoRepositorio $empleados;

    public function __construct(PDO $pdo)
    {
        $this->empleados = new EmpleadoRepositorio($pdo);
    }

    public function formulario(): void
    {
        if (Auth::usuario() !== null) {
            redirigir('ventas');
        }
        Vista::render('login', ['titulo' => 'Iniciar sesión', 'email' => '']);
    }

    public function iniciar(): void
    {
        $email = texto($_POST['email'] ?? '');
        $clave = is_string($_POST['clave'] ?? null) ? $_POST['clave'] : '';

        $empleado = $email !== '' ? $this->empleados->buscarPorEmail($email) : null;
        $claveValida = password_verify($clave, $empleado['password'] ?? self::HASH_FICTICIO);

        if ($empleado === null || !$claveValida) {
            Vista::render('login', [
                'titulo' => 'Iniciar sesión',
                'email'  => $email,
                'error'  => 'Correo o contraseña incorrectos.',
            ], 401);
            return;
        }

        Auth::iniciar($empleado);
        Sesion::flash('exito', "Bienvenido(a), {$empleado['nombre']}.");
        redirigir('ventas');
    }

    public function cerrar(): void
    {
        Auth::cerrar();
        Sesion::flash('aviso', 'Sesión cerrada.');
        redirigir('login');
    }
}
