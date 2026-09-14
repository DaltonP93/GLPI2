# Seguridad Fase 2 — ACL y auditoría (compras · workflow · firma)

Complementa `security-baseline.md`. **Diseño, sin implementación.** Toda autorización se apoya
en **ACL nativa de GLPI + entidad** (principio heredado: *el identificador identifica; GLPI
autoriza*). **Sin tocar el core.**

## Roles (derechos de plugin, no personas)
Se implementan como **derechos de perfil** propios (bits), asignables a los perfiles/grupos que
cada organización decida — **nunca** personas hardcodeadas:
`purchasing:create` · `purchasing:approve_area` · `purchasing:buy` (Compras) ·
`purchasing:approve_finance` · `purchasing:admin` · `purchasing:audit` · `purchasing:read`.
El "aprobador" concreto por etapa lo resuelve `companyworkflow` por **grupo/rol/entidad**.

## Matriz ACL (mínima)
| Acción | Solicitante | Jefe de Área | Compras | Gerencia Financiera | Administrador | Auditor | Consulta |
|---|---|---|---|---|---|---|---|
| Crear/editar borrador propio | ✅ (propio) | — | — | — | ✅ | — | — |
| Enviar (submit) | ✅ (propio) | — | — | — | ✅ | — | — |
| Ver solicitud | ✅ (propias) | ✅ (de su área/grupo) | ✅ (en flujo Compras) | ✅ (en su etapa) | ✅ | ✅ (todo, sólo lectura) | ✅ (lectura acotada) |
| Aprobar/rechazar/devolver — etapa **Jefe** | — | ✅ | — | — | ✅* | — | — |
| Revisar/cotizar/seleccionar proveedor | — | — | ✅ | — | ✅* | — | — |
| Aprobar/rechazar/devolver — etapa **Gerencia** | — | — | — | ✅ | ✅* | — | — |
| Registrar orden / recepción / entrega / cierre | — | — | ✅ | — | ✅ | — | — |
| Generar/exportar **PDF aprobado** | ✅ (propias) | ✅ | ✅ | ✅ | ✅ | ✅ | según config |
| Configurar workflow/derechos | — | — | — | — | ✅ | — | — |
| Ver auditoría/historial | ✅ (propio, resumen) | ✅ (su ámbito) | ✅ (su ámbito) | ✅ (su ámbito) | ✅ | ✅ (completa) | — |
| Cancelar | ✅ (propia, no-final) | — | ✅ (en su ámbito) | — | ✅ | — | — |

`*` El administrador puede actuar por excepción **con auditoría**; no reemplaza el quórum salvo
política explícita. La "consulta" es un rol de sólo-lectura acotado por configuración.

## Multi-entidad (estricto)
- Cada solicitud tiene `entities_id`/`is_recursive`. **Una persona de la entidad A no puede
  ver ni aprobar solicitudes de la entidad B**, salvo un **permiso explícito** (perfil con
  acceso recursivo o asignación entre entidades).
- Enforcement: `Session::haveAccessToEntity()` + derecho de plugin, en **cada** carga y acción
  (como la 🔒 multi-entidad ya probada en `companyqr`). Test obligatorio fail-closed en CI.
- El **auditor** puede tener alcance más amplio, pero siempre **sólo lectura** y registrado.

## Auditoría (append-only, sin borrado silencioso)
Eventos que se registran (tabla propia + `Log` nativo para cambios de campo):
`created · modified · submitted · approved · rejected · returned · amount_changed ·
items_changed · quote_added · quote_selected · supplier_selected · ordered · received ·
delivered · closed · cancelled · signed(evidence) · pdf_generated/exported ·
external_integration(webhook/api) · approval_invalidated`.

- **Append-only:** no hay borrado ni edición de eventos; correcciones = eventos nuevos.
- Cada evento guarda: `fecha/hora + timezone (America/Asuncion) · actor (users_id) · entidad ·
  objeto · from/to (si aplica) · comentario · meta` (sin secretos ni PII innecesaria).
- **Sin hashes permanentes de PII**; el hash que se guarda es el del **contenido aprobado**
  (evidencia de firma), no de datos personales.
- La auditoría es consultable por API/tablero respetando ACL; **nunca** por SQL directo a
  tablas core.

## Definition of Done (seguridad, por módulo)
ACL por rol · aislamiento de entidad probado (🔒 fail-closed) · auditoría append-only · CSRF
nativo en acciones · validación de entrada/archivos · logs sin secretos · verificación de core
intacto.
