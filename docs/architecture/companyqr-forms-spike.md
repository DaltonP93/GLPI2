# Spike: ¿reutilizar Forms nativo de GLPI 11 para el reporte de problema?

**Objetivo:** determinar si el flujo `QR → activo resuelto → Form nativo → ticket
asociado al activo` es posible de forma **soportada** (sin tocar core), precargando y
fijando el **activo escaneado**.

## Evidencia de código (GLPI 11.0.8)

| Hallazgo | Archivo | Implicancia |
|---|---|---|
| Form puede crear Ticket | `src/Glpi/Form/Destination/FormDestinationTicket.php`, `FormDestinationManager.php` | ✅ Forms → Ticket soportado |
| Creación como sistema (sirve anónimo) | `src/Glpi/Form/Destination/AbstractCommonITILFormDestination.php` (`callAsSystem`) | ✅ ticket sin rights del emisor |
| Asociar el ticket a un activo | `src/Glpi/Form/Destination/CommonITILField/AssociatedItemsField.php` + `AssociatedItemsFieldStrategy.php` (usa `QuestionTypeItem`, `QuestionTypeUserDevice`) | ✅ el ticket puede quedar vinculado a un ítem **elegido en una pregunta** |
| **Precargar/lockear un ítem desde contexto externo (URL/QR)** | búsqueda de `prefill` en `src/Glpi/Form` → **0 resultados** | ❌ no se evidencia vía soportada de prefill/lock |

## Análisis
- El vínculo activo↔ticket nativo proviene de la **respuesta del usuario** a una pregunta
  (`QuestionTypeItem` = elegir un ítem; `QuestionTypeUserDevice` = elegir entre los
  dispositivos del usuario autenticado), mapeada por `AssociatedItemsField` al ticket.
- Para nuestro caso el activo **no lo elige el usuario**: es **exactamente el que se
  escaneó**. No encontramos un mecanismo soportado (`prefill`, parámetro de URL, contexto)
  para inyectar y **fijar** ese activo en el Form.
- Forzarlo requeriría, o bien que el usuario re-seleccione el activo (frágil, propenso a
  error, rompe "es este activo"), o bien **modificar el core/Forms** (prohibido por Regla 0).

## Limitación del entorno
En esta sesión **no hay daemon Docker**, así que el spike es **a nivel de código** (no
runtime). La confirmación definitiva se hará con un **test de integración** en el stack
DEV/CI.

## Test de integración gate (primer paso de implementación, antes de construir el form)
1. Crear un Form nativo con destino Ticket y una pregunta `QuestionTypeItem`.
2. Intentar abrir el Form con el activo **preseleccionado y bloqueado** vía un
   mecanismo soportado (parámetro de ruta/URL/opción de render), sin editar core.
3. Enviar y verificar que el ticket queda **vinculado (Item_Ticket)** al activo correcto.
4. Repetir anónimo (si el modo estуviera habilitado).

**Criterio de decisión (regla del usuario):**
- ✅ Si funciona limpio y soportado → **reutilizar Forms** (actualizar este doc y el ADR).
- ❌ Si no funciona o exige tocar core → **formulario mínimo propio** vía `TicketCreator`
  (documentar la evidencia aquí y en el ADR-0011) — es la hipótesis actual.

## Conclusión provisional
La evidencia de código **inclina a formulario mínimo propio** para garantizar el vínculo
al activo exacto sin tocar core. Decisión final sujeta al test gate anterior, que se
ejecuta y reporta **antes** de escribir el formulario.
