# Riesgos — Fase 2 (compras · workflow · firma)

Registro de riesgos del diseño, con mitigación. **Diseño, sin implementación.**

| # | Riesgo | Prob. | Impacto | Mitigación |
|---|---|---|---|---|
| R1 | Reconstruir de más lo que GLPI ya hace (proveedores, presupuestos, notif.) | Media | Alto | Native-first (`native-first-phase2.md`) — sólo se construye el motor/dominio/evidencia. |
| R2 | Acoplar el motor a Compras (no reusable) | Media | Alto | `companyworkflow` sin conocimiento de dominio; API por `itemtype`/`items_id`; test con un 2º dominio ficticio. |
| R3 | Fuga entre entidades (aprobar/ver de otra entidad) | Media | **Crítico** | ACL + entidad en cada acción; test 🔒 fail-closed en CI (como companyqr). |
| R4 | Aprobadores/montos/departamentos hardcodeados | Media | Alto | Todo por rol/grupo/config; lint/review; sin literales de negocio. |
| R5 | Invalidación de aprobaciones mal definida (editar tras aprobar) | Alta | Alto | Campos "sustantivos" declarados; contrato firma↔workflow; eventos `approval_invalidated`; tests. |
| R6 | Hash de evidencia no reproducible (metadatos volátiles del PDF) | Media | Alto | Hash sobre **canónica del contenido**, no del PDF; test de determinismo. |
| R7 | Confundir imagen de firma con firma digital | Baja | **Crítico (legal)** | ADR-0014 separa evidencia interna vs. firma certificada; PNG nunca es prueba. |
| R8 | Handoff a inventario duplica activos/códigos | Media | Medio | Idempotencia por clave natural; reintento seguro; test. |
| R9 | Orden de dependencias entre plugins roto | Media | Medio | `check_prerequisites` en `setup.php`; documentado; CI instala en orden. |
| R10 | SLA/escalamiento que "aprueba solo" o spamea | Baja | Alto | Escalamiento sólo **notifica/enruta**; nunca decide; límites y config. |
| R11 | IA aprobando compras | Baja | **Crítico** | Regla dura: IA nunca aprueba; sólo lectura/sugerencia/enrutado autenticado (ADR-0008/0014). |
| R12 | Migraciones no reversibles / pérdida de historial | Baja | Alto | Migraciones reversibles; auditoría **append-only**; nunca borrado silencioso. |
| R13 | Upgrade a GLPI 12 rompe validación/webhooks nativos usados | Media | Alto | Rango `>=11 <12`; suite de regresión antes de subir major; adaptadores aíslan el core. |
| R14 | Rendimiento de tableros/métricas sobre muchas solicitudes | Media | Medio | Snapshots denormalizados (`current_state_code`), índices, agregación por API; no SQL a core. |
| R15 | Complejidad de entrega (3 plugins juntos) | Alta | Medio | Diseñar juntos, **entregar por PRs separados** por plugin; mismo pipeline de 3 niveles de test. |

## Deuda técnica arrastrada
- **CI:** `actions/checkout` y `actions/upload-artifact` en Node 20 (GitHub fuerza Node 24);
  follow-up separado, no bloquea Fase 2.
