<?php
declare(strict_types=1);

namespace Motocons\Core;

/** Se lanza cuando una ruta exige sesión y el usuario no ha iniciado sesión. */
final class NoAutenticado extends \RuntimeException
{
    public function __construct(string $mensaje = 'La sesión no está iniciada.')
    {
        parent::__construct($mensaje);
    }
}
