<?php
/** @var string $email  @var string|null $error */
use Motocons\Core\Csrf;
?>
<section class="tarjeta tarjeta-estrecha">
    <h1>Iniciar sesión</h1>
    <p class="texto-suave">Acceso para vendedores y administradores de la tienda.</p>

    <?php if (!empty($error)): ?>
        <div class="alerta alerta-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('login')) ?>" class="formulario" data-un-envio>
        <input type="hidden" name="csrf" value="<?= e(Csrf::token()) ?>">
        <label for="email">Correo electrónico</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="username" maxlength="100">

        <label for="clave">Contraseña</label>
        <input type="password" id="clave" name="clave" required autocomplete="current-password">

        <button type="submit" class="boton boton-primario boton-bloque">Entrar</button>
    </form>
</section>
