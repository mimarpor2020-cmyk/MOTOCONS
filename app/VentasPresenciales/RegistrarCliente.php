<?php
declare(strict_types=1);

namespace Motocons\VentasPresenciales;

use Motocons\Core\ErrorValidacion;
use PDO;
use PDOException;

/**
 * Registro mínimo del cliente que compra en la tienda, cuando aún no existe en el
 * sistema. Es una dependencia de RF-02 (venta.cliente_id es obligatorio); no es un
 * módulo de gestión de clientes: no edita ni elimina clientes.
 */
final class RegistrarCliente
{
    private readonly ClienteRepositorio $clientes;

    public function __construct(PDO $pdo)
    {
        $this->clientes = new ClienteRepositorio($pdo);
    }

    /** @throws ErrorValidacion */
    public function ejecutar(array $entrada): int
    {
        $nombre    = texto($entrada['nombre'] ?? '');
        $email     = mb_strtolower(texto($entrada['email'] ?? ''));
        $telefono  = texto($entrada['telefono'] ?? '');
        $direccion = texto($entrada['direccion'] ?? '');

        // Longitudes máximas = tamaño de las columnas de la tabla cliente.
        $errores = [];
        if ($nombre === '' || mb_strlen($nombre) > 100) {
            $errores['nombre'] = 'El nombre es obligatorio (máximo 100 caracteres).';
        }
        if ($email === '' || mb_strlen($email) > 100 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errores['email'] = 'Escriba un correo electrónico válido (máximo 100 caracteres).';
        }
        if ($telefono !== '' && !preg_match('/^\+?[0-9 ]{7,20}$/', $telefono)) {
            $errores['telefono'] = 'El teléfono solo puede tener dígitos y espacios (7 a 20), con + opcional al inicio.';
        }
        if (mb_strlen($direccion) > 255) {
            $errores['direccion'] = 'La dirección no puede superar 255 caracteres.';
        }
        if (!isset($errores['email']) && $this->clientes->existeEmail($email)) {
            $errores['email'] = 'Ya existe un cliente con ese correo. Selecciónelo en la venta.';
        }
        if ($errores !== []) {
            throw new ErrorValidacion($errores);
        }

        try {
            return $this->clientes->crear($nombre, $email, $telefono ?: null, $direccion ?: null);
        } catch (PDOException $error) {
            // 1062: otro usuario registró el mismo correo entre la verificación y la inserción.
            if (($error->errorInfo[1] ?? null) === 1062) {
                throw new ErrorValidacion(['email' => 'Ya existe un cliente con ese correo. Selecciónelo en la venta.']);
            }
            throw $error;
        }
    }
}
