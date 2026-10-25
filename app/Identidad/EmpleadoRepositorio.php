<?php
declare(strict_types=1);

namespace Motocons\Identidad;

use PDO;

/** Acceso a datos de la tabla `empleado`. */
final class EmpleadoRepositorio
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function buscarPorEmail(string $email): ?array
    {
        $consulta = $this->pdo->prepare(
            'SELECT id, nombre, email, password, rol FROM empleado WHERE email = ?'
        );
        $consulta->execute([$email]);
        return $consulta->fetch() ?: null;
    }

    public function buscarPorId(int $id): ?array
    {
        $consulta = $this->pdo->prepare('SELECT id, nombre, email, rol FROM empleado WHERE id = ?');
        $consulta->execute([$id]);
        return $consulta->fetch() ?: null;
    }

    /** Vendedores, para el filtro del listado que usa el administrador. */
    public function vendedores(): array
    {
        $consulta = $this->pdo->prepare('SELECT id, nombre FROM empleado WHERE rol = ? ORDER BY nombre');
        $consulta->execute([Roles::VENDEDOR]);
        return $consulta->fetchAll();
    }
}
