<?php
/**
 * Configuración de MOTOCONS · RF-02 Ventas presenciales.
 *
 * Ajuste estos valores a su instalación de XAMPP. Los valores por defecto
 * corresponden a una instalación de XAMPP sin modificar (MySQL/MariaDB en
 * 127.0.0.1:3306, usuario root sin contraseña).
 */
return [
    'app' => [
        // Zona horaria de la tienda: se usa para las fechas y la vigencia de promociones.
        'zona_horaria'        => 'America/Bogota',
        // true solo mientras desarrolla: muestra el detalle técnico de los errores en pantalla.
        'debug'               => false,
        // Minutos sin actividad tras los cuales la sesión se cierra.
        'minutos_inactividad' => 30,
    ],
    'db' => [
        'host'    => '127.0.0.1',
        'puerto'  => 3306,          // cámbielo si en XAMPP configuró otro puerto para MySQL
        'nombre'  => 'motocons_db',
        'usuario' => 'root',
        'clave'   => '',            // escriba aquí la contraseña si le asignó una a root
        // Desfase horario de la sesión SQL: Colombia es UTC-5 todo el año.
        'zona_horaria_sesion' => '-05:00',
    ],
];
