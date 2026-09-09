# CLAUDE.md - Reglas del proyecto Plataforma Interna / GLPI

## Misión
Construir una plataforma empresarial modular sobre GLPI sin convertir el fork del core en nuestro producto.

## REGLA 0 - NO TOCAR EL CORE
Está PROHIBIDO modificar archivos del core GLPI.
Orden obligatorio:
1. Configuración nativa.
2. Plugin existente evaluado.
3. Plugin propio usando APIs/hooks oficiales.
4. Servicio externo integrado por API/webhook.
5. Core únicamente con excepción aprobada explícitamente por un humano y ADR que justifique por qué 1-4 son imposibles.

Si creés que necesitás editar core: DETENETE. No implementes. Entregá diagnóstico, archivos afectados, motivo, alternativas y riesgo de upgrade.

## Objetivo de compatibilidad
- Base de producción: última release estable validada en staging.
- No usar RC/beta en producción.
- Cada plugin debe declarar rango de versiones GLPI soportadas.
- Las upgrades mayores requieren suite de regresión.

## Localización
- Default locale: es.
- Idioma secundario: en.
- Timezone: America/Asuncion.
- Moneda: PYG.
- Fecha visual recomendada: dd/mm/yyyy.
- 24h.
- Nada de textos de negocio hardcodeados: i18n.

## Arquitectura
GLPI: ITSM, inventario/CMDB, usuarios, entidades, SLA, conocimiento.
Plugins propios:
- company-portal
- company-purchasing
- company-workflow
- company-qr
- company-dashboard
- company-signature
- company-integrations

Servicios separados:
- ai-assistant
- whatsapp-adapter
- integration-hub

## Compras
Workflow inicial:
BORRADOR -> ENVIADA -> JEFE_AREA -> COMPRAS -> GERENCIA_FINANCIERA ->
APROBADA/RECHAZADA -> EN_COMPRA -> RECIBIDA -> ENTREGADA -> CERRADA.

El workflow debe ser configurable. No codificar nombres de personas como aprobadores.
Cada transición guarda actor, fecha/hora, comentario, estado anterior/nuevo y evidencia.

## Firma
No confundir imagen de firma con firma digital.
Aprobación electrónica interna: identidad autenticada + acción explícita + auditoría + hash + timestamp.
Firma digital certificada: integración separada cuando el negocio/legal la exija.

## API
- Preferir REST API v2 oficial de GLPI cuando cubra el caso.
- APIs propias bajo /api/v1.
- OAuth2/tokens de mínimo privilegio.
- Webhooks + correlation_id.
- Escrituras externas idempotentes.
- OpenAPI para servicios propios.
- No acceder directamente a tablas core si existe interfaz soportada.

## IA
- RAG sólo sobre fuentes autorizadas.
- Aplicar ACL antes de recuperar contenido.
- Citar fuente interna en respuestas.
- No aprender automáticamente de cualquier ticket.
- Soluciones nuevas pasan por curación/aprobación.
- Si no hay evidencia suficiente: escalar/crear ticket.
- Registrar métricas y acciones de IA.

## Seguridad
- RBAC y mínimo privilegio.
- Secrets fuera de Git.
- Validar input y archivos.
- Auditoría para cambios sensibles.
- Logs estructurados sin secretos.
- Backups y restauración probados.
- Dependencias fijadas y escaneadas.

## Definition of Done
Una tarea NO está terminada si falta cualquiera de:
- código;
- migración reversible si corresponde;
- autorización/ACL;
- i18n ES/EN;
- auditoría;
- métricas/logs;
- tests;
- documentación;
- changelog;
- verificación de que no se modificó core.

## Antes de cada implementación
1. Identificar capacidad nativa GLPI.
2. Revisar documentación oficial y hooks/API de la versión objetivo.
3. Escribir mini ADR: Configure / Extend / Integrate / Build.
4. Diseñar permisos, auditoría y métricas.
5. Recién después programar.

## Prohibiciones
- No editar vendor/.
- No monkey-patching del core.
- No SQL directo contra tablas core para saltar reglas de negocio.
- No credenciales en código.
- No aprobadores, SLA, URLs o departamentos hardcodeados.
- No desplegar sin staging.
- No asumir que un plugin compatible con una major anterior funciona en una nueva major sin pruebas.

## Prioridad del producto
Actualizable > modular > auditable > seguro > configurable > rápido de implementar.
