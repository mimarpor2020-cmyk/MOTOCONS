# RF-02 Ventas presenciales — Documentación técnica

Proyecto MOTOCONS · Diplomado en desarrollo web en la nube · Universidad de Córdoba
Fecha: 24 de octubre de 2026 · Entorno de desarrollo y pruebas: XAMPP 8.2.12 (Apache, PHP 8.2.12, MariaDB 10.4.32) en Windows 11.

---

## 1. Resumen

El módulo permite que un **vendedor** autenticado registre una venta asistida en la tienda física y que esa venta quede **asociada a su nombre**, que es la regla de negocio de RF-02. Una venta puede incluir motos y también repuestos o insumos vendidos en mostrador. La forma de pago es una de las cuatro del modelo. El sistema aplica automáticamente la mejor promoción vigente y descuenta el inventario. El vendedor consulta sus ventas filtrando por **moto, cliente y forma de pago** (HU-05) y ve el comprobante de cada una. El **administrador** consulta todas las ventas presenciales.

Todo cálculo y toda regla se resuelven en el servidor, dentro de una transacción de base de datos con bloqueo de filas. La base de datos refuerza las reglas críticas con restricciones `CHECK`. Hay 36 pruebas unitarias y de integración y 19 pruebas HTTP, todas ejecutadas con resultado correcto (sección 8).

---

## 2. Análisis de la documentación

### 2.1 Fuentes revisadas

| Documento | Aporte para RF-02 |
|---|---|
| `Requerimientos_Doc_base.docx` | Entrevista con el dueño, tabla de RF-01 a RF-10, actores, historias de usuario HU-01 a HU-16. |
| `MER_MR.pdf` | Modelo entidad-relación, cardinalidades, modelo relacional con nulabilidad y valores permitidos, y la matriz de tablas frente a HU y RF. |
| `motocons_db.sql` | Script físico oficial: 15 tablas para MariaDB 10.4. |
| `Informe_Analisis_Arquitectura_Motocicletas.docx` | Arquitectura recomendada (monolito modular), reglas de consistencia (transacciones, `FOR UPDATE`, idempotencia), seguridad y ajustes sugeridos al modelo (Anexo B). |

### 2.2 Respuestas del análisis

**1. Operaciones del módulo.** RF-02 dice: «registrar y gestionar ventas asistidas en la tienda física». HU-05 agrega: «la venta queda asociada al vendedor que la registra» y «puedo filtrar mediante moto, el cliente y la forma de pago». Se implementan:
- Registrar una venta presencial: cliente, motos, repuestos e insumos, y forma de pago.
- Listar las ventas presenciales con filtros por moto, cliente y forma de pago.
- Consultar el detalle o comprobante de una venta.
- Registro rápido de un cliente nuevo. Es una dependencia mínima, porque `venta.cliente_id` es obligatorio (decisión D8).

**2. Usuarios y permisos.** Los roles del MR son `Administrador`, `Vendedor` y `Mecanico`. El vendedor registra ventas y consulta las suyas. El administrador consulta todas las ventas presenciales (HU-14 y HU-16 le asignan la revisión por vendedor). El mecánico no tiene acceso. Ver la decisión D3.

**3. Datos registrados y consultados.**
- `venta`: `tipo_venta = 'fisica'`, `cliente_id`, `empleado_id` (el vendedor), `fecha_venta` y `total`.
- `detalle_venta` (motos): `cantidad`, `precio_unitario`, `descuento_aplicado` y `precio_final`.
- `detalle_venta_producto` (mostrador): `cantidad`, `precio_unitario` y `precio_final`.
- `transaccion_pago` (1:1 con la venta): `metodo_pago`, `estado_pago`, `soporte_recibo_gas` y `requisitos_servicedrito_ok`.
- Se consultan `cliente`, `empleado`, `moto`, `producto` y `promocion`.

**4. Reglas de negocio y validaciones.**

| Id | Regla | Origen |
|---|---|---|
| R1 | La venta física queda asociada al vendedor que la registra. | RF-02 (regla), HU-05 |
| R2 | Solo un empleado con rol Vendedor registra ventas presenciales. | RF-02 («permitir a los vendedores») |
| R3 | Una venta tiene al menos un artículo; las cantidades son enteros positivos. | MR (`cantidad` NOT NULL), integridad |
| R4 | No se vende más que el stock disponible; el stock se descuenta. | Integridad con el inventario (RF-08, Informe §9.2) |
| R5 | Los precios y descuentos se calculan en el servidor con la promoción vigente. | RF-07 («aplicación automática según el rango de fechas activo») |
| R6 | La forma de pago es una de: contado, tarjeta, ServiCrédito o Brilla. Brilla exige el recibo de gas validado; ServiCrédito exige cumplir sus requisitos. | RF-03, HU-06, MR `transaccion_pago` |
| R7 | Venta, líneas, pago e inventario se guardan de forma atómica. | Informe §9.1 |

**5. Entidades y relaciones.** `cliente 1—N venta`, `empleado 1—N venta` (nulo solo en ventas web), `venta 1—N detalle_venta N—1 moto`, `venta 1—N detalle_venta_producto N—1 producto`, `venta 1—1 transaccion_pago`, `moto 1—N promocion`. No hacen falta tablas nuevas. Los ajustes al esquema se explican en la sección 4.3.

**6. Dependencias con otros módulos.**

| Módulo | Relación con RF-02 | Cómo se resuelve |
|---|---|---|
| RF-04 Catálogo / RF-08 Inventario | RF-02 lee motos y productos y descuenta stock. | Lectura y descuento de `moto.stock` y `producto.stock`. Las altas y modificaciones del catálogo son de RF-04/RF-08: el catálogo se carga con `03_datos_prueba.sql`. |
| RF-07 Promociones | El precio de la venta depende de la promoción vigente. | Solo lectura de `promocion`. Las promociones de prueba se cargan por SQL con fechas relativas a `CURDATE()`. |
| RF-03 Pagos | Cada venta tiene su `transaccion_pago`. | RF-02 crea el registro y aplica las validaciones mínimas de HU-06. La conciliación de pagos y desembolsos queda en RF-03/RF-09. |
| RF-01 Ventas web | Comparte `venta` y `cliente`. | RF-02 solo crea y muestra ventas con `tipo_venta = 'fisica'`. |
| RF-09 Cierres / RF-10 Comisiones | Consumen las ventas por vendedor. | RF-02 deja `empleado_id` garantizado, que es la base de esos cálculos. No los implementa. |
| Gestión de empleados | Usuarios del sistema. | Los empleados se cargan por SQL con contraseñas bcrypt. |

Estos datos de prueba **no se presentan como módulos terminados**: son registros cargados por script para poder probar RF-02 de forma controlada.

**7. Criterios de aceptación** (verificados en la sección 7):

- CA-1: Un vendedor autenticado registra una venta física y en la base queda `empleado_id` igual a su id, aunque el formulario envíe otro.
- CA-2: El listado filtra por moto, por cliente y por forma de pago, y esos filtros se pueden combinar.
- CA-3: Un vendedor solo ve y abre sus ventas; el administrador ve todas; el mecánico no accede.
- CA-4: El total es la suma de las líneas, con la promoción vigente de mayor porcentaje, sin importar lo que envíe el navegador.
- CA-5: Una venta que excede el stock se rechaza completa y no modifica nada; dos ventas simultáneas de la última unidad no generan sobreventa.
- CA-6: Una venta con Brilla no se cierra sin el recibo de gas validado, y una con ServiCrédito no se cierra sin confirmar los requisitos.
- CA-7: Un doble clic o un reenvío del formulario no duplica la venta.

---

## 3. Contradicciones, ambigüedades y decisiones

### 3.1 Contradicciones encontradas

| # | Contradicción | Implicación | Resolución |
|---|---|---|---|
| C1 | `promocion.tipo_propulsion_aplicable` es `ENUM('Combustion','Electrica')` en el script, pero el MR (pág. 9) dice `'Combustible'`, igual que `moto.tipo_propulsion`. | Una promoción para motos de combustión nunca coincidiría con ninguna moto. RF-07 fallaría en silencio. | Se alinea al MR con `'Combustible'` en `02_ajustes_rf02.sql`, migrando valores existentes sin perder filas. |
| C2 | `venta.fecha_venta` tiene `ON UPDATE current_timestamp()`. | Cualquier `UPDATE` futuro de una venta (por ejemplo, de RF-09) reescribiría la fecha de la venta y alteraría los cierres semanales. | Se elimina el `ON UPDATE` (ajuste A1). La prueba «La fecha de la venta no cambia» lo verifica. |
| C3 | El MR define el valor `servicedrito` (y la columna `requisitos_servicedrito_ok`), un error de escritura de «ServiCrédito». | Si cada módulo «corrige» el valor a su manera, los filtros y cierres no coincidirán. | Se conserva literalmente el valor del MR, que es el contrato entre módulos. Al usuario se le muestra «ServiCrédito». |
| C4 | `cliente.email` y `cliente.password` son NOT NULL (pensados para el portal web), pero en la tienda llegan clientes sin cuenta. No hay campo de cédula. | No se puede registrar la venta a un cliente nuevo sin crear su cuenta. | Registro mínimo con correo obligatorio y contraseña = hash de un valor aleatorio, que no permite iniciar sesión (D8). Búsqueda por nombre, correo o teléfono. |
| C5 | El Informe recomienda Laravel; el entorno pedido es XAMPP con PHP y PDO. | Laravel exige Composer y más configuración local. | PHP nativo, que el Informe admite («las dos tecnologías permitidas») siguiendo su guía para PHP nativo (D1). |
| C6 | `moto` es una **referencia** con `stock`, pero tiene `placa`, que es un dato de cada unidad. El Informe (Anexo B) propone unidades por VIN. | No se puede saber qué unidad física se vendió. | Se respeta el MR oficial (stock por referencia). La tabla de unidades queda como mejora (D7). |
| C7 | HU-04 (web) dice «adjuntar el recibo de gas»; HU-06 dice «recibo de gas validado»; el Informe (§9.3) recomienda no almacenar fotos de facturas. | No está claro si se guarda un archivo o una referencia. | En venta presencial el vendedor ve el recibo original: se guarda su **referencia** (número de factura o contrato) en `soporte_recibo_gas` y una confirmación de validación (D4). |
| C8 | El Informe propone estados de pedido y nuevos roles (cajero, tesorería). El MR no tiene estado en `venta` y solo define tres roles. | No hay dónde guardar estados del pedido ni a quién asignar esos roles. | Se usa el modelo oficial: RF-02 registra **ventas cerradas** y se usan solo los tres roles del MR. |

### 3.2 Decisiones de diseño

| Id | Decisión | Justificación |
|---|---|---|
| D1 | **PHP 8 nativo sin framework ni Composer**, con controlador frontal, PDO y organización por módulos. | Corre en cualquier XAMPP sin instalar nada más y cada pieza se puede explicar en la sustentación. Sigue la recomendación del Informe para PHP nativo: un solo `public/index.php`, PDO con consultas preparadas, transacciones explícitas y separación por módulos. |
| D2 | «Gestionar» se interpreta como **registrar, listar con filtros y consultar el detalle**. No se implementa edición ni anulación. | Son las operaciones que describe HU-05. Editar una venta cerrada alteraría inventario, pagos y comisiones sin reglas definidas. La anulación (EN_REVERSION en el Informe) requiere reglas de reverso que no están especificadas. |
| D3 | Vendedor: registra y ve **solo sus** ventas. Administrador: ve todas y filtra por vendedor, pero **no** registra. Mecánico: sin acceso. | «Asociar la venta al vendedor correspondiente» implica que quien registra es un vendedor; si registrara el administrador, la venta quedaría asociada a alguien que no vende. Se aplica el principio de mínimo privilegio. |
| D4 | Forma de pago: Brilla exige referencia del recibo de gas **y** marcar «Verifiqué el recibo». ServiCrédito exige marcar «cumple los requisitos y fue aprobado». `estado_pago` = `Pagado` (contado, tarjeta) o `Aprobado` (créditos). | HU-06: «no se puede cerrar una venta con Crédito Brilla sin el recibo de gas validado». Los requisitos concretos de ServiCrédito no están documentados (RF-03 dice «tiene sus propios requisitos»), por eso se pide una confirmación explícita. Con «Aprobado» se distingue un crédito aprobado de dinero recibido, como recomienda el Informe (§4.4.1). |
| D5 | Promociones: aplica una promoción si coinciden **todos** los criterios que define (moto, gama, propulsión; NULL no restringe) y la fecha de la venta está entre `fecha_inicio` y `fecha_fin`, ambas incluidas. Si aplican varias, se usa la de **mayor porcentaje** (no se acumulan). Se ignoran porcentajes ≤ 0 o > 100. Solo aplican a motos. | El MR describe los NULL como «aplica a todos». La acumulación no está especificada y sumar descuentos podría superar el 100 %. La tabla `promocion` solo referencia motos. |
| D6 | En `detalle_venta`: `precio_unitario` = precio de lista de una unidad; `descuento_aplicado` = descuento **total de la línea**; `precio_final` = **total de la línea** (precio × cantidad − descuento). `venta.total` = suma de `precio_final` de motos y productos. | El MR no define si los campos son por unidad o por línea. Con esta convención el total se obtiene sumando las líneas y el comprobante muestra cuánto se descontó. |
| D7 | El inventario se maneja con `moto.stock` (cantidad por referencia), sin VIN. | Es lo que define el modelo oficial. Agregar unidades por VIN cambiaría el esquema de otros módulos (RF-05, RF-08). |
| D8 | Registro mínimo de cliente desde la venta: nombre, correo (único), teléfono y dirección opcionales. La contraseña es un hash bcrypt de un valor aleatorio. | Es necesario para que la venta tenga cliente. No es un CRUD de clientes: no edita ni elimina. |
| D9 | Ajustes de esquema en un script aparte (`02_ajustes_rf02.sql`): C1, C2, restricciones `CHECK` e índices de los filtros. El script oficial no se modifica. | Se ve claramente qué cambió y por qué. Las restricciones protegen los datos aunque otro módulo o un error de código intente violarlas. |
| D10 | Zona horaria `America/Bogota` en PHP y `-05:00` en la sesión SQL. | XAMPP trae `Europe/Berlin` en `php.ini`. Sin este ajuste, las fechas y la vigencia de las promociones quedarían desfasadas 6–7 horas. |
| D11 | La venta presencial incluye repuestos e insumos de mostrador (opcionales). | El MR modela `detalle_venta_producto` como «repuestos e insumos vendidos en mostrador» asociados a `venta` (relación *incluye_mostrador*). |
| D12 | Token de un solo uso por formulario de venta. | Evita ventas duplicadas por doble clic, recarga o botón «atrás». Es el equivalente simple de la clave de idempotencia del Informe (§4.1.2). |
| D13 | Máximo 50 unidades por línea. | Protege contra errores de digitación (por ejemplo, 1000 en vez de 1). Es una constante (`MAX_CANTIDAD_POR_LINEA`) fácil de ajustar. |

---

## 4. Diseño de la solución

### 4.1 Arquitectura

Es un monolito por capas con un controlador frontal, sin capas de más:

```
Navegador ──HTTP──► public/index.php  (controlador frontal: cabeceras de seguridad, sesión, CSRF, rutas, errores)
                        │
                        ▼
                Controladores  (VentaController, ClienteController, AuthController)
                  · leen la petición, verifican el rol, eligen la vista
                        │
                        ▼
                Casos de uso / dominio  (RegistrarVentaPresencial, RegistrarCliente, CalculadoraPrecios, MetodoPago)
                  · reglas de negocio, validaciones, transacción
                        │
                        ▼
                Repositorios  (VentaRepositorio, CatalogoRepositorio, ClienteRepositorio, EmpleadoRepositorio)
                  · SQL con PDO y consultas preparadas
                        │
                        ▼
                MariaDB  motocons_db  (+ restricciones CHECK de 02_ajustes_rf02.sql)
```

- **Interfaz**: `app/Vistas/*.php` (plantillas que solo presentan datos, con todo escapado mediante `e()`), `public/assets/app.css` y `public/assets/app.js`.
- **Lógica de negocio**: `app/VentasPresenciales/RegistrarVentaPresencial.php`, `CalculadoraPrecios.php` y `MetodoPago.php`.
- **Acceso a datos**: `*Repositorio.php` (único lugar con SQL).

Las rutas usan el parámetro `?r=` (por ejemplo `index.php?r=ventas/nueva`) para no depender de `mod_rewrite`.

| Método y ruta | Acción | Rol |
|---|---|---|
| `GET login` / `POST login` / `POST logout` | Inicio y cierre de sesión | — |
| `GET ventas` | Listado con filtros y paginación | Vendedor, Administrador |
| `GET ventas/nueva` | Formulario de venta | Vendedor |
| `POST ventas/registrar` | Registrar venta | Vendedor |
| `GET ventas/ver&id=N` | Comprobante | Vendedor (propia), Administrador |
| `GET clientes/nuevo` / `POST clientes/crear` | Registro rápido de cliente | Vendedor |
| `GET diagnostico` | Comprobación de la instalación | Solo desde localhost |

### 4.2 Flujo del registro de una venta

```
VentaController::registrar()
 1. Auth::exigirRol(Vendedor)                      → 403 si no es vendedor
 2. Csrf::consumirTokenFormulario('venta')         → si ya se usó: aviso, sin registrar (doble envío)
 3. RegistrarVentaPresencial::ejecutar(id_de_la_sesión, $_POST, hoy)
      a. validarEntrada(): cliente, líneas (agrupa repetidas), forma de pago, requisitos Brilla/ServiCrédito
         └─ si hay errores: revisa también cliente e inventario (sin bloquear) → ErrorValidacion (422)
      b. BEGIN
      c. el empleado existe y es Vendedor
      d. SELECT … FROM moto / producto WHERE id IN (…) ORDER BY id FOR UPDATE   ← bloqueo de filas
      e. cliente existe, artículos existen, stock suficiente  → si no: ROLLBACK + ErrorValidacion
      f. promociones vigentes → CalculadoraPrecios (centavos enteros)
      g. INSERT venta (tipo 'fisica', empleado = vendedor de la sesión, total)
      h. INSERT detalle_venta / detalle_venta_producto  +  UPDATE stock = stock − n WHERE stock >= n
      i. INSERT transaccion_pago
      j. COMMIT            (interbloqueo 1213 / espera 1205 → ROLLBACK y reintento, máx. 3)
 4. Redirección al comprobante (patrón POST/Redirect/GET)
```

### 4.3 Base de datos

No se crean tablas nuevas. `02_ajustes_rf02.sql` aplica:

| Ajuste | Contenido |
|---|---|
| A1 | `venta.fecha_venta` sin `ON UPDATE` (C2). |
| A2 | `promocion.tipo_propulsion_aplicable` = `ENUM('Combustible','Electrica')` (C1). |
| A3 | `CHECK`: `chk_venta_fisica_con_vendedor` (`tipo_venta <> 'fisica' OR empleado_id IS NOT NULL`) es la regla de RF-02 en la base. También `chk_venta_tipo`, `chk_venta_total`, cantidades > 0 y valores ≥ 0 en los detalles, `chk_pago_metodo` (valores del MR), `chk_pago_brilla_soporte` (Brilla exige soporte) y `stock >= 0` en `moto` y `producto`. |
| A4 | Índices `venta(tipo_venta, fecha_venta)` y `transaccion_pago(metodo_pago)` para los filtros. |

---

## 5. Componentes principales

| Archivo | Responsabilidad |
|---|---|
| `public/index.php` | Único punto de entrada. Envía cabeceras de seguridad (CSP sin scripts en línea, `X-Frame-Options`, `nosniff`), inicia la sesión, valida el token CSRF en todo POST, despacha la ruta y convierte excepciones en páginas 403, 404, 500 o 503. Registra los errores en `storage/logs/app.log`. |
| `app/Core/Database.php` | Crea la conexión PDO: excepciones activadas, `EMULATE_PREPARES = false` (consultas preparadas reales en el servidor), `utf8mb4` y zona horaria de la sesión SQL. |
| `app/Core/Auth.php` | Usuario en sesión, `session_regenerate_id` al iniciar sesión (evita la fijación de sesión) y `exigirRol()` para la autorización. |
| `app/Core/Csrf.php` | Token CSRF de sesión (comparado con `hash_equals`) y tokens de un solo uso contra el doble envío. |
| `app/Core/Sesion.php` | Cookie `HttpOnly`, `SameSite=Lax` y modo estricto; cierre tras 30 minutos de inactividad; mensajes flash. |
| `app/Core/Dinero.php` | Aritmética en **centavos enteros**: convierte `DECIMAL` ↔ entero, aplica porcentajes con redondeo al centavo y da formato de pesos. Evita los errores de punto flotante (por ejemplo, `0.1 + 0.2 ≠ 0.3`). |
| `app/Identidad/AuthController.php` | Inicio de sesión con `password_verify`. Si el correo no existe, igual verifica contra un hash ficticio para no revelar qué correos están registrados. |
| `app/VentasPresenciales/RegistrarVentaPresencial.php` | Caso de uso central: reglas R1–R7, validaciones, transacción, bloqueo y reintento. |
| `app/VentasPresenciales/CalculadoraPrecios.php` | Elige la promoción y calcula líneas y total. No accede a la base, por eso se prueba de forma aislada. |
| `app/VentasPresenciales/MetodoPago.php` | Valores del MR, etiquetas para mostrar y estado inicial del pago. |
| `app/VentasPresenciales/CatalogoRepositorio.php` | Lectura de motos, productos y promociones vigentes; lectura con `FOR UPDATE`; descuento condicionado `UPDATE … WHERE stock >= n`, que es la segunda barrera contra la sobreventa. |
| `app/VentasPresenciales/VentaRepositorio.php` | Inserciones de venta, detalles y pago; búsqueda con filtros (restringida al vendedor cuando corresponde) y paginación; detalle completo. Los comodines `%` y `_` del buscador se tratan como texto literal. |
| `app/VentasPresenciales/VentaController.php` | Listado, formulario, registro y comprobante. El vendedor siempre se toma de la sesión. |
| `app/VentasPresenciales/RegistrarCliente.php` | Validación y alta mínima de cliente; maneja el correo duplicado aunque dos usuarios lo registren al mismo tiempo (error 1062). |
| `app/Diagnostico/DiagnosticoController.php` | Verifica PHP, `pdo_mysql`, conexión, tablas, ajustes, datos y hora. |
| `app/Vistas/ventas/nueva.php` + `public/assets/app.js` | Formulario por pasos. El JS agrega o quita líneas, muestra una **vista previa** de los totales, oculta los requisitos de pago que no aplican, filtra clientes y deshabilita el botón al enviar. Ninguna regla depende del JS. |
| `database/instalar.php` | Crea la base con los tres scripts; no borra una base existente salvo con `--recrear`. |

---

## 6. Seguridad aplicada

| Riesgo | Control |
|---|---|
| Inyección SQL | Todas las consultas usan parámetros preparados (`EMULATE_PREPARES = false`). Los únicos fragmentos SQL dinámicos son nombres de tabla internos y marcadores `?` generados por el código. |
| XSS | Toda salida pasa por `htmlspecialchars` (`e()`), más una CSP que prohíbe scripts en línea. Hay una prueba con `<script>` en el nombre de un cliente. |
| CSRF | Token por sesión en todos los formularios POST, incluido el cierre de sesión. |
| Suplantación del vendedor | `empleado_id` sale de la sesión y del rol verificado en la base; lo que envíe el formulario se ignora. |
| Manipulación de precios | El navegador solo envía el id y la cantidad; precios, descuentos y totales se calculan en el servidor. |
| Acceso a ventas ajenas | La restricción por vendedor está en la consulta SQL; una venta ajena responde 404. |
| Contraseñas | bcrypt (`password_hash`) y comparación con `password_verify`. |
| Sesión | Regeneración del id al iniciar sesión, cookie `HttpOnly` + `SameSite`, expiración por inactividad. |
| Archivos internos | `.htaccess` con `Require all denied` en `app/`, `database/`, `tests/` y `storage/`; sin listado de directorios. |
| Errores | Mensajes genéricos al usuario y detalle en el log (`app.debug = false`). |

---

## 7. Matriz de trazabilidad

Las pruebas `U-` y `I-` están en `tests/run.php`; las `E-` en `tests/e2e_http.php`.

| Requisito / criterio | Código | Pruebas |
|---|---|---|
| **RF-02 regla / HU-05 C1 / CA-1** — venta asociada al vendedor que la registra | `VentaController::registrar` (usa `$usuario['id']`), `RegistrarVentaPresencial::registrar`, `VentaRepositorio::insertarVenta`, `chk_venta_fisica_con_vendedor` | I «Registra venta de contado asociada al vendedor…», I «Ignora un empleado_id enviado…», I «La base rechaza una venta física sin vendedor», E «Registra la venta; ignora empleado_id y precios manipulados…» |
| **RF-02 registrar** — venta con motos, repuestos e insumos y forma de pago | `RegistrarVentaPresencial`, `Vistas/ventas/nueva.php`, `app.js` | I «Registra venta mixta…», I «Agrupa en una sola línea…», E «El formulario de nueva venta carga…», E «Venta con Crédito Brilla completa por HTTP» |
| **RF-02 gestionar / HU-05 C2 / CA-2** — filtrar por moto, cliente y forma de pago | `VentaController::listado`, `VentaRepositorio::buscar`, `Vistas/ventas/listado.php` | I «Filtra por moto», I «Filtra por cliente…», I «Filtra por forma de pago», I «Pagina los resultados…», E «Filtros del vendedor…» |
| **RF-02 gestionar** — consultar el detalle o comprobante | `VentaController::ver`, `VentaRepositorio::obtener`, `Vistas/ventas/detalle.php` | I «Obtiene el detalle completo…», E «Registra la venta…» (verifica el comprobante) |
| **CA-3** — autorización por rol | `Auth::exigirRol`, controladores, verificación R2 en el servicio, filtro por vendedor en SQL | I «Solo un empleado con rol Vendedor puede registrar…», I «Un vendedor solo obtiene sus ventas…», E «Otro vendedor no ve…», E «El administrador ve todas…», E «El mecánico no tiene acceso…», E «Sin sesión, el módulo redirige…» |
| **CA-4 / RF-07 (dependencia)** — cálculo en el servidor con la promoción vigente | `CalculadoraPrecios`, `Dinero`, `CatalogoRepositorio::promocionesVigentes` | U (8 pruebas de `Dinero` y `CalculadoraPrecios`), I «No aplica una promoción vencida», I «Ignora precios enviados por el navegador» |
| **CA-5 / R4** — inventario y no sobreventa | `CatalogoRepositorio::motosPorId(…, true)` (`FOR UPDATE`), `descontar()` condicionado, `chk_*_stock` | I «Stock insuficiente: … atomicidad», I «Moto agotada…», I «Dos ventas simultáneas…», I «Bloqueo de fila … (determinista)», I «La base impide stock negativo…» |
| **CA-6 / RF-03 · HU-06 (dependencia)** — requisitos de Brilla y ServiCrédito | `RegistrarVentaPresencial::validarEntrada`, `MetodoPago`, `chk_pago_brilla_soporte`, `chk_pago_metodo` | I «Crédito Brilla: exige…», I «ServiCrédito: exige…», I «Rechaza forma de pago inexistente…», I «La base rechaza un pago Brilla sin soporte…», E «Errores de validación: 422…» |
| **CA-7** — sin ventas duplicadas | `Csrf::emitirTokenFormulario` / `consumirTokenFormulario`, `data-un-envio` en JS | E «Reenviar el mismo formulario… no duplica la venta» |
| **R7** — atomicidad | Transacción en `RegistrarVentaPresencial::ejecutar/registrar` | I «Stock insuficiente: rechaza la venta completa y no modifica nada» |
| Validación de datos | `validarEntrada`, `leerLineas`, `RegistrarCliente`, función `texto()` | I «Rechaza forma de pago inexistente, venta sin artículos y cantidades inválidas», I «Rechaza cliente inexistente…», I «Informa en un solo intento…», I (2 pruebas de cliente) |
| Integridad de la fecha (C2) | `02_ajustes_rf02.sql` A1 | I «La fecha de la venta no cambia…» |
| Seguridad web | `public/index.php`, `Csrf`, `Sesion`, `e()`, `.htaccess` | E «Un POST sin token CSRF…», E «…XSS», E «La cookie de sesión es HttpOnly…», E «Inicio de sesión… cambia el id de sesión», E «Contraseña incorrecta…», E «Las carpetas internas no son accesibles…», E «Cerrar sesión…», E «Ruta inexistente: 404» |
| Instalación y conexión | `database/instalar.php`, `DiagnosticoController` | E «Diagnóstico de la instalación: todas las comprobaciones en OK» |

---

## 8. Pruebas

### 8.1 Pruebas ejecutadas (24/10/2026, en este equipo)

| Suite | Comando | Resultado |
|---|---|---|
| Sintaxis de todos los archivos PHP | `php -l` sobre `app/`, `public/`, `tests/` y `database/` | Sin errores |
| Unitarias + integración | `C:\xampp\php\php.exe tests\run.php` | **36 correctas, 0 fallidas** |
| Extremo a extremo HTTP (Apache real) | `C:\xampp\php\php.exe tests\e2e_http.php` | **19 correctas, 0 fallidas** |
| Instalación por consola | `database\instalar.php` y `--recrear` | Correcta (66 + 13 + 8 sentencias) |
| Importación con el cliente `mysql.exe` | Los tres scripts sobre una base temporal | Correcta: 11 restricciones `CHECK`, tildes en UTF-8 |
| Diagnóstico | `?r=diagnostico` | 7/7 comprobaciones OK |
| Revisión de logs | `apache/logs/error.log`, `storage/logs/app.log` | Sin advertencias ni errores de PHP; solo las denegaciones 403 esperadas |
| Revisión visual | Capturas con Chrome sin interfaz a 1280 px y 390 px | Formulario, listado y comprobante correctos; se corrigieron el desbordamiento en celular y el salto de línea de los montos |

**Prueba de mutación.** Para comprobar que las pruebas de concurrencia detectan errores reales, se quitó temporalmente el `FOR UPDATE`. La prueba determinista de bloqueo **falló**, como se esperaba. Incluso así no hubo sobreventa: el `UPDATE … WHERE stock >= n` y la restricción `CHECK` actuaron como segunda y tercera barrera, aunque con un error técnico en lugar de un mensaje claro. Después se restauró el código y todas las pruebas volvieron a pasar.

Cálculos verificados (datos de `03_datos_prueba.sql`):

| Caso | Cálculo | Resultado |
|---|---|---|
| Pulsar NS 200 (Media, Combustión): promociones de 10 % y 7 % | 11.490.000 − 10 % | 10.341.000 |
| 2 × NKD 125 (5 %) + 3 aceites + 1 filtro | 2 × 5.025.500 + 114.000 + 25.000 | 10.190.000 |
| Starker Fly (Eléctrica 12,5 %) | 6.490.000 − 811.250 | 5.678.750 |
| Super Soco CPx: 10 % (Media) y 12,5 % (Eléctrica) | se aplica 12,5 % | 15.741.250 |
| Yamaha MT-03, promoción de gama Alta vencida | sin descuento | 26.990.000 |
| Redondeo: 12,5 % de $3,33 | 0,41625 | $0,42 |

### 8.2 Pruebas que debe hacer manualmente

Estas no se pudieron automatizar o dependen de su equipo:

1. **Interacción en el navegador**: agregar y quitar líneas, ver cómo cambia la vista previa del total al cambiar cantidades, ver los requisitos de Brilla o ServiCrédito al elegir la forma de pago, el buscador de clientes y el botón *Imprimir comprobante*. El código JS se revisó y se vio su estado inicial en capturas, pero los clics no se ejecutaron automáticamente.
2. **Importación por phpMyAdmin** (opción A del README). Se probó con `mysql.exe` y con el instalador PDO, no desde la interfaz de phpMyAdmin.
3. **Cierre por inactividad**: dejar la sesión 30 minutos sin uso.
4. **Celular real**: se emuló con un ancho de 390 px; no se probó en un dispositivo.
5. Si su `root` tiene contraseña o usa otro puerto, repetir el diagnóstico tras ajustar `app/config.php`.

---

## 9. Limitaciones y trabajo futuro

- **Sin anulación ni edición de ventas** (D2). Si el profesor la exige, conviene un flujo de anulación con causal y rol autorizado que devuelva el stock y marque la venta, sin borrarla.
- **Requisitos de ServiCrédito no documentados**: se pide una confirmación del vendedor. Cuando RF-03 defina la lista, se puede mostrar como lista de chequeo.
- **El soporte de Brilla es una referencia de texto**, no un archivo. Adjuntar archivos requeriría almacenamiento privado (RF-03).
- **Inventario por referencia, sin VIN** (D7): no identifica qué unidad física se vendió.
- **Catálogo, promociones y empleados se cargan por SQL**: sus módulos de administración (RF-04, RF-07, RF-08) no son parte de RF-02.
- **Sin límite de intentos de inicio de sesión** ni doble factor (el Informe los recomienda para producción).
- Pensado para **ejecución local**: en un servidor real hay que usar HTTPS (la cookie se marca `Secure` automáticamente bajo HTTPS), `app.debug = false` y un usuario de base de datos con privilegios mínimos en lugar de `root`.
- No hay factura electrónica ni notificaciones (fuera del alcance de RF-02).
