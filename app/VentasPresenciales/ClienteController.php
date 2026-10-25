<?php
declare(strict_types=1);

namespace Motocons\VentasPresenciales;

use Motocons\Core\Auth;
use Motocons\Core\ErrorValidacion;
use Motocons\Core\Sesion;
use Motocons\Core\Vista;
use Motocons\Identidad\Roles;
use PDO;

/** Registro rápido de un cliente desde el punto de venta. */
final class ClienteController
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function nuevo(): void
    {
        Auth::exigirRol(Roles::VENDEDOR);
        Vista::render('clientes/nuevo', ['titulo' => 'Registrar cliente', 'entrada' => [], 'errores' => []]);
    }

    public function crear(): void
    {
        Auth::exigirRol(Roles::VENDEDOR);
        try {
            $id = (new RegistrarCliente($this->pdo))->ejecutar($_POST);
        } catch (ErrorValidacion $error) {
            Vista::render('clientes/nuevo', [
                'titulo'  => 'Registrar cliente',
                'entrada' => $_POST,
                'errores' => $error->errores(),
            ], 422);
            return;
        }
        Sesion::flash('exito', 'Cliente registrado. Ya está seleccionado en la venta.');
        redirigir('ventas/nueva', ['cliente_id' => $id]);
    }
}
