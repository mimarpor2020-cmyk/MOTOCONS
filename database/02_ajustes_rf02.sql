-- =====================================================================
-- MOTOCONS · RF-02 Ventas presenciales
-- Ajustes al esquema oficial (01_motocons_db.sql).
--
-- Ejecutar UNA sola vez, después de 01_motocons_db.sql y sobre la base
-- motocons_db. No elimina tablas ni datos. Cada ajuste se justifica en
-- docs/RF-02_documentacion.md (sección "Decisiones ante ambigüedades").
-- Probado en MariaDB 10.4.32 (XAMPP 8.2.12).
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- A1. La fecha de una venta no debe cambiar cuando se actualiza la fila.
--     El script original define ON UPDATE current_timestamp(), con lo que
--     cualquier UPDATE sobre `venta` reescribiría la fecha de la venta.
-- ---------------------------------------------------------------------
ALTER TABLE `venta`
  MODIFY `fecha_venta` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- ---------------------------------------------------------------------
-- A2. Alinear el dominio de promocion.tipo_propulsion_aplicable con
--     moto.tipo_propulsion. El MR (sección 2.1) define 'Combustible',
--     pero el script usa 'Combustion', por lo que una promoción para motos
--     de combustión nunca coincidiría con ninguna moto. Se migra en tres
--     pasos para conservar filas existentes, si las hubiera.
-- ---------------------------------------------------------------------
ALTER TABLE `promocion`
  MODIFY `tipo_propulsion_aplicable` ENUM('Combustion','Combustible','Electrica') DEFAULT NULL;
UPDATE `promocion`
  SET `tipo_propulsion_aplicable` = 'Combustible'
  WHERE `tipo_propulsion_aplicable` = 'Combustion';
ALTER TABLE `promocion`
  MODIFY `tipo_propulsion_aplicable` ENUM('Combustible','Electrica') DEFAULT NULL;

-- ---------------------------------------------------------------------
-- A3. Reglas de integridad en la base de datos (defensa en profundidad:
--     la aplicación ya las valida, pero la base las garantiza aunque
--     otro módulo o un error de código intente violarlas).
-- ---------------------------------------------------------------------

-- Regla de negocio de RF-02: toda venta física queda asociada a un vendedor.
ALTER TABLE `venta`
  ADD CONSTRAINT `chk_venta_tipo`               CHECK (`tipo_venta` IN ('web','fisica')),
  ADD CONSTRAINT `chk_venta_fisica_con_vendedor` CHECK (`tipo_venta` <> 'fisica' OR `empleado_id` IS NOT NULL),
  ADD CONSTRAINT `chk_venta_total`              CHECK (`total` >= 0);

ALTER TABLE `detalle_venta`
  ADD CONSTRAINT `chk_dv_cantidad`  CHECK (`cantidad` > 0),
  ADD CONSTRAINT `chk_dv_valores`   CHECK (`precio_unitario` >= 0 AND `descuento_aplicado` >= 0 AND `precio_final` >= 0);

ALTER TABLE `detalle_venta_producto`
  ADD CONSTRAINT `chk_dvp_cantidad` CHECK (`cantidad` > 0),
  ADD CONSTRAINT `chk_dvp_valores`  CHECK (`precio_unitario` >= 0 AND `precio_final` >= 0);

-- Valores de metodo_pago definidos en el MR (se conserva la grafía
-- 'servicedrito' del documento para que todos los módulos usen el mismo valor).
-- Regla de RF-03 / MR: con Crédito Brilla el soporte del recibo de gas es obligatorio.
ALTER TABLE `transaccion_pago`
  ADD CONSTRAINT `chk_pago_metodo`        CHECK (`metodo_pago` IN ('contado','tarjeta','servicedrito','brilla')),
  ADD CONSTRAINT `chk_pago_brilla_soporte` CHECK (`metodo_pago` <> 'brilla' OR (`soporte_recibo_gas` IS NOT NULL AND `soporte_recibo_gas` <> ''));

-- El inventario nunca puede quedar negativo (impide sobreventa).
ALTER TABLE `moto`
  ADD CONSTRAINT `chk_moto_stock`     CHECK (`stock` >= 0);
ALTER TABLE `producto`
  ADD CONSTRAINT `chk_producto_stock` CHECK (`stock` >= 0);

-- ---------------------------------------------------------------------
-- A4. Índices para los filtros del listado de ventas (HU-05).
--     detalle_venta(moto_id) y venta(cliente_id, empleado_id) ya existen.
-- ---------------------------------------------------------------------
ALTER TABLE `venta`
  ADD KEY `idx_venta_tipo_fecha` (`tipo_venta`, `fecha_venta`);
ALTER TABLE `transaccion_pago`
  ADD KEY `idx_pago_metodo` (`metodo_pago`);
