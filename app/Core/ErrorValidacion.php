<?php
declare(strict_types=1);

namespace Motocons\Core;

/**
 * Datos de entrada que incumplen una validación o una regla de negocio.
 * Transporta todos los errores encontrados, indexados por el campo afectado,
 * para mostrarlos juntos en el formulario (HTTP 422).
 */
final class ErrorValidacion extends \RuntimeException
{
    /** @param array<string,string> $errores campo => mensaje */
    public function __construct(private readonly array $errores)
    {
        parent::__construct(implode(' ', $errores));
    }

    /** @return array<string,string> */
    public function errores(): array
    {
        return $this->errores;
    }
}
