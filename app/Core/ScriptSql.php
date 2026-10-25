<?php
declare(strict_types=1);

namespace Motocons\Core;

use PDO;
use RuntimeException;

/**
 * Ejecuta un archivo .sql sentencia por sentencia con PDO.
 * Lo usan el instalador de la base de datos y las pruebas automatizadas.
 *
 * Supone, como los scripts del proyecto, que cada sentencia termina con ";"
 * al final de una línea.
 */
final class ScriptSql
{
    public static function ejecutar(PDO $pdo, string $archivo): int
    {
        $sql = file_get_contents($archivo);
        if ($sql === false) {
            throw new RuntimeException("No se pudo leer {$archivo}");
        }

        $ejecutadas = 0;
        foreach (preg_split('/;\s*$/m', $sql) as $sentencia) {
            $sinComentarios = trim((string) preg_replace('/^\s*--.*$/m', '', $sentencia));
            if ($sinComentarios === '') {
                continue;
            }
            $pdo->exec($sentencia);
            $ejecutadas++;
        }
        return $ejecutadas;
    }
}
