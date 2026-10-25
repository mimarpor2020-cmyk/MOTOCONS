<?php
declare(strict_types=1);

namespace Motocons\VentasPresenciales;

use PDO;

/** Acceso a datos de la tabla `cliente`. */
final class ClienteRepositorio
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function listar(): array
    {
        return $this->pdo->query(
            'SELECT id, nombre, email, telefono FROM cliente ORDER BY nombre'
        )->fetchAll();
    }

    public function buscarPorId(int $id): ?array
    {
        $consulta = $this->pdo->prepare('SELECT id, nombre, email, telefono, direccion FROM cliente WHERE id = ?');
        $consulta->execute([$id]);
        return $consulta->fetch() ?: null;
    }

    public function existeEmail(string $email): bool
    {
        $consulta = $this->pdo->prepare('SELECT 1 FROM cliente WHERE email = ?');
        $consulta->execute([$email]);
        return (bool) $consulta->fetchColumn();
    }

    /**
     * Inserta un cliente atendido en tienda. La columna password es obligatoria en el
     * modelo (pensada para el portal web, RF-01); se guarda el hash de un valor aleatorio
     * que nadie conoce, de modo que la cuenta no permite iniciar sesión en el portal.
     */
    public function crear(string $nombre, string $email, ?string $telefono, ?string $direccion): int
    {
        $consulta = $this->pdo->prepare(
            'INSERT INTO cliente (nombre, email, password, telefono, direccion) VALUES (?, ?, ?, ?, ?)'
        );
        $consulta->execute([
            $nombre,
            $email,
            password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            $telefono,
            $direccion,
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}
