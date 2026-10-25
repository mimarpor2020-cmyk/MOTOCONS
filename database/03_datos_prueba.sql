-- =====================================================================
-- MOTOCONS · RF-02 Ventas presenciales
-- Datos de prueba para demostrar el módulo en un entorno local.
--
-- Ejecutar después de 02_ajustes_rf02.sql sobre una base motocons_db VACÍA.
-- Los datos son ficticios. Las fechas de las promociones se calculan con
-- CURDATE() para que las promociones "vigentes" lo sigan siendo el día en
-- que se importe el script.
--
-- Contraseña de TODOS los empleados de prueba: Motocons2026*
-- (hash bcrypt generado con password_hash() de PHP)
-- =====================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- Empleados: un administrador, dos vendedores y un mecánico
-- (el mecánico permite comprobar que un rol sin permisos no accede al módulo).
INSERT INTO `empleado` (`id`, `nombre`, `email`, `password`, `rol`) VALUES
(1, 'Laura Gómez',  'admin@motocons.test',        '$2y$10$uTOmdTk6aS4VrpG9ep1zResW2gb4H2K2tBlznCPxRzVvGa8AfJB7O', 'Administrador'),
(2, 'Carlos Pérez', 'carlos.perez@motocons.test', '$2y$10$uTOmdTk6aS4VrpG9ep1zResW2gb4H2K2tBlznCPxRzVvGa8AfJB7O', 'Vendedor'),
(3, 'Ana Ruiz',     'ana.ruiz@motocons.test',     '$2y$10$uTOmdTk6aS4VrpG9ep1zResW2gb4H2K2tBlznCPxRzVvGa8AfJB7O', 'Vendedor'),
(4, 'Jorge Díaz',   'jorge.diaz@motocons.test',   '$2y$10$uTOmdTk6aS4VrpG9ep1zResW2gb4H2K2tBlznCPxRzVvGa8AfJB7O', 'Mecanico');

-- Clientes. La contraseña es el hash de un valor aleatorio descartado:
-- estos clientes no pueden iniciar sesión en el portal web (RF-01) hasta
-- que ese módulo les permita definir una contraseña.
INSERT INTO `cliente` (`id`, `nombre`, `email`, `password`, `telefono`, `direccion`) VALUES
(1, 'María Fernanda López', 'maria.lopez@correo.test',   '$2y$10$AzH1XJ/wOBQcTJvn.uavce2t1t7rSreDQhAlnzltWSriDZwBQEf4S', '3001234567', 'Calle 5 # 12-30, Lorica'),
(2, 'Andrés Felipe Torres', 'andres.torres@correo.test', '$2y$10$2GIM2IwDzfPMcoZaI3JqleCxX.RZKJlT2cWf4f29lRoHlZgPtLVtG', '3109876543', 'Carrera 20 # 8-15, Montería'),
(3, 'Luisa Martínez',       'luisa.martinez@correo.test','$2y$10$dgbXtUOjUJkjw6x2BKvNrusmSjnC55zNmIgMOJIr/SkiHxEauiuCa', NULL,         NULL);

-- Catálogo de motos: las tres gamas y los dos tipos de propulsión.
-- La Suzuki tiene stock 0 para probar el rechazo por inventario agotado.
INSERT INTO `moto` (`id`, `modelo`, `placa`, `marca`, `gama`, `tipo_propulsion`, `precio`, `stock`, `ficha_tecnica`) VALUES
(1, 'NKD 125',     NULL, 'AKT',        'Baja',  'Combustible', 5290000.00,  8, 'Motor 125 cc, 4 tiempos, freno de tambor.'),
(2, 'Pulsar NS 200', NULL, 'Bajaj',    'Media', 'Combustible', 11490000.00, 5, 'Motor 199,5 cc refrigerado por líquido, 6 velocidades.'),
(3, 'MT-03',       NULL, 'Yamaha',     'Alta',  'Combustible', 26990000.00, 2, 'Motor bicilíndrico 321 cc, ABS.'),
(4, 'CB 190R',     NULL, 'Honda',      'Media', 'Combustible', 12890000.00, 4, 'Motor 184 cc, inyección electrónica.'),
(5, 'Fly',         NULL, 'Starker',    'Baja',  'Electrica',   6490000.00,  3, 'Motor eléctrico 1,5 kW, autonomía 60 km.'),
(6, 'CPx',         NULL, 'Super Soco', 'Media', 'Electrica',   17990000.00, 1, 'Motor eléctrico 4 kW, dos baterías de litio.'),
(7, 'GSX-S750',    NULL, 'Suzuki',     'Alta',  'Combustible', 49900000.00, 0, 'Motor de 4 cilindros 749 cc.');

-- Repuestos e insumos para venta en mostrador (detalle_venta_producto).
-- El líquido de frenos tiene stock 2 para probar el control de inventario.
INSERT INTO `producto` (`id`, `nombre`, `tipo`, `precio_unitario`, `stock`, `descripcion`) VALUES
(1, 'Aceite 20W-50 (1 L)',   'insumo',   38000.00,  40, 'Aceite mineral para motor de 4 tiempos.'),
(2, 'Filtro de aceite',      'repuesto', 25000.00,  25, 'Compatible con motores de 125 a 200 cc.'),
(3, 'Kit de arrastre',       'repuesto', 185000.00, 10, 'Cadena, piñón y corona.'),
(4, 'Pastillas de freno',    'repuesto', 42000.00,  15, 'Juego delantero.'),
(5, 'Líquido de frenos DOT4','insumo',   22000.00,   2, 'Envase de 250 ml.');

-- Promociones (RF-07). Permiten comprobar que la venta aplica la mejor
-- promoción vigente y que una promoción vencida no se aplica.
--  · Pulsar NS 200 y CB 190R (Media, Combustible): aplican la 1 (10 %) y la 5 (7 %) → se usa 10 %.
--  · Super Soco CPx (Media, Eléctrica): aplican la 1 (10 %) y la 2 (12,5 %) → se usa 12,5 %.
--  · Starker Fly (Baja, Eléctrica): aplica la 2 (12,5 %).
--  · AKT NKD 125: aplica la 3 (5 %, promoción de una moto específica).
--  · Yamaha MT-03 y Suzuki (Alta): la 4 está vencida → sin descuento.
INSERT INTO `promocion` (`id`, `nombre`, `porcentaje_descuento`, `gama_aplicable`, `tipo_propulsion_aplicable`, `moto_id`, `fecha_inicio`, `fecha_fin`) VALUES
(1, 'Temporada gama media',      10.00, 'Media', NULL,          NULL, CURDATE() - INTERVAL 5 DAY,  CURDATE() + INTERVAL 25 DAY),
(2, 'Semana eléctrica',          12.50, NULL,    'Electrica',   NULL, CURDATE() - INTERVAL 1 DAY,  CURDATE() + INTERVAL 6 DAY),
(3, 'Lanzamiento NKD 125',        5.00, NULL,    NULL,          1,    CURDATE(),                   CURDATE() + INTERVAL 30 DAY),
(4, 'Gama alta (vencida)',       15.00, 'Alta',  NULL,          NULL, CURDATE() - INTERVAL 40 DAY, CURDATE() - INTERVAL 10 DAY),
(5, 'Combustión gama media',      7.00, 'Media', 'Combustible', NULL, CURDATE() - INTERVAL 2 DAY,  CURDATE() + INTERVAL 10 DAY);

COMMIT;
