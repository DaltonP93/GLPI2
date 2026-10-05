# UAT — baseline de Fase 2 (Compras v1 + plataforma)

> Pruebas de aceptación de usuario de la baseline `main` `6c7d994` (`../releases/phase2-baseline.md`).
> **Sólo en staging o test**, nunca en producción. Instancia instalada con `../operations/phase2-clean-install.md` o
> actualizada con `../operations/phase2-upgrade.md`.
> **SI-4 sigue apagado** (`si4_enabled = 0`): no se habilita para esta UAT (ver F-13).
> **Sin datos personales reales** y sin secretos: usuarios, proveedores e importes son de prueba.

Cada caso registra: ID · usuario/perfil · fecha y hora (America/Asuncion) · resultado (OK/FALLA) · evidencia
(captura, URL, n.º de solicitud, correo capturado). Una FALLA bloquea la aceptación hasta que se analice.

Rutas de la UI de Compras: prefijo `/plugins/companypurchasing`, o el menú **Gestión → Compras**.
- `/requests`
- `/inbox/{approvals|purchasing|receiving|delivery}`
- `/request/new`
- `/request/{id}`
- `/request/{id}/edit`
- `/metrics`
- `/config`

## 0. Preparación

| # | Qué | Detalle |
|---|---|---|
| P-1 | Entidades | Al menos **E1** y **E2**, hermanas, para multi-entidad |
| P-2 | Grupos aprobadores (E1) | Jefe de área, Compras y Finanzas; configurados en `/config` (`approver_group_area_head`, `_purchasing`, `_finance`). Quórum 1 salvo en S-03 |
| P-3 | Definición publicada | `/config` → **Publicar** |
| P-4 | Proveedores | 2 proveedores de GLPI aplicables a E1 (**Gestión → Proveedores**) |
| P-5 | Correo | Notificaciones de GLPI activas y salida a un capturador de test (MailHog en DEV, `../operations/email-dev.md`). Nunca un buzón real |
| P-6 | Cron | `front/cron.php` cada minuto **como www-data**, nunca root (`../releases/phase2-baseline.md` §6). Verificar en **Configuración → Acciones automáticas** que `escalation`, `reconcile` y `reconcileprojection` avanzan |
| P-7 | Moneda | PYG, escala 0 |

Usuarios de prueba, con **mínimo privilegio** (perfiles de `../operations/phase2-clean-install.md` §5) y sin `VIEW_*` salvo donde
se indica:

| Usuario | Entidad | Perfil / derechos |
|---|---|---|
| `uat-sol` | E1 | Solicitante: READ + CREATE_REQUEST + VIEW_OWN + EDIT_DRAFT |
| `uat-jefe`, `uat-jefe2` | E1 | Aprobador: READ + motor RIGHT_ACT + firma RIGHT_RECORD; miembros del grupo Jefe de área |
| `uat-comp` | E1 | Compras: READ + MANAGE_PURCHASING + motor RIGHT_ACT + firma RIGHT_RECORD; grupo Compras |
| `uat-fin` | E1 | Aprobador: grupo Finanzas |
| `uat-rec` | E1 | Receptor: READ + RECEIVE |
| `uat-ent`, `uat-ent2` | E1 | Entregador: READ + DELIVER |
| `uat-met` | E1 | Métricas: READ + VIEW_METRICS |
| `uat-sol2` | E2 | Solicitante en E2 |
| `uat-nopriv` | E1 | Self-Service, **sin** derechos de los plugins |

Solicitudes de la UAT:
- **A**: sólo líneas **no** inventariables. Recorre el circuito completo hasta el cierre.
- **B**: con una línea **inventariable**. Sirve para verificar el gate de inventario con SI-4 apagado.
- **C** (devolución) y **D** (rechazo): para las ramas alternativas.

## 1. Flujo principal

| ID | Quién | Pasos | Resultado esperado |
|---|---|---|---|
| F-01 Crear | `uat-sol` | `/request/new`: cabecera + 2 líneas no inventariables (p. ej. 3 u. y 2 u.) con importes en PYG | Solicitud A en **Borrador** (`DRAFT`). Montos exactos, sin decimales. Sin número de solicitud todavía |
| F-02 Editar borrador | `uat-sol` | `/request/{id}/edit`: cambiar cabecera; agregar, modificar y quitar una línea | Cambios guardados (PRG con mensaje). Total recalculado exacto. Sólo edita quien tiene EDIT_DRAFT y puede verla (con VIEW_OWN, sólo las propias) |
| F-03 Enviar | `uat-sol` | **Enviar** | Pasa a **Pendiente de aprobación del jefe de área** (`PENDING_AREA_HEAD`) con número asignado. Ya no es editable. Aparece en `/inbox/approvals` de `uat-jefe`. Correos "enviada" y "pendiente de aprobación" |
| F-04 Jefe aprueba | `uat-jefe` | Desde su bandeja: abrir → **Aprobar** con comentario | Pasa a **En revisión de Compras** (`PURCHASING`). En el historial: actor, fecha y hora, comentario, estado anterior y nuevo. Evidencia registrada. PDF de la etapa (`pdf_stages`) en "PDF aprobados y evidencias". Desaparece de la bandeja del jefe |
| F-05 Jefe devuelve | `uat-jefe` (sol. C) | **Devolver** con comentario | Pasa a **Devuelta** (`RETURNED`). `uat-sol` la edita y la **reenvía**: vuelve a `PENDING_AREA_HEAD`. Correo "devuelta" |
| F-06 Jefe rechaza | `uat-jefe` (sol. D) | **Rechazar** con comentario | Pasa a **Rechazada** (`REJECTED`): estado final, sin acciones disponibles. Correo "rechazada" |
| F-07 Compras cotiza | `uat-comp` | En la solicitud A: crear 2 cotizaciones (2 proveedores), editarlas y adjuntar un documento | Cotizaciones con montos exactos y adjunto visible. Sólo Compras ve los botones |
| F-08 Seleccionar proveedor | `uat-comp` | **Seleccionar** una cotización → **Aprobar** su etapa | Cotización seleccionada (una sola). Pasa a **Pendiente de aprobación de Gerencia Financiera** (`PENDING_FINANCE`) |
| F-09 Finanzas aprueba | `uat-fin` | **Aprobar** | Pasa a **Aprobada** (`APPROVED`). PDF de la etapa de Finanzas. Correo "aprobada" |
| F-10 Iniciar compra | `uat-comp` | **Iniciar compra** | Pasa a **En compra** (`IN_PURCHASE`). El contenido aprobado queda **congelado** (no editable). Correo "compra iniciada" |
| F-11 Recepción parcial | `uat-rec` | `/inbox/receiving` → recibir sólo parte de una línea | Pasa a **Recibida parcialmente** (`PARTIALLY_RECEIVED`). Se crean las unidades recibidas, cada una con su costo exacto. El resto queda pendiente |
| F-12 Recepción total | `uat-rec` | Recibir el resto | Pasa a **Recibida** (`RECEIVED`). Correos "recepción completa" y "lista para entregar" |
| F-13 Inventario / SI-4 | `uat-rec`, `uat-ent` (sol. B) | Recorrer B hasta **Recibida** e intentar entregar la unidad inventariable | La unidad muestra **"Inventario pendiente"** y **no** se puede entregar ni cerrar. Es lo esperado con SI-4 apagado (`../operations/si4-readiness.md` §0). Sin bypass. Las líneas no inventariables de B sí se entregan |
| F-14 QR / etiqueta | admin con `plugin_companyqr` | En un activo nativo de GLPI (E1) → botón **QR / etiqueta** → generar → imprimir etiqueta | El PDF de la etiqueta no lleva IP, MAC, hostname ni serial. La URL del QR exige login y muestra la ficha según la ACL. `/public/{token}` **no** se sirve (`anonymous_enabled = 0`) |
| F-15 Entrega parcial | `uat-ent` | `/inbox/delivery` → entregar **algunas** unidades a un destinatario | Las unidades quedan "Entregada" (lote con actor, destinatario y fecha). La solicitud sigue **Recibida**. Las pendientes siguen en la bandeja |
| F-16 Entrega completa | `uat-ent` | Entregar las unidades restantes | Pasa a **Entregada** (`DELIVERED`). Correo "entregada" |
| F-17 Cerrar | `uat-comp` | **Cerrar** | Pasa a **Cerrada** (`CLOSED`): final, sin acciones. Correo "cerrada" |
| F-18 PDF / evidencia | `uat-sol`, `uat-comp` | Abrir "PDF aprobados y evidencias" | PDFs descargables por etapa y versión; fecha y hora en America/Asuncion; una evidencia por aprobador. Es aprobación **interna**, no firma digital certificada. "Reintentar PDF aprobado" sólo aparece si un PDF falló |
| F-19 Notificaciones | — | Revisar el capturador de correo | Los 10 eventos (enviada, pendiente de aprobación, aprobada, rechazada, devuelta, compra iniciada, recepción completa, lista para entregar, entregada, cerrada) aparecen **como mucho una vez** por hecho, con los destinatarios correctos (solicitante, aprobadores efectivos, actor, destinatario) |
| F-20 Métricas | `uat-met` | `/metrics` | Conteos por estado y mes; montos solicitados, aprobados y comprados **por moneda**, exactos; ciclo y duración por etapa. Filtrar por entidad sólo **reduce** |
| F-21 Multi-entidad | `uat-sol2` (E2) + usuarios de E1 | `uat-sol2` crea y envía una solicitud en E2 | Los usuarios de E1 **no** la ven en listados ni bandejas, ni pueden accionarla. Las métricas de `uat-met` (E1) no la cuentan |
| F-22 Perfiles mínimos | cada usuario | Abrir el menú y las bandejas | Cada uno ve sólo sus bandejas: aprobaciones para todos; compras, recepción y entrega según su derecho. Sin páginas de configuración salvo MANAGE_CONFIG |
| F-23 Lectura contextual | `uat-jefe`, `uat-rec`, `uat-ent` (sin VIEW_*) | Abrir desde la bandeja una solicitud accionable; después de actuar, recargar su URL; abrir a mano el id de otra solicitud de E1 no accionable; abrir un id inexistente | Accionable ⇒ 200. Terminada la acción ⇒ **403**. Otra no accionable ⇒ **403**. Inexistente ⇒ **404**. La búsqueda general (`/requests`) no se amplía (ADR-0023) |
| F-24 Permisos negativos | `uat-nopriv`, `uat-rec` | `uat-nopriv`: abrir las páginas de Compras y enviar un POST con un CSRF válido de su sesión. `uat-rec`: intentar **Entregar** o **Cerrar** | Páginas ⇒ 403/404. POST ⇒ **403** y **nada** creado ni modificado. Acciones fuera del derecho ⇒ 403 |

## 2. Escenarios de robustez

| ID | Escenario | Pasos | Resultado esperado |
|---|---|---|---|
| S-01 Replay / idempotencia | reenvío del mismo POST | Tras aprobar, recibir o entregar: volver atrás y reenviar el formulario (o F5 sobre el POST) | El token CSRF reutilizado da **403**. No se duplica la aprobación, la recepción ni el lote de entrega |
| S-02 Doble click | doble envío | Doble click rápido en **Aprobar**, **Recibir** y **Entregar** | Un **único** efecto: una fila de historial y un lote. El segundo envío no produce nada |
| S-03 Concurrencia | dos actores a la vez | (a) `uat-jefe` y `uat-jefe2` (mismo grupo, quórum 1) aprueban la misma solicitud desde dos navegadores al mismo tiempo. (b) `uat-ent` y `uat-ent2` entregan la misma unidad a la vez | (a) **Una sola** decisión registrada; el otro recibe un mensaje seguro de que el estado cambió. (b) **Un solo** lote; el otro recibe un conflicto, sin entrega doble |
| S-04 Usuario sin derecho | ver F-24 | — | 403 y sin efectos |
| S-05 Otra entidad | manipular ids | `uat-sol2` (E2) abre `/request/{id}` de una solicitud de E1 y envía un POST de acción con su CSRF válido | 403/404 y sin efectos |
| S-06 Error de integración | fallas controladas en staging | (a) Detener el SMTP o capturador de test y aprobar una solicitud. (b) `config:set --context=plugin:companysignature listen_workflow_events 0` y aprobar otra. (c) Solicitud B con SI-4 apagado (F-13) | (a) La aprobación **se confirma**: un fallo de envío no revierte el negocio (`notification.failed`). (b) La aprobación se registra y la evidencia queda **pendiente**. (c) "Inventario pendiente", sin bypass |
| S-07 Recuperación posterior | volver a la normalidad | (a) Restaurar el SMTP. (b) `listen_workflow_events 1` y esperar la Acción automática `reconcile` (o `plugins:companysignature:reconcile`). (c) `plugins:companypurchasing:reconcile` | (a) La cola nativa de GLPI envía lo pendiente. (b) La evidencia se materializa **exactamente una vez**. (c) Termina sin anomalías nuevas; un `recepcion_pendiente` converge con `reconcileprojection` |

## 3. Criterios de salida

- [ ] Todos los F-xx y S-xx en OK, con evidencia registrada.
- [ ] `plugins:companysignature:reconcile` y `plugins:companypurchasing:reconcile` limpios al final. Sólo se aceptan
  los `legacy` documentados de un upgrade.
- [ ] `files/_log/`:
  - sin errores nuevos de los plugins;
  - el único CRITICAL conocido es un GET sobre rutas POST de companyqr o companyworkflow
    (`../releases/phase2-baseline.md` §6).
- [ ] SI-4 sigue apagado: `plugins:companyintegrations:si4-run` ⇒ "disabled".
- [ ] `tests/upgrade/verify-core-untouched.sh` OK.
- [ ] Firma de aceptación (rol, no persona): responsable de Compras, de TI y de Seguridad, con fecha.

## 4. Fuera de alcance de esta UAT

- SI-4 contra Snipe-IT. El recorrido completo con inventario está cubierto en CI por `[E2E-FULL]`, con un Snipe-IT
  simulado. En staging, sólo cuando se cumpla `../operations/si4-readiness.md` y haya autorización explícita.
- `companyportal` y `companydashboard`: esqueletos.
- IA, WhatsApp y firma digital certificada.
