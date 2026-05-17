# RADEP — Registro y Acreditación de Proveedores

> Plataforma web para la gestión, acreditación y control de proveedores en eventos corporativos y sociales.

**Proyecto Final · 7mo Informática "B" · Instituto Leonardo Murialdo**

---

## ¿Qué es RADEP?

RADEP es una plataforma web diseñada para que empresas organizadoras de eventos gestionen de forma centralizada a sus proveedores. Permite validar documentación legal, asignar personal y autorizar el ingreso a eventos mediante códigos QR, eliminando los procesos manuales y reduciendo errores administrativos.

**Cliente:** Magnética (Empresa Productora)

---

## Funcionalidades principales

### Para empresas organizadoras
- Creación y gestión de eventos
- Alta y asignación de proveedores a eventos
- Revisión y validación de documentación legal
- Aprobación o rechazo de documentos cargados
- Autorización de ingreso al evento

### Para proveedores
- Alta de empleados
- Asignación de empleados a eventos
- Carga de documentación requerida
- Recepción de código QR de acceso vía email (si la documentación es aprobada)

### Para administradores
- Control y manejo general del sistema
- Gestión de roles y permisos de usuarios

---

## Stack tecnológico

| Capa | Tecnología |
|---|---|
| Frontend | HTML, CSS, JavaScript |
| Backend | PHP |
| Base de datos | AWS RDS |
| Almacenamiento | AWS S3 |
| Infraestructura | AWS Cloud |
| Protocolo | HTTPS / SSL-TLS |
| Comunicación interna | TCP/IP |

El sistema es 100% cloud-based sobre AWS, sin necesidad de servidores locales.

---

## Requerimientos funcionales

| ID | Nombre | Prioridad |
|---|---|---|
| RF01 | Administración de usuarios (ABM) | Alta |
| RF02 | Administración de documentos | Alta |
| RF03 | Autenticación de usuario por rol | Alta |
| RF04 | Administración de empresas y eventos | Alta |
| RF05 | Administración de proveedores y empleados | Alta |
| RF06 | Verificación de requisitos legales + emisión de QR | Alta |

---

## Interfaces

- **IU01–IU04:** Interfaz basada en páginas, menús y formularios; navegación por rol; diseño responsive.
- **IH01–IH02:** Compatible con PC, notebook, tablet y smartphone con conexión a internet.
- **Software:** Windows 10+, macOS, Linux · Browsers: Chrome, Edge, Firefox, Safari, Opera.

---

## Seguridad

- Autenticación obligatoria para acceder a cualquier funcionalidad
- Control de acceso basado en roles (empresa, proveedor, administrador)
- Contraseñas encriptadas/hasheadas
- Comunicaciones bajo HTTPS con cifrado SSL/TLS
- Cumplimiento de la **Ley N.º 25.326 de Protección de Datos Personales (Argentina)**
- Uptime objetivo: **99.99%** (≤ 52 min de inactividad por año)

---

## Equipo

| Nombre | Rol |
|---|---|
| Iván Pelli | Project Manager |
| Máximo González Mayer | Backend |
| Santiago Vázquez | Base de Datos |
| Massimo Olmedo | Frontend |
| Federico García Raffa | Frontend |

---

## Links del proyecto

- 🔗 [Repositorio GitHub](https://github.com/RADEP2025/RADEP-Registro-y-Acreditaci-n-de-Proveedores-.git)
- 🎨 [Prototipo en Figma](https://www.figma.com/design/V481YQezgjm9S3LdWKdA4I/Untitled?node-id=109-18&t=3vryiCnG84aGoScT-1)
- 📋 [Carta Gantt (Notion)](https://www.notion.so/RADEP-20621f82fadd80bcb2bad1f88647f62c?source=copy_link)
- 📖 [Manual de uso – Empresas](https://docs.google.com/document/d/1E_haVIuL5BDNGWnDdZxewMFhX-E_clgOGqUV03qF7rA/edit?usp=sharing)
- 📖 [Manual de uso – Proveedores](https://docs.google.com/document/d/1Bz47pd8ipgxuc8F4L-AH6XDJCggj_H7WfuLoDsllIuY/edit?usp=sharing)
- 💰 [Estimación económica](https://docs.google.com/spreadsheets/d/144l3euzntN0xHRFofzsOPSjnxRyvw2i5_365EYh6NjM/edit?usp=sharing)

---

## Plazos del proyecto

| Hito | Fecha |
|---|---|
| Primer acercamiento con Magnética | 25/04/2025 |
| Segundo acercamiento con Magnética | 08/09/2025 |
| Tercer acercamiento con Magnética | 29/09/2025 |
| Entrega del producto | 01/12/2025 |

---

## Criterios de aceptación

El sistema se considera aceptado si:

1. La empresa puede crear eventos correctamente.
2. Se permite dar de alta a proveedores y empleados.
3. Se pueden asignar y eliminar proveedores de eventos.
4. Es posible cargar, visualizar y validar documentos.
5. Se envían automáticamente los correos con el QR de acceso al evento.

---

## Control de versiones del proyecto

| Revisión | Descripción | Fecha | Versión |
|---|---|---|---|
| 001 | Versión inicial — previa aprobación cliente | 10/07/2025 | RADEP_V01 |
| 002 | Versión avanzada | 02/10/2025 | RADEP_V02 |

---

> Instituto Leonardo Murialdo · Grupo 7 · 7mo Informática "B" · 2025
