# Riesgos identificados (Fase 0)

| # | Riesgo | Impacto | Probabilidad | Mitigación |
|---|--------|---------|--------------|------------|
| R1 | Salto de major GLPI 11 → 12 rompe plugins | Alto | Media | Cada plugin declara rango de versiones; suite de actualización obligatoria en staging antes de producción (`../operations/glpi-upgrade-test.md`). **12.0.0-rc1 no va a producción.** |
| R2 | Presión por "parchear el core" ante un bloqueo | Alto | Media | Regla 0 + ADR de excepción con aprobación humana; verificación automatizada `tests/upgrade/verify-core-untouched.sh`. |
| R3 | Formcreator EOL / formularios heredados | Medio | Baja | Usar **Forms nativo**; si hay formularios viejos, migrar con la herramienta oficial (GLPI 11.0+). |
| R4 | Firma electrónica sin validez legal esperada | Alto | Media | Distinguir nivel A (interno) y B (certificado); validar con Legal antes de reemplazar papel con efectos externos (`../adr/ADR-0007-*`). |
| R5 | IA filtra información sin ACL | Alto | Baja | RAG aplica ACL antes de recuperar; citar fuente; auditar (`../adr/ADR-0008-*`). |
| R6 | Integraciones sin idempotencia generan duplicados | Medio | Media | Escrituras idempotentes + `correlation_id`; tablero de estado en `integration-hub`. |
| R7 | Secretos filtrados en Git/logs | Alto | Baja | `.gitignore` de secretos, `.env` fuera de Git, secret manager, logs sin secretos (`../security/secrets-management.md`). |
| R8 | Zona horaria mal configurada (timestamps) | Medio | Media | Cargar tablas de husos en MariaDB; almacenar consistente y mostrar en America/Asuncion. |
| R9 | Imagen Docker depende de descarga upstream | Bajo | Media | Versión fijada (`GLPI_VERSION`); cachear tarball/artefacto en CI; el core no se versiona por diseño. |
| R10 | Deriva entre entornos dev/staging/prod | Medio | Media | Staging idéntico a producción; infra reproducible; smoke tests y plan de rollback. |

## Decisiones abiertas (a resolver en ADRs de implementación)
- Pila de observabilidad (logs/métricas/dashboards) — ADR pendiente.
- Stack de los servicios (`ai-assistant`, `whatsapp-adapter`, `integration-hub`).
- Proveedor de LLM para IA y proveedor de firma digital certificada.
- Proveedor de WhatsApp Business API.
- Licencia formal del repositorio (propuesta: GPL-3.0-or-later para plugins).
