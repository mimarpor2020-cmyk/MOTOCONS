<?php
/** @var array $entrada  @var array $errores */
use Motocons\Core\Csrf;

$valor = static fn (string $campo): string => e($entrada[$campo] ?? '');
$claseError = static fn (string $campo): string => isset($errores[$campo]) ? 'con-error' : '';
?>
<section class="tarjeta tarjeta-media">
    <h1>Registrar cliente</h1>
    <p class="texto-suave">Para clientes que compran en la tienda y aún no están registrados. Al guardar, vuelve a la venta con el cliente seleccionado.</p>

    <?php if ($errores): ?>
        <div class="alerta alerta-error" role="alert">
            <strong>Revise los datos:</strong>
            <ul><?php foreach ($errores as $mensaje): ?><li><?= e($mensaje) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('clientes/crear')) ?>" class="formulario" data-un-envio>
        <input type="hidden" name="csrf" value="<?= e(Csrf::token()) ?>">

        <label for="nombre">Nombre completo *</label>
        <input id="nombre" name="nombre" value="<?= $valor('nombre') ?>" required maxlength="100" class="<?= $claseError('nombre') ?>">

        <label for="email">Correo electrónico *</label>
        <input id="email" name="email" type="email" value="<?= $valor('email') ?>" required maxlength="100" class="<?= $claseError('email') ?>">

        <label for="telefono">Teléfono</label>
        <input id="telefono" name="telefono" value="<?= $valor('telefono') ?>" maxlength="20" inputmode="tel" class="<?= $claseError('telefono') ?>">

        <label for="direccion">Dirección</label>
        <input id="direccion" name="direccion" value="<?= $valor('direccion') ?>" maxlength="255" class="<?= $claseError('direccion') ?>">

        <div class="acciones">
            <button type="submit" class="boton boton-primario">Guardar cliente</button>
            <a class="boton boton-fantasma" href="<?= e(url('ventas/nueva')) ?>">Cancelar</a>
        </div>
    </form>
</section>
