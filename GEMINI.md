# Proyecto Laboratorio Técnico (laboratorio-php)

Este proyecto es un sistema de gestión de reparaciones para un laboratorio técnico, permitiendo el seguimiento de equipos desde su ingreso hasta su entrega, con control de técnicos, estados y registro histórico de acciones.

## Resumen del Proyecto
- **Propósito**: Gestión y seguimiento de órdenes de reparación técnica.
- **Origen**: Este proyecto es un port de una aplicación previa desarrollada en **Python/Flask con SQLite** (ubicada originalmente en `C:\proyecto_laboratorio`).
- **Tecnologías Principales**: PHP 8+, MySQL/MariaDB, Bootstrap 5, Vanilla JS/jQuery.
- **Arquitectura**: Estructura MVC simplificada con lógica centralizada en `app/` y puntos de entrada públicos en `public/`.

## Estructura de Directorios Principal
- `/admin`: Funciones administrativas (backups, importación CSV, gestión de usuarios).
- `/api`: Endpoints para actualizaciones dinámicas vía AJAX (estados, prioridades, comentarios).
- `/app`: Lógica de negocio, modelos de datos (`ReparacionModel`, `CatalogoModel`) y utilidades.
- `/database`: Contiene el esquema SQL inicial (`schema.sql`).
- `/exports`: Lógica para generación de archivos Excel.
- `/public`: Raíz web del proyecto. Contiene los archivos `.php` que se sirven al usuario.
- `/public/assets`: Archivos estáticos (CSS, JS).
- `/public/includes`: Componentes de interfaz compartidos (header, sidebar, footer).

## Configuración y Ejecución
1. **Base de Datos**: Importar `database/schema.sql` en un servidor MySQL/MariaDB.
2. **Configuración**: Editar `app/config.php` para ajustar las credenciales de la base de datos y la constante `APP_URL`.
3. **Servidor**: Requiere un servidor compatible con PHP (como Laragon, XAMPP o Apache nativo). Apuntar el servidor a la carpeta raíz del proyecto.
4. **Instalación**: Se puede utilizar `public/install.php` para la configuración inicial si está disponible.

## Convenciones de Desarrollo
- **Seguridad**:
    - Utilizar siempre `e($string)` para escapar salidas HTML y prevenir XSS.
    - Las consultas a la base de datos deben usar **sentencias preparadas** (PDO) para prevenir Inyección SQL.
    - La autenticación y roles se gestionan mediante `app/auth.php` y `requireRole()`.
    - Se debe incluir el token CSRF en formularios que realicen cambios (usar `csrf.php`).
- **Modelos**: Toda la interacción con la tabla `reparaciones` debe realizarse a través de `app/ReparacionModel.php`.
- **Historial**: Cualquier cambio significativo en el estado de una reparación debe registrarse usando `registrarHistorial()` (definido en `app/historial.php`).
- **Nomenclatura**: Las tablas de la base de datos y variables de sesión suelen estar en español, mientras que algunas funciones internas y clases pueden usar inglés o español.

## Características Clave
- **Importación/Exportación**: Soporta importación masiva de equipos vía CSV y exportación de reportes a Excel.
- **Identificación**: Uso de UID y NPU para el seguimiento único de equipos.
- **Roles**: Distinción entre `admin` (control total) y `tecnico` (gestión de sus propias reparaciones).
- **Single Session**: El sistema invalida sesiones previas al iniciar una nueva sesión con el mismo usuario.

## Convenciones de Interfaz (UI/UX)
Este proyecto adopta una estética **SaaS de Alta Densidad (High-Density)**, orientada a uso administrativo intensivo:
- **Layout Compacto**: Reducción drástica de márgenes y *paddings*. Controles (inputs, selects, botones) estandarizados a alturas de **38px - 40px** (`.btn-compact`, `.form-control-compact`).
- **Tipografía**: Títulos de página entre 26px y 28px. Textos principales, tablas y formularios entre **13px y 14px**. Textos secundarios y etiquetas en 12px con colores grises suaves (`#64748b`).
- **Tablas Administrativas**: 
  - Uso de `table-layout: fixed` con anchos de columna estrictos.
  - Scroll vertical interno (`.table-scroll` con `max-height: calc(100vh - 330px)`) y cabeceras fijas (`position: sticky`).
  - Información apilada en celdas (`.table-cell-stack` o `.identificacion-cell`) para mostrar dos datos en la misma celda sin ensanchar la tabla (Ej: Fecha arriba, Hora abajo). Textos largos truncados con *ellipsis*.
- **Filtros y Estados (Chips/Pills)**:
  - Los estados se muestran como "píldoras" (`.status-pill` o `.nav-tab-custom`). 
  - Colores activos estandarizados: Urgentes (Rojo), Pend. Reparación (Azul), En Reparación (Violeta), Reparados (Verde), Pendientes (Mostaza), Sin Reparación (Gris Azulado), Todas (Naranja).
  - Los estados soportan saltos de línea manuales (`<br>`) para textos largos sin romper el diseño.
- **Modales**: Diseño redondeado (`border-radius: 12px`), sombras suaves (`shadow-sm`), anchos controlados (`max-width: 340px - 400px`) y eliminación de bordes internos duros.
- **Restricciones UX**: Campos sensibles (como Fecha de Ingreso) se muestran como `readonly` por defecto y requieren activación manual mediante un *switch* exclusivo para usuarios con rol `admin`.
