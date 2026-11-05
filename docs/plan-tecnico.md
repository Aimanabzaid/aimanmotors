# Aiman Motors — Plan técnico

Última revisión: 6 de noviembre de 2026.

## 1. Situación actual

| Pieza | Qué es |
|---|---|
| Web | `index.html` estático (HTML, CSS y JS sin dependencias) + páginas legales |
| Lógica de propietarios | `propietarios.js` (simulador, preevaluación, calendario, referidos) |
| Servidor | `enviar.php` en Hostinger: recibe formularios, registra y envía email |
| Registro | Ficheros JSON por líneas en `../aiman_datos/` (fuera de `public_html`, no accesible desde internet) |
| Publicación | GitHub `Aimanabzaid/aimanmotors` (rama `main`) → Hostinger con implementación automática |
| Infraestructura disponible | PHP 8 y MySQL incluidos en el plan de Hostinger |

> El repositorio es **público**. Nunca se suben contraseñas, claves ni datos de clientes.
> Recomendación: ponerlo en privado (la conexión con Hostinger sigue funcionando).

## 2. Fase 1 — implementada

- **Simulador de ingresos** (solo navegador; no envía datos). Valores de ejemplo en `CONFIG_PROPIETARIOS.simulador`.
- **Preevaluación** en 4 pasos → referencia `AM-AAMMDD-XXXX`, registro en `solicitudes.jsonl`, email con «puntos a revisar». Nunca aprueba ni rechaza.
- **Calendario de disponibilidad** (6 meses). Actualizable con referencia + teléfono → `disponibilidad.jsonl`.
- **Recomendaciones**: código `R-XXXXXX` y enlace `/?ref=…` → `referidos.jsonl`; la solicitud del recomendado guarda `referido_por`.
- Seguridad: campo trampa, tiempo mínimo, límite por IP (hash), validación en servidor, control de origen, fotos validadas por tipo real y no almacenadas.

**Consultar los registros hoy:** hPanel → Administrador de archivos → carpeta `aiman_datos` (al lado de `public_html`), o buscar la referencia en Gmail.

## 3. Fase 2 — base de datos y gestión interna

**Infraestructura:** MySQL de Hostinger (incluido) + PHP. Coste extra estimado: 0 €.

Tablas previstas (los campos ya coinciden con los registros de la fase 1, que se importarán):

- `propietarios` (id, nombre, teléfono, email, estado, fecha_alta)
- `vehiculos` (id, propietario_id, marca, modelo, año, km, ciudad, combustible, estado, itv, seguro, daños, estado_evaluacion: pendiente/aceptado/rechazado, motivo, fecha)
- `disponibilidad` (vehiculo_id, fecha, estado: disponible/no disponible)
- `referidos` (codigo, propietario_id, recompensa, estado) y `referidos_altas` (codigo, vehiculo_id, fecha, estado)
- `condiciones` (vehiculo_id, comision_porcentaje, fecha_acuerdo, notas) — solo comisiones acordadas
- `eventos` (historial de cambios de estado, para seguimiento)

**Panel interno** `/gestion` con usuario y contraseña (hash bcrypt, sesión segura, bloqueo por intentos), para:
solicitudes, vehículos pendientes/aceptados/rechazados, disponibilidad, referidos, comisiones acordadas y estado de cada propietario.

**Notificaciones automáticas:**
- Email: SMTP con un buzón del dominio (p. ej. `info@aimanmotors.com`), mejor entrega que `mail()`. Normalmente incluido en el plan de Hostinger.
- WhatsApp automático: requiere WhatsApp Business API (Meta cobra por conversación + posible cuota de un proveedor). Se presupuesta aparte antes de activarlo.

## 4. Fase 3 — área privada de propietarios

Ruta: `aimanmotors.com/propietarios`.

- **Autenticación:** acceso por enlace mágico al email o código por SMS/WhatsApp (sin contraseñas que recordar), sesiones con cookie `HttpOnly`, `Secure`, `SameSite`.
- **Separación de datos:** todas las consultas filtradas por `propietario_id` de la sesión en el servidor; nunca por parámetros de la URL.
- **Contenido:** vehículos, estado de solicitudes, disponibilidad editable, reservas y días alquilados, ingresos, comisiones, liquidaciones e incidencias.
- **Requisito previo:** que existan datos reales de reservas y liquidaciones (fase 2 + plataforma de alquiler). No se mostrará ningún dato inventado.
- **Integraciones** con plataformas de alquiler: dependen de que la plataforma ofrezca API; se estudian cuando se elija.

## 5. Alternativa gestionada (si el volumen crece)

Supabase o similar (base de datos + autenticación gestionadas): plan gratuito para empezar; planes de pago desde unos 25 $/mes (comprobar precio vigente). Solo compensa si el panel PHP se queda corto.

## 6. Pendiente de definir por Aiman Motors

Comisión real, condiciones del seguro, procedimiento ante daños, forma y plazo de pagos, requisitos de vehículos y conductores, recompensa del programa de recomendaciones, plazo de conservación de datos y datos del titular para el aviso legal.
