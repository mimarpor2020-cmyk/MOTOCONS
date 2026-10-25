<?php
/**
 * Formulario de registro de una venta presencial (HU-05).
 * Los atributos data-* alimentan la vista previa de app.js; el servidor recalcula todo al registrar.
 *
 * @var array $entrada  @var array $errores  @var array $clientes  @var array $motos
 * @var array $productos  @var string $formToken
 */
use Motocons\Core\Csrf;
use Motocons\VentasPresenciales\MetodoPago;
use Motocons\VentasPresenciales\RegistrarVentaPresencial;

$maxCantidad = RegistrarVentaPresencial::MAX_CANTIDAD_POR_LINEA;
$clienteSeleccionado = texto($entrada['cliente_id'] ?? '');
$metodoSeleccionado = texto($entrada['metodo_pago'] ?? '');

// Filas a mostrar: las que envió el usuario (si hubo errores) o una fila vacía de moto.
$filasMotos = is_array($entrada['motos'] ?? null) ? array_values(array_filter($entrada['motos'], 'is_array')) : [];
$filasProductos = is_array($entrada['productos'] ?? null) ? array_values(array_filter($entrada['productos'], 'is_array')) : [];
if ($filasMotos === [] && $filasProductos === []) {
    $filasMotos = [['id' => '', 'cantidad' => '1']];
}

$opcionesMoto = static function (string $seleccionada) use ($motos): string {
    $html = '<option value="">— Seleccione una moto —</option>';
    foreach ($motos as $m) {
        $etiqueta = "{$m['marca']} {$m['modelo']} · " . ($m['gama'] ?? 'Sin gama') . ' · ' . propulsion($m['tipo_propulsion']) . ' · '
            . pesos($m['precio_centavos']);
        if ($m['promocion'] !== null) {
            $etiqueta .= ' (promoción −' . rtrim(rtrim($m['promocion']['porcentaje'], '0'), '.') . ' %)';
        }
        $etiqueta .= (int) $m['stock'] > 0 ? " · stock {$m['stock']}" : ' · AGOTADA';
        $html .= sprintf(
            '<option value="%d" data-precio="%d" data-puntos="%d" data-stock="%d"%s%s>%s</option>',
            $m['id'], $m['precio_centavos'], $m['puntos_basicos'], $m['stock'],
            (string) $m['id'] === $seleccionada ? ' selected' : '',
            (int) $m['stock'] > 0 || (string) $m['id'] === $seleccionada ? '' : ' disabled',
            e($etiqueta)
        );
    }
    return $html;
};

$opcionesProducto = static function (string $seleccionado) use ($productos): string {
    $html = '<option value="">— Seleccione un repuesto o insumo —</option>';
    foreach ($productos as $p) {
        $etiqueta = "{$p['nombre']} · {$p['tipo']} · " . pesos($p['precio_centavos'])
            . ((int) $p['stock'] > 0 ? " · stock {$p['stock']}" : ' · AGOTADO');
        $html .= sprintf(
            '<option value="%d" data-precio="%d" data-puntos="0" data-stock="%d"%s%s>%s</option>',
            $p['id'], $p['precio_centavos'], $p['stock'],
            (string) $p['id'] === $seleccionado ? ' selected' : '',
            (int) $p['stock'] > 0 || (string) $p['id'] === $seleccionado ? '' : ' disabled',
            e($etiqueta)
        );
    }
    return $html;
};

$fila = static function (string $grupo, int $indice, array $datos, string $opciones) use ($maxCantidad): string {
    $cantidad = texto($datos['cantidad'] ?? '1');
    return '<tr class="linea">'
        . '<td><select name="' . $grupo . '[' . $indice . '][id]" class="linea-articulo" aria-label="Artículo">' . $opciones . '</select></td>'
        . '<td><input type="number" name="' . $grupo . '[' . $indice . '][cantidad]" class="linea-cantidad" min="1" max="' . $maxCantidad . '" value="' . e($cantidad === '' ? '1' : $cantidad) . '" aria-label="Cantidad"></td>'
        . '<td class="numero linea-precio">—</td><td class="numero linea-descuento">—</td><td class="numero linea-subtotal">—</td>'
        . '<td><button type="button" class="boton boton-icono" data-quitar-linea aria-label="Quitar línea">✕</button></td>'
        . '</tr>';
};

$claseError = static fn (string $campo): string => isset($errores[$campo]) ? ' con-error' : '';
?>
<div class="encabezado-pagina">
    <h1>Registrar venta presencial</h1>
    <a class="boton boton-fantasma" href="<?= e(url('ventas')) ?>">Volver al listado</a>
</div>

<?php if ($errores): ?>
    <div class="alerta alerta-error" role="alert">
        <strong>No se registró la venta. Corrija lo siguiente:</strong>
        <ul><?php foreach ($errores as $mensaje): ?><li><?= e($mensaje) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('ventas/registrar')) ?>" id="form-venta" class="formulario-venta" data-un-envio>
    <input type="hidden" name="csrf" value="<?= e(Csrf::token()) ?>">
    <input type="hidden" name="form_token" value="<?= e($formToken) ?>">

    <section class="tarjeta">
        <h2><span class="paso">1</span> Cliente</h2>
        <div class="fila-campos">
            <div class="campo campo-ancho">
                <label for="buscar-cliente">Buscar cliente</label>
                <input type="search" id="buscar-cliente" placeholder="Nombre, correo o teléfono" data-filtrar-select="cliente_id">
            </div>
            <div class="campo campo-ancho">
                <label for="cliente_id">Cliente *</label>
                <select id="cliente_id" name="cliente_id" required class="<?= $claseError('cliente_id') ?>">
                    <option value="">— Seleccione el cliente —</option>
                    <?php foreach ($clientes as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= (string) $c['id'] === $clienteSeleccionado ? 'selected' : '' ?>>
                            <?= e($c['nombre'] . ' — ' . $c['email'] . ($c['telefono'] ? ' — ' . $c['telefono'] : '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <p class="texto-suave">¿El cliente no aparece? <a href="<?= e(url('clientes/nuevo')) ?>">Regístrelo aquí</a>.</p>
    </section>

    <section class="tarjeta">
        <h2><span class="paso">2</span> Motos</h2>
        <div class="tabla-desplazable">
            <table class="tabla tabla-lineas<?= $claseError('motos') ?>" data-grupo="motos">
                <thead><tr><th>Moto</th><th>Cant.</th><th class="numero">Precio unit.</th><th class="numero">Descuento</th><th class="numero">Subtotal</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($filasMotos as $i => $datos): ?>
                    <?= $fila('motos', $i, $datos, $opcionesMoto(texto($datos['id'] ?? ''))) ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <template id="plantilla-motos"><?= $fila('motos', 0, [], $opcionesMoto('')) ?></template>
        <button type="button" class="boton" data-agregar-linea="motos">+ Agregar moto</button>
    </section>

    <section class="tarjeta">
        <h2><span class="paso">3</span> Repuestos e insumos <span class="texto-suave">(opcional)</span></h2>
        <div class="tabla-desplazable">
            <table class="tabla tabla-lineas<?= $claseError('productos') ?>" data-grupo="productos">
                <thead><tr><th>Repuesto / insumo</th><th>Cant.</th><th class="numero">Precio unit.</th><th class="numero">Descuento</th><th class="numero">Subtotal</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($filasProductos as $i => $datos): ?>
                    <?= $fila('productos', $i, $datos, $opcionesProducto(texto($datos['id'] ?? ''))) ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <template id="plantilla-productos"><?= $fila('productos', 0, [], $opcionesProducto('')) ?></template>
        <button type="button" class="boton" data-agregar-linea="productos">+ Agregar repuesto o insumo</button>
    </section>

    <section class="tarjeta">
        <h2><span class="paso">4</span> Forma de pago</h2>
        <div class="opciones-pago<?= $claseError('metodo_pago') ?>" role="radiogroup" aria-label="Forma de pago">
            <?php foreach (MetodoPago::ETIQUETAS as $valor => $etiqueta): ?>
                <label class="opcion">
                    <input type="radio" name="metodo_pago" value="<?= e($valor) ?>" required <?= $metodoSeleccionado === $valor ? 'checked' : '' ?>>
                    <span><?= e($etiqueta) ?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <fieldset class="requisitos" data-para-metodo="<?= e(MetodoPago::BRILLA) ?>">
            <legend>Requisitos de Crédito Brilla</legend>
            <label for="soporte_recibo_gas">Referencia del recibo de gas presentado (n.º de factura o contrato) *</label>
            <input id="soporte_recibo_gas" name="soporte_recibo_gas" maxlength="<?= RegistrarVentaPresencial::MAX_LONGITUD_SOPORTE ?>"
                   value="<?= e(texto($entrada['soporte_recibo_gas'] ?? '')) ?>" class="<?= $claseError('soporte_recibo_gas') ?>">
            <label class="casilla<?= $claseError('recibo_gas_validado') ?>">
                <input type="checkbox" name="recibo_gas_validado" value="1" <?= texto($entrada['recibo_gas_validado'] ?? '') === '1' ? 'checked' : '' ?>>
                Verifiqué el recibo de gas original del cliente.
            </label>
        </fieldset>

        <fieldset class="requisitos" data-para-metodo="<?= e(MetodoPago::SERVICREDITO) ?>">
            <legend>Requisitos de ServiCrédito</legend>
            <label class="casilla<?= $claseError('requisitos_servicredito_ok') ?>">
                <input type="checkbox" name="requisitos_servicredito_ok" value="1" <?= texto($entrada['requisitos_servicredito_ok'] ?? '') === '1' ? 'checked' : '' ?>>
                Verifiqué que el cliente cumple los requisitos de ServiCrédito y el crédito fue aprobado.
            </label>
        </fieldset>
    </section>

    <section class="tarjeta resumen">
        <h2><span class="paso">5</span> Resumen</h2>
        <dl class="totales">
            <dt>Subtotal</dt><dd id="resumen-subtotal">—</dd>
            <dt>Descuentos por promoción</dt><dd id="resumen-descuento">—</dd>
            <dt class="total">Total a pagar</dt><dd class="total" id="resumen-total">—</dd>
        </dl>
        <p class="texto-suave">Vista previa. El total definitivo lo calcula el servidor con los precios y promociones vigentes al registrar.</p>
        <button type="submit" class="boton boton-primario boton-grande">Registrar venta</button>
    </section>
</form>
