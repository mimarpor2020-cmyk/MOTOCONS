<?php
/** @var array $chequeos  @var bool $correcto */
?>
<section class="tarjeta">
    <h1>Diagnóstico de la instalación</h1>
    <p class="texto-suave">Comprueba que PHP, MySQL/MariaDB y la base <code>motocons_db</code> estén listos para el módulo RF-02.</p>

    <div class="alerta <?= $correcto ? 'alerta-exito' : 'alerta-error' ?>">
        <?= $correcto ? 'Todo está correcto: el módulo puede usarse.' : 'Hay comprobaciones fallidas. Revise el detalle de cada una.' ?>
    </div>

    <table class="tabla">
        <thead><tr><th>Comprobación</th><th>Resultado</th><th>Detalle</th></tr></thead>
        <tbody>
        <?php foreach ($chequeos as $chequeo): ?>
            <tr>
                <td><?= e($chequeo['nombre']) ?></td>
                <td><span class="estado <?= $chequeo['ok'] ? 'estado-ok' : 'estado-fallo' ?>"><?= $chequeo['ok'] ? 'OK' : 'FALLA' ?></span></td>
                <td><?= e($chequeo['detalle']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="acciones"><a class="boton" href="<?= e(url('login')) ?>">Ir al módulo</a></p>
</section>
