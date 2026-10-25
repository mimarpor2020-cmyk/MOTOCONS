<?php
declare(strict_types=1);

namespace Motocons\Core;

/** Se lanza cuando el recurso solicitado no existe o el usuario no puede verlo (HTTP 404). */
final class NoEncontrado extends \RuntimeException
{
    public function __construct(string $mensaje = 'La página o el registro solicitado no existe.')
    {
        parent::__construct($mensaje);
    }
}
