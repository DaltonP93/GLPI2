# Análisis native-first — Fase 2 (workflow · compras · firma)

Objetivo: antes de diseñar/construir nada, determinar qué de **GLPI 11.0.8** se reutiliza y
qué realmente construimos. Clasificación por requisito:
**Configure** (config nativa) · **Existing** (plugin existente) · **Extend** (plugin propio
vía hook/API) · **Integrate** (servicio/API/webhook) · **Build** (desarrollo propio).

> **Core = dependencia upstream.** Todas las rutas `src/...` citadas son del repositorio
> oficial **`glpi-project/glpi` @ `11.0.8`**, no de `DaltonP93/GLPI2`. **No se modifica el
> core** (Regla 0). Verificado por búsqueda de código en el repo upstream.

## Matriz de decisión

| # | Requisito | Nativo en GLPI 11.0.8 | Evidencia (`glpi-project/glpi@11.0.8`) | Decisión | Nota |
|---|---|---|---|---|---|
| 1 | **Proveedores** | ✅ `Supplier` (+ `Contact_Supplier`, `Contract_Supplier`) | `src/Supplier.php` | **Reuse (Configure)** | Selección de proveedor referencia `suppliers_id`; no recrear catálogo. |
| 2 | **Presupuestos** | ✅ `Budget` | `src/Budget.php` | **Reuse** | Imputar solicitud/orden a `budgets_id`. |
| 3 | **Información financiera de activos** | ✅ `Infocom` (costo compra, proveedor, presupuesto, garantía, orden) | `src/Infocom.php` | **Reuse** | Al recibir e inventariar, poblar `Infocom` del activo (costo/proveedor/presupuesto). |
| 4 | **Documentos / adjuntos** | ✅ `Document` + `Document_Item` (MIME, tamaño, versiones de archivo) | `src/Document_Item.php` | **Reuse** | Cotizaciones y archivos como `Document` vinculados por `Document_Item`. |
| 5 | **Usuarios / grupos / entidades / RBAC** | ✅ `User`, `Group`, `Group_User`, `Entity`, `Profile`, ACL multi-entidad | núcleo GLPI | **Reuse** | Aprobadores por **rol/grupo**; aislamiento por **entidad**. |
| 6 | **Reglas de negocio** | ✅ Motor `Rule` (criterios/acciones) | `src/Rule.php`, `front/rule.php` | **Reuse parcial** | Reutilizar patrón para enrutar/asignar; las **condiciones de transición** (monto/categoría) viven en `companyworkflow` (evaluador propio acotado). |
| 7 | **Notificaciones** | ✅ `Notification` + `NotificationTemplate` (+ targets, colas) | `src/NotificationTemplate.php`, `src/Notification_NotificationTemplate.php` | **Reuse (Extend)** | Registrar eventos/plantillas del plugin; no construir motor de correo. |
| 8 | **Estados (dropdown)** | ✅ `State` + trait `Glpi\Features\State` | `src/State.php`, `src/Glpi/Features/State.php` | **No reuse para workflow** | El `State` nativo es para **activos**. Los estados de negocio los define **`companyworkflow`** (configurables). |
| 9 | **Aprobación multi-nivel (validación)** | ✅ `CommonITILValidation` + `ValidationStep` (pasos, % requerido, targets user/group) | `src/CommonITILValidation.php`, `src/ValidationStep.php`, `src/TicketValidationStep.php` | **Build (inspirado en nativo)** | **Nativo sólo aplica a objetos ITIL (Ticket/Change).** No es un motor genérico para objetos arbitrarios ni cubre devolución/delegación/SLA por etapa. Ver ADR-0012 §Alternativas. |
| 10 | **Auditoría / historial** | ✅ `Log` (historial de campos) + `Glpi\Event` | `src/Log.php`, `src/Glpi/Event.php` | **Reuse + Build** | `Log` para cambios de campo; **tabla append-only propia** para eventos de negocio semánticos (aprobar/rechazar/cambio de monto…). |
| 11 | **PDFs** | ✅ TCPDF incluido | `composer.json` (tecnickcom/tcpdf) | **Reuse** | Mismo enfoque que `companyqr` (documento aprobado + QR de verificación). |
| 12 | **API REST** | ✅ **API REST v2** (High-Level API) | `src/Glpi/Api/HL/*` | **Reuse + Extend** | Lectura/escritura estándar por API v2; endpoints propios `/api/v1` sólo para lo que v2 no cubra. |
| 13 | **Webhooks** | ✅ **Webhooks nativos** (`Webhook`, `QueuedWebhook`, `WebhookCategory`) | `src/Webhook.php`, `src/QueuedWebhook.php` | **Reuse (Integrate)** | Emisión de eventos salientes por webhook nativo; no construir cola de webhooks propia. |
| 14 | **Formularios de autoservicio** | ✅ **Forms** (GLPI 11) → Ticket/Change | `src/Glpi/Form/*` | **Evaluar (spike)** | Forms puede capturar una solicitud simple, pero **no** modela ítems múltiples/cotizaciones/estados de compra. Ver ADR-0013 §Forms. |
| 15 | **QR / etiqueta / activo** | ✅ ya resuelto en Fase 1 | `plugins/companyqr` (nuestro) | **Reuse (companyqr)** | Recepción→activo→QR reutiliza `companyqr` (ADR-0011). |
| 16 | **SLA / escalamiento** | ✅ `SLA`/`OLA` + niveles de escalado (ITIL) | núcleo GLPI | **Build (inspirado)** | SLA nativo es ITIL-only; el SLA por **etapa** del workflow se define en `companyworkflow`. |

## Conclusiones

1. **Reutilizamos masivamente** el núcleo administrativo de GLPI: proveedores, presupuestos,
   Infocom, documentos, usuarios/grupos/entidades/RBAC, notificaciones, `Log`, TCPDF, API v2 y
   **webhooks nativos**. Nada de esto se reconstruye.
2. **Construimos** sólo el diferencial que GLPI **no** ofrece de forma genérica:
   - `companyworkflow`: **motor de workflow configurable y reusable** (estados/transiciones/
     aprobadores/niveles/condiciones/SLA/escalamiento/delegación/devolución) para **cualquier**
     objeto de negocio, no atado a ITIL. GLPI sólo tiene validación **ITIL-bound**.
   - `companypurchasing`: el **dominio de compras** (solicitud, ítems, cotizaciones, montos
     PYG, proveedor, recepción) que **consume** `companyworkflow` (no duplica motor).
   - `companysignature`: **evidencia de aprobación electrónica interna** (identidad + acción +
     hash de contenido versionado + timestamp + auditoría) y un **adaptador futuro** para firma
     digital certificada. GLPI registra aprobador/comentario pero **no** hash de documento
     versionado ni versionado que invalide aprobaciones.
3. **Principio heredado de Fase 1:** *el identificador identifica; GLPI autoriza.* Toda vista y
   acción pasa por **ACL nativa + entidad**. La IA **nunca** aprueba por sí misma.

## Regla 0
Sólo plugins + hooks/controladores/API/webhooks soportados. **Sin modificar el core.** Se
verificará por `tests/upgrade/verify-core-untouched.sh` y CI, como en Fase 1.
