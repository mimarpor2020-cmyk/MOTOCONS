<?php
declare(strict_types=1);

namespace Motocons\VentasPresenciales;

use Motocons\Core\Auth;
use Motocons\Core\Csrf;
use Motocons\Core\Dinero;
use Motocons\Core\ErrorValidacion;
use Motocons\Core\NoEncontrado;
use Motocons\Core\Sesion;
use Motocons\Core\Vista;
use Motocons\Identidad\EmpleadoRepositorio;
use Motocons\Identidad\Roles;
use PDO;

/**
 * Controlador HTTP de las ventas presenciales: recibe la petición, comprueba permisos,
 * delega las reglas en RegistrarVentaPresencial / VentaRepositorio y elige la vista.
 *
 * Permisos (decisión D3):
 *  - Vendedor: registra ventas y consulta únicamente las suyas.
 *  - Administrador: consulta todas las ventas presenciales (puede filtrar por vendedor).
 *  - Mecánico: sin acceso.
 */
final class VentaController
{
    private const POR_PAGINA = 20;

    private readonly VentaRepositorio $ventas;
    private readonly CatalogoRepositorio $catalogo;

    public function __construct(private readonly PDO $pdo)
    {
        $this->ventas = new VentaRepositorio($pdo);
        $this->catalogo = new CatalogoRepositorio($pdo);
    }

    public function listado(): void
    {
        $usuario = Auth::exigirRol(Roles::VENDEDOR, Roles::ADMINISTRADOR);
        $esAdministrador = $usuario['rol'] === Roles::ADMINISTRADOR;

        $metodo = $_GET['metodo_pago'] ?? '';
        $filtros = [
            'moto_id'     => $this->enteroPositivo($_GET['moto_id'] ?? null),
            'cliente'     => mb_substr(texto($_GET['cliente'] ?? ''), 0, 100),
            'metodo_pago' => MetodoPago::esValido($metodo) ? $metodo : null,
            'empleado_id' => $esAdministrador ? $this->enteroPositivo($_GET['empleado_id'] ?? null) : null,
        ];
        $pagina = $this->enteroPositivo($_GET['pagina'] ?? null) ?? 1;

        // La restricción por vendedor se aplica en el servidor, no depende de la interfaz.
        $resultado = $this->ventas->buscar(
            $filtros,
            $esAdministrador ? null : $usuario['id'],
            $pagina,
            self::POR_PAGINA
        );

        Vista::render('ventas/listado', [
            'titulo'          => $esAdministrador ? 'Ventas presenciales' : 'Mis ventas presenciales',
            'ventas'          => $resultado['filas'],
            'totalRegistros'  => $resultado['total'],
            'pagina'          => $pagina,
            'paginas'         => max(1, (int) ceil($resultado['total'] / self::POR_PAGINA)),
            'filtros'         => $filtros,
            'motos'           => $this->catalogo->motos(),
            'vendedores'      => $esAdministrador ? (new EmpleadoRepositorio($this->pdo))->vendedores() : [],
            'esAdministrador' => $esAdministrador,
        ]);
    }

    public function nueva(): void
    {
        Auth::exigirRol(Roles::VENDEDOR);
        $this->mostrarFormulario(['cliente_id' => $_GET['cliente_id'] ?? ''], []);
    }

    public function registrar(): void
    {
        $usuario = Auth::exigirRol(Roles::VENDEDOR);

        if (!Csrf::consumirTokenFormulario('venta', $_POST['form_token'] ?? null)) {
            Sesion::flash('aviso', 'Este formulario ya había sido enviado. Revise el listado antes de registrar la venta de nuevo.');
            redirigir('ventas');
        }

        try {
            // R1: el vendedor sale de la sesión. Cualquier "empleado_id" enviado en el formulario se ignora.
            $ventaId = (new RegistrarVentaPresencial($this->pdo))->ejecutar($usuario['id'], $_POST, date('Y-m-d'));
        } catch (ErrorValidacion $error) {
            $this->mostrarFormulario($_POST, $error->errores(), 422);
            return;
        }

        Sesion::flash('exito', "Venta #{$ventaId} registrada correctamente.");
        redirigir('ventas/ver', ['id' => $ventaId]);
    }

    public function ver(): void
    {
        $usuario = Auth::exigirRol(Roles::VENDEDOR, Roles::ADMINISTRADOR);
        $id = $this->enteroPositivo($_GET['id'] ?? null);
        $venta = $id === null ? null : $this->ventas->obtener($id);

        // Un vendedor no puede ver ventas de otros: se responde igual que si no existiera.
        $visible = $venta !== null
            && $venta['tipo_venta'] === VentaRepositorio::TIPO_FISICA
            && ($usuario['rol'] === Roles::ADMINISTRADOR || (int) $venta['empleado_id'] === $usuario['id']);
        if (!$visible) {
            throw new NoEncontrado('La venta solicitada no existe o no pertenece a sus ventas.');
        }

        Vista::render('ventas/detalle', ['titulo' => "Venta #{$venta['id']}", 'venta' => $venta]);
    }

    private function mostrarFormulario(array $entrada, array $errores, int $estadoHttp = 200): void
    {
        // Precios con la promoción vigente hoy: solo para informar al vendedor.
        // Al registrar, el servidor vuelve a calcular todo dentro de la transacción.
        $calculadora = new CalculadoraPrecios();
        $promociones = $this->catalogo->promocionesVigentes(date('Y-m-d'));
        $motos = [];
        foreach ($this->catalogo->motos() as $moto) {
            $promocion = $calculadora->promocionAplicable($moto, $promociones);
            $linea = $calculadora->lineaMoto($moto, 1, $promocion);
            $motos[] = $moto + [
                'precio_centavos'    => $linea['precio_unitario'],
                'descuento_centavos' => $linea['descuento_aplicado'],
                'puntos_basicos'     => $promocion['puntos_basicos'] ?? 0,
                'promocion'          => $linea['promocion'],
            ];
        }
        $productos = array_map(
            static fn (array $p): array => $p + ['precio_centavos' => Dinero::aCentavos((string) $p['precio_unitario'])],
            $this->catalogo->productos()
        );

        Vista::render('ventas/nueva', [
            'titulo'    => 'Registrar venta presencial',
            'entrada'   => $entrada,
            'errores'   => $errores,
            'clientes'  => (new ClienteRepositorio($this->pdo))->listar(),
            'motos'     => $motos,
            'productos' => $productos,
            'formToken' => Csrf::emitirTokenFormulario('venta'),
        ], $estadoHttp);
    }

    private function enteroPositivo(mixed $valor): ?int
    {
        $entero = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $entero === false ? null : $entero;
    }
}
