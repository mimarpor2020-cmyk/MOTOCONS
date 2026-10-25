<?php
/**
 * Detalle (comprobante) de una venta presencial.
 *
 * @var array $venta
 */
use Motocons\Core\Dinero;
use Motocons\VentasPresenciales\MetodoPago;

$c = static fn (string $decimal): string => pesos(Dinero::aCentavos($decimal));
$descuentoTotal = array_sum(array_map(static fn ($m) => Dinero::aCentavos($m['descuento_aplicado']), $venta['motos']));
?>
<div class="encabezado-pagina no-imprimir">
    <h1>Venta presencial #<?= (int) $venta['id'] ?></h1>
    <div class="acciones">
        <button type="button" class="boton" data-imprimir>Imprimir comprobante</button>
        <a class="boton boton-fantasma" href="<?= e(url('ventas')) ?>">Volver al listado</a>
    </div>
</div>

<article class="tarjeta comprobante">
    <header class="comprobante-encabezado">
        <div>
            <strong>MOTOCONS</strong><br>
            <span class="texto-suave">Comprobante de venta presencial</span>
        </div>
        <div class="derecha">
            <strong>N.º <?= (int) $venta['id'] ?></strong><br>
            <span class="texto-suave"><?= e(fecha_hora($venta['fecha_venta'])) ?></span>
        </div>
    </header>

    <div class="rejilla-datos">
        <div>
            <h3>Cliente</h3>
            <p><?= e($venta['cliente']) ?><br>
                <span class="texto-suave"><?= e($venta['cliente_email']) ?>
                <?= $venta['cliente_telefono'] ? ' · ' . e($venta['cliente_telefono']) : '' ?></span>
                <?php if ($venta['cliente_direccion']): ?><br><span class="texto-suave"><?= e($venta['cliente_direccion']) ?></span><?php endif; ?>
            </p>
        </div>
        <div>
            <h3>Vendedor</h3>
            <p><?= e($venta['vendedor']) ?></p>
        </div>
        <div>
            <h3>Forma de pago</h3>
            <p><?= e(MetodoPago::etiqueta((string) $venta['metodo_pago'])) ?> — <?= e($venta['estado_pago']) ?>
                <?php if ($venta['metodo_pago'] === MetodoPago::BRILLA): ?>
                    <br><span class="texto-suave">Recibo de gas: <?= e($venta['soporte_recibo_gas']) ?></span>
                <?php elseif ($venta['metodo_pago'] === MetodoPago::SERVICREDITO): ?>
                    <br><span class="texto-suave">Requisitos verificados: <?= $venta['requisitos_servicedrito_ok'] ? 'sí' : 'no' ?></span>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <?php if ($venta['motos']): ?>
        <h3>Motos</h3>
        <div class="tabla-desplazable">
            <table class="tabla">
                <thead><tr><th>Moto</th><th class="numero">Cant.</th><th class="numero">Precio unit.</th><th class="numero">Descuento</th><th class="numero">Total línea</th></tr></thead>
                <tbody>
                <?php foreach ($venta['motos'] as $m): ?>
                    <tr>
                        <td><?= e("{$m['marca']} {$m['modelo']}") ?><br><span class="texto-suave"><?= e(($m['gama'] ?? 'Sin gama') . ' · ' . propulsion($m['tipo_propulsion'])) ?></span></td>
                        <td class="numero"><?= (int) $m['cantidad'] ?></td>
                        <td class="numero"><?= e($c($m['precio_unitario'])) ?></td>
                        <td class="numero"><?= Dinero::aCentavos($m['descuento_aplicado']) > 0 ? '− ' . e($c($m['descuento_aplicado'])) : '—' ?></td>
                        <td class="numero"><?= e($c($m['precio_final'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($venta['productos']): ?>
        <h3>Repuestos e insumos</h3>
        <div class="tabla-desplazable">
            <table class="tabla">
                <thead><tr><th>Producto</th><th class="numero">Cant.</th><th class="numero">Precio unit.</th><th class="numero">Total línea</th></tr></thead>
                <tbody>
                <?php foreach ($venta['productos'] as $p): ?>
                    <tr>
                        <td><?= e($p['nombre']) ?><br><span class="texto-suave"><?= e($p['tipo']) ?></span></td>
                        <td class="numero"><?= (int) $p['cantidad'] ?></td>
                        <td class="numero"><?= e($c($p['precio_unitario'])) ?></td>
                        <td class="numero"><?= e($c($p['precio_final'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <dl class="totales">
        <?php if ($descuentoTotal > 0): ?>
            <dt>Descuentos por promoción</dt><dd>− <?= e(pesos($descuentoTotal)) ?></dd>
        <?php endif; ?>
        <dt class="total">Total</dt><dd class="total"><?= e($c($venta['total'])) ?></dd>
    </dl>
</article>
