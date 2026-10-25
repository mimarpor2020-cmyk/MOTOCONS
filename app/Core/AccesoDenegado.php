<?php
declare(strict_types=1);

namespace Motocons\Core;

/** Se lanza cuando el usuario autenticado no tiene el rol requerido (HTTP 403). */
final class AccesoDenegado extends \RuntimeException
{
    public function __construct(string $mensaje = 'No tiene permiso para realizar esta acción.')
    {
        parent::__construct($mensaje);
    }
}
