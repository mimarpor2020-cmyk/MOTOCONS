# MOTOCONS · RF-02 Ventas presenciales

Módulo web para que los vendedores registren y gestionen las ventas asistidas en la tienda física,
asociadas al vendedor que las registra (RF-02 / HU-05). PHP nativo + PDO + MySQL/MariaDB, listo para XAMPP.

La documentación técnica completa (análisis, diseño, trazabilidad, pruebas y decisiones) está en
[docs/RF-02_documentacion.md](docs/RF-02_documentacion.md).

## Requisitos

| Componente | Versión probada | Mínimo |
|---|---|---|
| XAMPP (Apache + PHP + MariaDB) | 8.2.12 | — |
| PHP | 8.2.12 | 8.1 (usa `never`, `readonly`) con `pdo_mysql` y `mbstring` |
| MariaDB / MySQL | MariaDB 10.4.32 | MariaDB ≥ 10.2 o MySQL ≥ 8.0.16 (restricciones `CHECK`) |
| Navegador | Chrome / Edge actuales | cualquiera moderno |

No requiere Composer, Node ni librerías externas.

## Instalación paso a paso

### 1. Ubicar el proyecto

La carpeta del proyecto debe quedar en `C:\xampp\htdocs\motocons`, con `app\`, `public\`,
`database\` y demás subcarpetas directamente debajo de ella.
Si su XAMPP está en otra ruta, use `<su_xampp>\htdocs\motocons`.

### 2. Iniciar los servicios

1. Abra **XAMPP Control Panel** (`C:\xampp\xampp-control.exe`).
2. Pulse **Start** en **Apache** y en **MySQL**. Ambos deben quedar en verde.
   - Si Apache no arranca, otro programa usa el puerto 80 (por ejemplo, IIS o Skype). Cierre ese programa o cambie el puerto en *Config → httpd.conf* (`Listen 8080`); en ese caso las URL llevan `:8080`.

### 3. Configurar la conexión (solo si es necesario)

Abra `app/config.php`. Los valores por defecto corresponden a un XAMPP sin modificar:

| Clave | Valor por defecto | Cuándo cambiarlo |
|---|---|---|
| `db.host` | `127.0.0.1` | Casi nunca |
| `db.puerto` | `3306` | Si en XAMPP configuró otro puerto para MySQL (*Config → my.ini*, `port=`) |
| `db.usuario` | `root` | Si prefiere un usuario propio |
| `db.clave` | `''` (vacía) | **Si le asignó contraseña a `root`** (por ejemplo en phpMyAdmin) |
| `db.nombre` | `motocons_db` | Si importó la base con otro nombre |
| `app.debug` | `false` | `true` solo para ver el detalle técnico de los errores mientras desarrolla |

### 4. Crear la base de datos

**Opción A — phpMyAdmin (visual):**

1. Abra http://localhost/phpmyadmin
2. *Nueva* → nombre `motocons_db`, cotejamiento `utf8mb4_general_ci` → *Crear*.
3. Con `motocons_db` seleccionada, pestaña *Importar*, e importe **en este orden**:
   1. `database/01_motocons_db.sql` — modelo oficial del proyecto (sin cambios).
   2. `database/02_ajustes_rf02.sql` — ajustes de integridad de RF-02 (ejecutar una sola vez).
   3. `database/03_datos_prueba.sql` — empleados, clientes, motos, productos y promociones de prueba.

**Opción B — consola (un solo comando):**

```bat
cd C:\xampp\htdocs\motocons
C:\xampp\php\php.exe database\instalar.php
```

Si la base ya existe, el instalador no la toca. Con `--recrear` la **borra** y la crea de nuevo, por ejemplo para dejarla limpia antes de una demostración.

### 5. Comprobar la instalación

Abra **http://localhost/motocons/public/index.php?r=diagnostico**. Todas las filas deben decir **OK**:
versión de PHP, `pdo_mysql`, conexión, tablas, ajustes de RF-02, datos mínimos y hora de PHP y de la base.
Si la conexión falla, el detalle indica la causa (MySQL detenido, contraseña, base inexistente).
El diagnóstico solo responde a peticiones desde el mismo equipo.

### 6. Usar el módulo

Abra **http://localhost/motocons/** (redirige a `public/`).

| Rol | Correo | Contraseña | Qué puede hacer |
|---|---|---|---|
| Vendedor | `carlos.perez@motocons.test` | `Motocons2026*` | Registrar ventas, registrar clientes, ver **sus** ventas |
| Vendedor | `ana.ruiz@motocons.test` | `Motocons2026*` | Igual que el anterior |
| Administrador | `admin@motocons.test` | `Motocons2026*` | Ver **todas** las ventas presenciales y filtrar por vendedor |
| Mecánico | `jorge.diaz@motocons.test` | `Motocons2026*` | Sin acceso (sirve para demostrar la autorización) |

## Guion sugerido para la demostración

1. Inicie sesión como **Carlos** → *Nueva venta*.
2. Cliente: María Fernanda López. Moto: *Bajaj Pulsar NS 200*, que tiene dos promociones vigentes (10 % y 7 %); se aplica la mayor.
3. Agregue *Aceite 20W-50* × 2. Forma de pago **Contado** → *Registrar venta* → comprobante con total **$ 10.417.000**.
4. Nueva venta con **Crédito Brilla** sin escribir el recibo de gas: el sistema rechaza la venta y explica qué falta (HU-06).
5. Pida 3 unidades de *Líquido de frenos* (stock 2): la venta se rechaza por stock insuficiente.
6. En *Ventas*, filtre por moto, por cliente y por forma de pago.
7. Cierre sesión, entre como **Ana**: no ve las ventas de Carlos. Si abre la URL de una de ellas, responde «no encontrada».
8. Entre como **administrador**: ve todas las ventas con su vendedor, pero no puede registrar ventas. Como **mecánico**: acceso denegado.

## Pruebas automatizadas

```bat
cd C:\xampp\htdocs\motocons
C:\xampp\php\php.exe tests\run.php          :: 36 pruebas unitarias y de integración (usa la base temporal motocons_test)
C:\xampp\php\php.exe tests\e2e_http.php     :: 19 pruebas HTTP contra Apache (escribe en motocons_db)
C:\xampp\php\php.exe database\instalar.php --recrear   :: deja motocons_db como recién instalada
```

## Estructura

```
motocons/
├── index.php                    Redirige a public/
├── app/                         Código de la aplicación (bloqueado al navegador con .htaccess)
│   ├── config.php               Configuración de conexión y zona horaria
│   ├── bootstrap.php            Autoload, funciones de ayuda y configuración
│   ├── Core/                    Infraestructura: PDO, sesión, autenticación, CSRF, vistas, dinero, excepciones
│   ├── Identidad/               Inicio de sesión y roles (tabla empleado)
│   ├── VentasPresenciales/      Módulo RF-02: controladores, casos de uso, cálculo y repositorios
│   ├── Diagnostico/             Página de comprobación de la instalación
│   └── Vistas/                  Plantillas HTML (layout, login, ventas, clientes, errores)
├── public/                      Única carpeta pensada para el navegador
│   ├── index.php                Controlador frontal (rutas ?r=...)
│   └── assets/                  app.css y app.js
├── database/                    Scripts SQL e instalador por consola (bloqueado al navegador)
├── tests/                       Pruebas automatizadas (bloqueado al navegador)
├── storage/logs/                Registro de errores app.log (bloqueado al navegador)
└── docs/RF-02_documentacion.md  Documentación técnica
```

## Solución de problemas

| Síntoma | Causa probable y solución |
|---|---|
| «Error de base de datos» al entrar | MySQL detenido, base no creada o contraseña distinta. Abra `?r=diagnostico`. |
| `Access denied for user 'root'` | `root` tiene contraseña: escríbala en `app/config.php` → `db.clave`. |
| `Unknown database 'motocons_db'` | Falta el paso 4. |
| El diagnóstico dice «Ajustes RF-02: FALLA» | Falta importar `02_ajustes_rf02.sql`. |
| «Solicitud no válida» al enviar un formulario | La sesión expiró (30 min sin actividad) o se abrió el formulario antes de reiniciar el navegador. Recargue la página. |
| Error 403 en `http://localhost/motocons/app/...` | Es lo esperado: esas carpetas no son públicas. |
