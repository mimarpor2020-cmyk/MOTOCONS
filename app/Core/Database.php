<?php
declare(strict_types=1);

namespace Motocons\Core;

use PDO;

/**
 * Crea la conexión PDO a MySQL/MariaDB.
 *
 * - ERRMODE_EXCEPTION: cualquier error SQL lanza una excepción (no hay fallos silenciosos).
 * - EMULATE_PREPARES = false: las consultas preparadas las resuelve el servidor,
 *   así los parámetros nunca se concatenan al SQL (prevención de inyección SQL).
 * - utf8mb4 en el DSN y zona horaria de la sesión fijada a la de la tienda.
 */
final class Database
{
    public static function conectar(array $db, bool $conBaseDeDatos = true): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], (int) $db['puerto']);
        if ($conBaseDeDatos) {
            $dsn .= ';dbname=' . $db['nombre'];
        }

        $pdo = new PDO($dsn, $db['usuario'], $db['clave'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $zona = $pdo->prepare('SET time_zone = ?');
        $zona->execute([$db['zona_horaria_sesion']]);

        return $pdo;
    }
}
