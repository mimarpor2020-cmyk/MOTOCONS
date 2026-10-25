<?php
/** @var string $contenido  @var string $titulo */
use Motocons\Core\Auth;
use Motocons\Core\Csrf;
use Motocons\Core\Sesion;
use Motocons\Identidad\Roles;

$usuario = Auth::usuario();
$mensajes = Sesion::tomarFlash();
$rutaActual = is_string($_GET['r'] ?? null) ? $_GET['r'] : 'ventas';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo ?? 'Ventas presenciales') ?> · MOTOCONS</title>
    <link rel="stylesheet" href="<?= e(recurso('assets/app.css')) ?>">
</head>
<body>
<header class="barra">
    <a class="marca" href="<?= e(url('ventas')) ?>">
        <span class="marca-nombre">MOTOCONS</span>
        <span class="marca-modulo">Ventas presenciales</span>
    </a>
    <?php if ($usuario !== null): ?>
        <nav class="menu" aria-label="Principal">
            <a href="<?= e(url('ventas')) ?>" class="<?= $rutaActual === 'ventas' ? 'activo' : '' ?>">Ventas</a>
            <?php if ($usuario['rol'] === Roles::VENDEDOR): ?>
                <a href="<?= e(url('ventas/nueva')) ?>" class="<?= $rutaActual === 'ventas/nueva' || $rutaActual === 'ventas/registrar' ? 'activo' : '' ?>">Nueva venta</a>
                <a href="<?= e(url('clientes/nuevo')) ?>" class="<?= str_starts_with($rutaActual, 'clientes') ? 'activo' : '' ?>">Nuevo cliente</a>
            <?php endif; ?>
        </nav>
        <div class="sesion">
            <span class="sesion-nombre"><?= e($usuario['nombre']) ?></span>
            <span class="etiqueta"><?= e($usuario['rol']) ?></span>
            <form method="post" action="<?= e(url('logout')) ?>">
                <input type="hidden" name="csrf" value="<?= e(Csrf::token()) ?>">
                <button type="submit" class="boton boton-fantasma">Salir</button>
            </form>
        </div>
    <?php endif; ?>
</header>

<main class="contenedor">
    <?php foreach ($mensajes as $mensaje): ?>
        <div class="alerta alerta-<?= e($mensaje['tipo']) ?>" role="status"><?= e($mensaje['mensaje']) ?></div>
    <?php endforeach; ?>
    <?= $contenido ?>
</main>

<footer class="pie">
    MOTOCONS · RF-02 Ventas presenciales · Universidad de Córdoba — Diplomado en desarrollo web en la nube
</footer>
<script src="<?= e(recurso('assets/app.js')) ?>" defer></script>
</body>
</html>
