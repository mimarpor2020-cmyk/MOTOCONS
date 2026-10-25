<?php
/** @var string $titulo  @var string $mensaje  @var string|null $detalle */
use Motocons\Core\Auth;
?>
<section class="tarjeta tarjeta-estrecha">
    <h1><?= e($titulo) ?></h1>
    <p><?= e($mensaje) ?></p>
    <?php if (!empty($detalle)): ?>
        <pre class="detalle-tecnico"><?= e($detalle) ?></pre>
    <?php endif; ?>
    <p class="acciones">
        <?php if (Auth::usuario() !== null): ?>
            <a class="boton" href="<?= e(url('ventas')) ?>">Volver a las ventas</a>
        <?php else: ?>
            <a class="boton" href="<?= e(url('login')) ?>">Ir a iniciar sesión</a>
        <?php endif; ?>
        <?php if (!empty($enlaceDiagnostico)): ?>
            <a class="boton boton-fantasma" href="<?= e(url('diagnostico')) ?>">Diagnóstico de la instalación</a>
        <?php endif; ?>
    </p>
</section>
