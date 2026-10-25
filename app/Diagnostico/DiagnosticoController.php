<?php
declare(strict_types=1);

namespace Motocons\Diagnostico;

use Motocons\Core\Database;
use Motocons\Core\NoEncontrado;
use Motocons\Core\Vista;
use PDO;
use Throwable;

/**
 * Página de comprobación de la instalación (?r=diagnostico).
 * Verifica PHP, la conexión a MySQL/MariaDB, las tablas y los ajustes de RF-02.
 * Solo responde a peticiones hechas desde el mismo equipo (localhost).
 */
final class DiagnosticoController
{
    private const TABLAS = [
        'empleado', 'cliente', 'moto', 'producto', 'promocion',
        'venta', 'detalle_venta', 'detalle_venta_producto', 'transaccion_pago',
    ];

    public function __construct(private readonly array $config)
    {
    }

    public function index(): void
    {
        if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
            throw new NoEncontrado();
        }

        $db = $this->config['db'];
        $chequeos = [];
        $chequeos[] = $this->chequeo('Versión de PHP ≥ 8.0', PHP_VERSION_ID >= 80000, 'PHP ' . PHP_VERSION);
        $chequeos[] = $this->chequeo('Extensión pdo_mysql', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'Cargada' : 'Habilítela en php.ini');

        $pdo = null;
        try {
            $pdo = Database::conectar($db);
            $version = $pdo->query('SELECT VERSION()')->fetchColumn();
            $chequeos[] = $this->chequeo('Conexión a la base de datos', true, "{$db['usuario']}@{$db['host']}:{$db['puerto']} / {$db['nombre']} — servidor {$version}");
        } catch (Throwable $error) {
            $chequeos[] = $this->chequeo('Conexión a la base de datos', false, $error->getMessage()
                . ' — Verifique que MySQL esté iniciado en el panel de XAMPP, que la base exista y los datos de app/config.php.');
        }

        if ($pdo instanceof PDO) {
            $existentes = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            $faltantes = array_diff(self::TABLAS, $existentes);
            $chequeos[] = $this->chequeo('Tablas del modelo (01_motocons_db.sql)', $faltantes === [],
                $faltantes === [] ? count(self::TABLAS) . ' tablas requeridas presentes' : 'Faltan: ' . implode(', ', $faltantes));

            if ($faltantes === []) {
                $restriccion = $pdo->prepare(
                    "SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
                      WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_NAME = 'chk_venta_fisica_con_vendedor'"
                );
                $restriccion->execute([$db['nombre']]);
                $ajustes = (int) $restriccion->fetchColumn() === 1;
                $chequeos[] = $this->chequeo('Ajustes RF-02 (02_ajustes_rf02.sql)', $ajustes,
                    $ajustes ? 'Restricciones de integridad aplicadas' : 'Importe database/02_ajustes_rf02.sql');

                $conteo = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();
                $vendedores = $conteo("SELECT COUNT(*) FROM empleado WHERE rol = 'Vendedor'");
                $motos = $conteo('SELECT COUNT(*) FROM moto');
                $clientes = $conteo('SELECT COUNT(*) FROM cliente');
                $chequeos[] = $this->chequeo('Datos mínimos (03_datos_prueba.sql)', $vendedores > 0 && $motos > 0 && $clientes > 0,
                    "{$vendedores} vendedores, {$motos} motos, {$clientes} clientes, "
                    . $conteo("SELECT COUNT(*) FROM venta WHERE tipo_venta = 'fisica'") . ' ventas presenciales');

                $horaBd = (string) $pdo->query('SELECT NOW()')->fetchColumn();
                $diferencia = abs(strtotime($horaBd) - time());
                $chequeos[] = $this->chequeo('Hora de PHP y de la base coinciden', $diferencia < 120,
                    "PHP: " . date('Y-m-d H:i:s') . " · Base de datos: {$horaBd}");
            }
        }

        $correcto = !in_array(false, array_column($chequeos, 'ok'), true);
        Vista::render('diagnostico', [
            'titulo'   => 'Diagnóstico de la instalación',
            'chequeos' => $chequeos,
            'correcto' => $correcto,
        ], $correcto ? 200 : 503);
    }

    private function chequeo(string $nombre, bool $ok, string $detalle): array
    {
        return ['nombre' => $nombre, 'ok' => $ok, 'detalle' => $detalle];
    }
}
