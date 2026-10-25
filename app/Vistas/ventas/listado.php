<?php
/**
 * Listado de ventas presenciales con filtros por moto, cliente y forma de pago (HU-05).
 *
 * @var array $ventas  @var int $totalRegistros  @var int $pagina  @var int $paginas
 * @var array $filtros  @var array $motos  @var array $vendedores  @var bool $esAdministrador
 */
use Motocons\Core\Dinero;
use Motocons\VentasPresenciales\MetodoPago;

$parametrosFiltro = array_filter([
    'moto_id'     => $filtros['moto_id'],
    'cliente'     => $filtros['cliente'],
    'metodo_pago' => $filtros['metodo_pago'],
    'empleado_id' => $filtros['empleado_id'],
], static fn ($v) => $v !== null && $v !== '');
?>
<div class="encabezado-pagina">
    <h1><?= e($titulo) ?></h1>
    <?php if (!$esAdministrador): ?>
        <a class="boton boton-primario" href="<?= e(url('ventas/nueva')) ?>">+ Registrar venta</a>
    <?php endif; ?>
</div>

<form method="get" action="<?= e(url()) ?>" class="tarjeta filtros">
    <input type="hidden" name="r" value="ventas">
    <div class="campo">
        <label for="f-moto">Moto</label>
        <select id="f-moto" name="moto_id">
            <option value="">Todas</option>
            <?php foreach ($motos as $m): ?>
                <option value="<?= (int) $m['id'] ?>" <?= $filtros['moto_id'] === (int) $m['id'] ? 'selected' : '' ?>><?= e("{$m['marca']} {$m['modelo']}") ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="campo">
        <label for="f-cliente">Cliente</label>
        <input id="f-cliente" name="cliente" type="search" value="<?= e($filtros['cliente']) ?>" placeholder="Nombre o correo" maxlength="100">
    </div>
    <div class="campo">
        <label for="f-metodo">Forma de pago</label>
        <select id="f-metodo" name="metodo_pago">
            <option value="">Todas</option>
            <?php foreach (MetodoPago::ETIQUETAS as $valor => $etiqueta): ?>
                <option value="<?= e($valor) ?>" <?= $filtros['metodo_pago'] === $valor ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if ($esAdministrador): ?>
        <div class="campo">
            <label for="f-vendedor">Vendedor</label>
            <select id="f-vendedor" name="empleado_id">
                <option value="">Todos</option>
                <?php foreach ($vendedores as $v): ?>
                    <option value="<?= (int) $v['id'] ?>" <?= $filtros['empleado_id'] === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <div class="campo campo-acciones">
        <button type="submit" class="boton boton-primario">Filtrar</button>
        <a class="boton boton-fantasma" href="<?= e(url('ventas')) ?>">Limpiar</a>
    </div>
</form>

<p class="texto-suave"><?= $totalRegistros ?> venta(s) encontrada(s)<?= $parametrosFiltro ? ' con los filtros aplicados' : '' ?>.</p>

<?php if ($ventas === []): ?>
    <div class="tarjeta vacio">
        <p>No hay ventas presenciales que coincidan.</p>
    </div>
<?php else: ?>
    <div class="tabla-desplazable tarjeta sin-relleno">
        <table class="tabla">
            <thead>
            <tr>
                <th>N.º</th><th>Fecha</th><th>Cliente</th>
                <?php if ($esAdministrador): ?><th>Vendedor</th><?php endif; ?>
                <th>Motos</th><th>Forma de pago</th><th class="numero">Total</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($ventas as $v): ?>
                <tr>
                    <td>#<?= (int) $v['id'] ?></td>
                    <td class="sin-salto"><?= e(fecha_hora($v['fecha_venta'])) ?></td>
                    <td><?= e($v['cliente']) ?><br><span class="texto-suave"><?= e($v['cliente_email']) ?></span></td>
                    <?php if ($esAdministrador): ?><td><?= e($v['vendedor']) ?></td><?php endif; ?>
                    <td>
                        <?= $v['motos'] !== null ? e($v['motos']) : '<span class="texto-suave">—</span>' ?>
                        <?php if ((int) $v['unidades_producto'] > 0): ?>
                            <br><span class="texto-suave">+ <?= (int) $v['unidades_producto'] ?> repuesto(s)/insumo(s)</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e(MetodoPago::etiqueta((string) $v['metodo_pago'])) ?><br><span class="texto-suave"><?= e($v['estado_pago']) ?></span></td>
                    <td class="numero sin-salto"><?= e(pesos(Dinero::aCentavos($v['total']))) ?></td>
                    <td><a class="boton boton-pequeno" href="<?= e(url('ventas/ver', ['id' => $v['id']])) ?>">Ver</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($paginas > 1): ?>
        <nav class="paginacion" aria-label="Páginas">
            <?php if ($pagina > 1): ?>
                <a class="boton boton-pequeno" href="<?= e(url('ventas', $parametrosFiltro + ['pagina' => $pagina - 1])) ?>">← Anterior</a>
            <?php endif; ?>
            <span>Página <?= $pagina ?> de <?= $paginas ?></span>
            <?php if ($pagina < $paginas): ?>
                <a class="boton boton-pequeno" href="<?= e(url('ventas', $parametrosFiltro + ['pagina' => $pagina + 1])) ?>">Siguiente →</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
