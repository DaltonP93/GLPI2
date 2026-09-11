# Spike: ¿reutilizar Forms nativo de GLPI 11 para el reporte de problema?

**Objetivo:** determinar si el flujo `QR → activo resuelto → Form nativo → ticket
asociado al activo` es posible de forma **soportada** (sin tocar core), precargando y
fijando el **activo escaneado**.

> **Nota sobre las rutas de código citadas.** El core de GLPI **no vive en este
> repositorio** (`DaltonP93/GLPI2`); es una **dependencia upstream**. Todas las rutas
> `src/...`, `templates/...`, `composer.json`, etc. de este documento se refieren al
> repositorio oficial **`glpi-project/glpi`**, en el **tag `11.0.8`**
> (`https://github.com/glpi-project/glpi/tree/11.0.8`), no a código nuestro.

## Evidencia de código (`glpi-project/glpi` @ `11.0.8`)

| Hallazgo | Archivo (en `glpi-project/glpi@11.0.8`) | Implicancia |
|---|---|---|
| Form puede crear Ticket | `src/Glpi/Form/Destination/FormDestinationTicket.php`, `FormDestinationManager.php` | ✅ Forms → Ticket soportado |
| Creación como sistema (sirve anónimo) | `src/Glpi/Form/Destination/AbstractCommonITILFormDestination.php` (`callAsSystem`) | ✅ ticket sin rights del emisor |
| Asociar el ticket a un activo | `src/Glpi/Form/Destination/CommonITILField/AssociatedItemsField.php` + `AssociatedItemsFieldStrategy.php` (usa `QuestionTypeItem`, `QuestionTypeUserDevice`) | ✅ el ticket puede quedar vinculado a un ítem **elegido en una pregunta** |
| **Precargar/lockear un ítem desde contexto externo (URL/QR)** | análisis estático de `src/Glpi/Form` (búsqueda de `prefill` = 0 resultados) | ⚠️ **no se identificó** (ver más abajo) |

## Análisis

- El vínculo activo↔ticket nativo proviene de la **respuesta del usuario** a una pregunta
  (`QuestionTypeItem` = elegir un ítem; `QuestionTypeUserDevice` = elegir entre los
  dispositivos del usuario autenticado), mapeada por `AssociatedItemsField` al ticket.
- Para nuestro caso el activo **no lo elige el usuario**: es **exactamente el que se
  escaneó**. Necesitaríamos **inyectar y fijar** ese activo en el Form desde el contexto
  del QR.
- **Resultado del análisis estático (redacción precisa):** *no se identificó durante el
  análisis estático una API soportada/documentada para precargar y bloquear el activo
  proveniente del QR en un Form nativo.* La ausencia del término `prefill` en
  `src/Glpi/Form` es un indicio, **no** una prueba definitiva de que no exista ninguna vía
  soportada. **El test de integración en runtime es el gate definitivo.**

## Limitación del entorno

En la sesión de diseño **no hay daemon Docker**, así que el spike es **a nivel de código**
(no runtime). La confirmación definitiva se hace con un **test de integración** en el
stack de CI (GitHub Actions sí levanta el stack), **antes** de escribir el formulario.

## Test de integración gate (primer paso de implementación, antes de construir el form)

Implementado en `plugins/companyqr/tests/integration/forms_gate.php` y ejecutado por el
job `integration` de CI. Pasos:

1. Crear un Form nativo con destino Ticket y una pregunta `QuestionTypeItem`.
2. Intentar abrir/renderizar el Form con el activo **preseleccionado y bloqueado** vía un
   mecanismo **soportado** (parámetro de ruta/URL/opción de render), **sin editar core**.
3. Enviar y verificar que el ticket queda **vinculado (`Item_Ticket`)** al activo correcto.
4. Repetir el caso anónimo (si el modo estuviera habilitado).

**Criterio de decisión (regla del usuario):**

- ✅ Si funciona limpio y soportado → **reutilizar Forms** (se actualiza este doc y el ADR
  con la evidencia runtime y se emite un ADR de seguimiento para cambiar el motor).
- ❌ Si no funciona o exige tocar core → **formulario mínimo propio** vía `TicketCreator`
  (se documenta la evidencia runtime aquí y en `ADR-0011`).

## Decisión de implementación (v1)

Dado que (a) el análisis estático **no** identificó una vía soportada de prefill/lock y
(b) el gate runtime **no puede** ejecutarse en la sesión de diseño (sin Docker), se
implementa el **formulario mínimo propio** vía `TicketCreator` como camino **conservador y
100 % bajo control** (cumple Regla 0, garantiza el vínculo al activo exacto y no bloquea la
entrega). En paralelo, el **gate de Forms queda como test de integración de CI**: si en
runtime demuestra una vía soportada y limpia, se registra la evidencia y se abre un ADR de
seguimiento para migrar el motor de reporte a Forms nativo. Así respetamos native-first sin
que una capacidad no confirmada bloquee la Fase 1.
