<?php
/**
 * Pruebas extremo a extremo por HTTP contra Apache (XAMPP) y la base motocons_db.
 *
 * Uso (Apache y MySQL iniciados, base instalada con datos de prueba):
 *   C:\xampp\php\php.exe tests\e2e_http.php [url_base]
 *   url_base por defecto: http://localhost/motocons
 *
 * ATENCIÓN: registra ventas y un cliente en motocons_db. Para dejar la base como
 * recién instalada ejecute después: C:\xampp\php\php.exe database\instalar.php --recrear
 */
declare(strict_types=1);

use Motocons\Core\Database;

require __DIR__ . '/lib.php';

$base = rtrim($argv[1] ?? 'http://localhost/motocons', '/');
$app = $base . '/public/index.php';
$pdo = Database::conectar($GLOBALS['config']['db']);
const CLAVE = 'Motocons2026*';

/** Cliente HTTP mínimo con cookies propias (cada instancia es un navegador distinto). */
final class Navegador
{
    private string $cookies;
    public function __construct()
    {
        $this->cookies = tempnam(sys_get_temp_dir(), 'mc');
    }
    public function get(string $url): array
    {
        return $this->peticion($url, null);
    }
    public function post(string $url, array $campos): array
    {
        return $this->peticion($url, http_build_query($campos));
    }
    private function peticion(string $url, ?string $cuerpo): array
    {
        $c = curl_init($url);
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->cookies, CURLOPT_COOKIEFILE => $this->cookies,
        ]);
        if ($cuerpo !== null) {
            curl_setopt($c, CURLOPT_POST, true);
            curl_setopt($c, CURLOPT_POSTFIELDS, $cuerpo);
        }
        $respuesta = curl_exec($c);
        if ($respuesta === false) {
            throw new RuntimeException('No hay respuesta de ' . $url . ': ' . curl_error($c));
        }
        $tamEncabezado = curl_getinfo($c, CURLINFO_HEADER_SIZE);
        $resultado = [
            'codigo' => curl_getinfo($c, CURLINFO_RESPONSE_CODE),
            'encabezados' => substr($respuesta, 0, $tamEncabezado),
            'cuerpo' => substr($respuesta, $tamEncabezado),
        ];
        curl_close($c);
        preg_match('/^Location:\s*(.+)$/mi', $resultado['encabezados'], $m);
        $resultado['location'] = isset($m[1]) ? trim($m[1]) : null;
        return $resultado;
    }
    public function cookieSesion(): ?string
    {
        preg_match('/MOTOCONS_SID\s+(\S+)/', (string) file_get_contents($this->cookies), $m);
        return $m[1] ?? null;
    }
}

function campoOculto(string $html, string $nombre): string
{
    preg_match('/name="' . preg_quote($nombre, '/') . '" value="([^"]+)"/', $html, $m);
    afirmar(isset($m[1]), "No se encontró el campo oculto {$nombre}.");
    return html_entity_decode($m[1]);
}

function iniciarSesion(Navegador $n, string $app, string $email): array
{
    $login = $n->get("{$app}?r=login");
    return $n->post("{$app}?r=login", ['csrf' => campoOculto($login['cuerpo'], 'csrf'), 'email' => $email, 'clave' => CLAVE]);
}

$carlos = new Navegador();
$ana = new Navegador();
$admin = new Navegador();
$mecanico = new Navegador();
$contexto = [];

seccion("Autenticación ({$app})");

prueba('Sin sesión, el módulo redirige al inicio de sesión', function () use ($app) {
    $r = (new Navegador())->get("{$app}?r=ventas");
    afirmarIgual(302, $r['codigo']);
    afirmar(str_contains((string) $r['location'], 'r=login'), 'Location: ' . $r['location']);
});

prueba('Contraseña incorrecta: 401 y mensaje genérico', function () use ($app) {
    $n = new Navegador();
    $login = $n->get("{$app}?r=login");
    $r = $n->post("{$app}?r=login", ['csrf' => campoOculto($login['cuerpo'], 'csrf'), 'email' => 'carlos.perez@motocons.test', 'clave' => 'incorrecta']);
    afirmarIgual(401, $r['codigo']);
    afirmar(str_contains($r['cuerpo'], 'Correo o contraseña incorrectos'), 'Falta el mensaje de error.');
});

prueba('Inicio de sesión del vendedor: cambia el id de sesión y entra a sus ventas', function () use ($app, $carlos) {
    $carlos->get("{$app}?r=login");
    $antes = $carlos->cookieSesion();
    $r = iniciarSesion($carlos, $app, 'carlos.perez@motocons.test');
    afirmarIgual(302, $r['codigo']);
    afirmar($antes !== $carlos->cookieSesion(), 'El identificador de sesión debe regenerarse al iniciar sesión.');
    $lista = $carlos->get("{$app}?r=ventas");
    afirmarIgual(200, $lista['codigo']);
    afirmar(str_contains($lista['cuerpo'], 'Mis ventas presenciales'), 'Título del listado del vendedor.');
});

prueba('La cookie de sesión es HttpOnly y SameSite=Lax; hay cabeceras de seguridad', function () use ($app) {
    $r = (new Navegador())->get("{$app}?r=login");
    afirmar((bool) preg_match('/Set-Cookie: MOTOCONS_SID=[^\r\n]*HttpOnly[^\r\n]*SameSite=Lax/i', $r['encabezados']), 'Cookie sin HttpOnly/SameSite.');
    afirmar(str_contains($r['encabezados'], 'Content-Security-Policy:'), 'Falta CSP.');
    afirmar(str_contains($r['encabezados'], 'X-Frame-Options: DENY'), 'Falta X-Frame-Options.');
});

seccion('Registro de una venta presencial por HTTP');

prueba('El formulario de nueva venta carga clientes, motos y token de un solo uso', function () use ($app, $carlos, &$contexto) {
    $r = $carlos->get("{$app}?r=ventas/nueva");
    afirmarIgual(200, $r['codigo']);
    afirmar(str_contains($r['cuerpo'], 'María Fernanda López'), 'Falta el cliente de prueba.');
    afirmar(str_contains($r['cuerpo'], 'Pulsar NS 200'), 'Falta la moto de prueba.');
    afirmar(str_contains($r['cuerpo'], 'AGOTADA'), 'La moto sin stock debe marcarse como agotada.');
    $contexto['csrf'] = campoOculto($r['cuerpo'], 'csrf');
    $contexto['form_token'] = campoOculto($r['cuerpo'], 'form_token');
});

prueba('Un POST sin token CSRF se rechaza (400) y no registra nada', function () use ($app, $carlos, $pdo) {
    $antes = (int) valor($pdo, 'SELECT COUNT(*) FROM venta');
    $r = $carlos->post("{$app}?r=ventas/registrar", ['cliente_id' => '1', 'motos' => [['id' => '2', 'cantidad' => '1']], 'metodo_pago' => 'contado']);
    afirmarIgual(400, $r['codigo']);
    afirmarIgual($antes, (int) valor($pdo, 'SELECT COUNT(*) FROM venta'));
});

prueba('Registra la venta; ignora empleado_id y precios manipulados; redirige al comprobante', function () use ($app, $carlos, $pdo, &$contexto) {
    $r = $carlos->post("{$app}?r=ventas/registrar", [
        'csrf' => $contexto['csrf'], 'form_token' => $contexto['form_token'],
        'cliente_id' => '1', 'empleado_id' => '3', 'total' => '1',
        'motos' => [['id' => '2', 'cantidad' => '1', 'precio' => '1']],
        'productos' => [['id' => '1', 'cantidad' => '2']],
        'metodo_pago' => 'contado',
    ]);
    afirmarIgual(302, $r['codigo']);
    afirmar((bool) preg_match('/r=ventas%2Fver&id=(\d+)/', (string) $r['location'], $m), 'Location: ' . $r['location']);
    $contexto['venta'] = (int) $m[1];
    $fila = $pdo->query("SELECT empleado_id, tipo_venta, total FROM venta WHERE id = {$contexto['venta']}")->fetch();
    afirmarIgual(['empleado_id' => 2, 'tipo_venta' => 'fisica', 'total' => '10417000.00'], $fila, '10.341.000 + 2 × 38.000:');

    $detalle = $carlos->get("{$app}?r=ventas/ver&id={$contexto['venta']}");
    afirmarIgual(200, $detalle['codigo']);
    foreach (['Carlos Pérez', '$ 10.417.000', 'registrada correctamente', '− $ 1.149.000'] as $texto) {
        afirmar(str_contains($detalle['cuerpo'], $texto), "El comprobante debe mostrar «{$texto}».");
    }
});

prueba('Reenviar el mismo formulario (doble clic / recarga) no duplica la venta', function () use ($app, $carlos, $pdo, &$contexto) {
    $antes = (int) valor($pdo, 'SELECT COUNT(*) FROM venta');
    $r = $carlos->post("{$app}?r=ventas/registrar", [
        'csrf' => $contexto['csrf'], 'form_token' => $contexto['form_token'],
        'cliente_id' => '1', 'motos' => [['id' => '2', 'cantidad' => '1']], 'metodo_pago' => 'contado',
    ]);
    afirmarIgual(302, $r['codigo']);
    afirmarIgual($antes, (int) valor($pdo, 'SELECT COUNT(*) FROM venta'));
    afirmar(str_contains($carlos->get("{$app}?r=ventas")['cuerpo'], 'ya había sido enviado'), 'Debe avisar del reenvío.');
});

prueba('Errores de validación: 422, mensajes y conserva lo digitado', function () use ($app, $carlos, $pdo) {
    $formulario = $carlos->get("{$app}?r=ventas/nueva");
    $antes = (int) valor($pdo, 'SELECT COUNT(*) FROM venta');
    $r = $carlos->post("{$app}?r=ventas/registrar", [
        'csrf' => campoOculto($formulario['cuerpo'], 'csrf'), 'form_token' => campoOculto($formulario['cuerpo'], 'form_token'),
        'cliente_id' => '2', 'motos' => [['id' => '4', 'cantidad' => '1']], 'metodo_pago' => 'brilla',
    ]);
    afirmarIgual(422, $r['codigo']);
    afirmar(str_contains($r['cuerpo'], 'No se registró la venta'), 'Resumen de errores.');
    afirmar(str_contains($r['cuerpo'], 'recibo de gas'), 'Mensaje del requisito de Brilla.');
    afirmar((bool) preg_match('/value="2"\s+selected/', $r['cuerpo']), 'Debe conservar el cliente elegido.');
    afirmar(str_contains($r['cuerpo'], 'value="brilla" required checked'), 'Debe conservar la forma de pago.');
    afirmarIgual($antes, (int) valor($pdo, 'SELECT COUNT(*) FROM venta'));
});

prueba('Venta con Crédito Brilla completa por HTTP', function () use ($app, $carlos, $pdo) {
    $formulario = $carlos->get("{$app}?r=ventas/nueva");
    $r = $carlos->post("{$app}?r=ventas/registrar", [
        'csrf' => campoOculto($formulario['cuerpo'], 'csrf'), 'form_token' => campoOculto($formulario['cuerpo'], 'form_token'),
        'cliente_id' => '2', 'motos' => [['id' => '4', 'cantidad' => '1']], 'metodo_pago' => 'brilla',
        'soporte_recibo_gas' => 'SURTIGAS-445566', 'recibo_gas_validado' => '1',
    ]);
    afirmarIgual(302, $r['codigo']);
    afirmarIgual('SURTIGAS-445566', valor($pdo, "SELECT soporte_recibo_gas FROM transaccion_pago WHERE metodo_pago = 'brilla' ORDER BY id DESC LIMIT 1"));
});

seccion('Consulta, filtros y permisos');

prueba('Filtros del vendedor por forma de pago y por moto', function () use ($app, $carlos, &$contexto) {
    $contado = $carlos->get("{$app}?r=ventas&metodo_pago=contado")['cuerpo'];
    afirmar(str_contains($contado, "#{$contexto['venta']}<"), 'La venta de contado debe aparecer.');
    afirmar(str_contains($contado, '1 venta(s) encontrada(s)'), 'Solo una venta de contado.');
    $porMoto = $carlos->get("{$app}?r=ventas&moto_id=7")['cuerpo'];
    afirmar(str_contains($porMoto, '0 venta(s)'), 'Ninguna venta de la moto 7.');
    $porCliente = $carlos->get("{$app}?r=ventas&cliente=andres")['cuerpo'];
    afirmar(str_contains($porCliente, '1 venta(s)') && str_contains($porCliente, 'Andrés Felipe Torres'), 'Filtro por cliente.');
});

prueba('Otro vendedor no ve ni abre las ventas de Carlos (404)', function () use ($app, $ana, &$contexto) {
    iniciarSesion($ana, $app, 'ana.ruiz@motocons.test');
    afirmarIgual(404, $ana->get("{$app}?r=ventas/ver&id={$contexto['venta']}")['codigo']);
    afirmar(str_contains($ana->get("{$app}?r=ventas")['cuerpo'], '0 venta(s)'), 'Ana no tiene ventas.');
});

prueba('El administrador ve todas las ventas y su vendedor, pero no registra ventas (403)', function () use ($app, $admin, &$contexto) {
    iniciarSesion($admin, $app, 'admin@motocons.test');
    $lista = $admin->get("{$app}?r=ventas");
    afirmarIgual(200, $lista['codigo']);
    afirmar(str_contains($lista['cuerpo'], "#{$contexto['venta']}<") && str_contains($lista['cuerpo'], '<th>Vendedor</th>'), 'Listado del administrador.');
    afirmar(str_contains($admin->get("{$app}?r=ventas&empleado_id=3")['cuerpo'], '0 venta(s)'), 'Filtro por vendedor.');
    afirmarIgual(200, $admin->get("{$app}?r=ventas/ver&id={$contexto['venta']}")['codigo']);
    afirmarIgual(403, $admin->get("{$app}?r=ventas/nueva")['codigo']);
    $r = $admin->post("{$app}?r=ventas/registrar", ['csrf' => campoOculto($lista['cuerpo'], 'csrf'), 'cliente_id' => '1', 'motos' => [['id' => '1', 'cantidad' => '1']], 'metodo_pago' => 'contado']);
    afirmarIgual(403, $r['codigo']);
});

prueba('El mecánico no tiene acceso al módulo (403)', function () use ($app, $mecanico) {
    iniciarSesion($mecanico, $app, 'jorge.diaz@motocons.test');
    $r = $mecanico->get("{$app}?r=ventas");
    afirmarIgual(403, $r['codigo']);
    afirmar(str_contains($r['cuerpo'], 'Mecanico'), 'Debe explicar el rol sin permiso.');
});

seccion('Registro de cliente y seguridad');

prueba('Registra un cliente y lo deja seleccionado; el contenido se escapa (XSS)', function () use ($app, $carlos) {
    $form = $carlos->get("{$app}?r=clientes/nuevo");
    $r = $carlos->post("{$app}?r=clientes/crear", [
        'csrf' => campoOculto($form['cuerpo'], 'csrf'),
        'nombre' => '<script>alert(1)</script> Prueba', 'email' => 'xss.' . bin2hex(random_bytes(4)) . '@correo.test',
    ]);
    afirmarIgual(302, $r['codigo']);
    afirmar((bool) preg_match('/cliente_id=(\d+)/', (string) $r['location'], $m), 'Location: ' . $r['location']);
    $venta = $carlos->get("{$app}?r=ventas/nueva&cliente_id={$m[1]}")['cuerpo'];
    afirmar(str_contains($venta, '&lt;script&gt;alert(1)&lt;/script&gt; Prueba'), 'El nombre debe mostrarse escapado.');
    afirmar(!str_contains($venta, '<script>alert(1)'), 'No debe haber script sin escapar.');
    afirmar((bool) preg_match('/value="' . $m[1] . '"\s+selected/', $venta), 'El cliente nuevo debe quedar seleccionado.');
});

prueba('Las carpetas internas no son accesibles desde el navegador', function () use ($base) {
    $n = new Navegador();
    foreach (['/database/01_motocons_db.sql', '/app/config.php', '/tests/run.php', '/storage/logs/', '/app/Vistas/layout.php'] as $ruta) {
        afirmarIgual(403, $n->get($base . $ruta)['codigo'], "{$ruta}:");
    }
});

prueba('Ruta inexistente: 404', function () use ($app, $carlos) {
    afirmarIgual(404, $carlos->get("{$app}?r=no/existe")['codigo']);
});

prueba('Cerrar sesión invalida el acceso', function () use ($app, $carlos) {
    $lista = $carlos->get("{$app}?r=ventas");
    $r = $carlos->post("{$app}?r=logout", ['csrf' => campoOculto($lista['cuerpo'], 'csrf')]);
    afirmarIgual(302, $r['codigo']);
    afirmarIgual(302, $carlos->get("{$app}?r=ventas")['codigo']);
});

prueba('Diagnóstico de la instalación: todas las comprobaciones en OK', function () use ($app) {
    $r = (new Navegador())->get("{$app}?r=diagnostico");
    afirmarIgual(200, $r['codigo']);
    afirmar(!str_contains($r['cuerpo'], 'FALLA'), 'Hay comprobaciones fallidas.');
});

resumenYSalir();
