# Funcional — Portal tipo Jira Service Management (`companyportal`)

## Objetivo
Experiencia de autoservicio simple para el usuario final, sin exponer la
complejidad administrativa de GLPI, **extendiendo el Self-Service Portal nativo**.

## Requisitos
- **Catálogo de servicios por tarjetas:** Soporte TI, Accesos, Equipos, Compras,
  Sistemas, Redes, Telefonía y servicios futuros (configurable).
- **Formularios condicionales** (mostrar sólo campos relevantes) con **Forms nativo**.
- Seguimiento simple para el usuario; colas/vistas operativas para técnicos.
- Estados comprensibles para usuarios + estados técnicos internos cuando aplique.
- Tickets vinculados a activos, usuarios, ubicaciones y artículos de KB.
- Experiencia **responsive** y accesible.

## Estrategia native-first
- **Configurar/Extender:** el catálogo y los formularios usan **Forms** y el
  **Self-Service Portal** nativos de GLPI 11. `companyportal` sólo aporta
  personalizaciones de UI/UX vía hooks soportados (sin editar plantillas del core).
- No se construye un motor de formularios ni un portal desde cero.

## i18n / Localización
Español por defecto, inglés habilitable; fechas `dd/mm/aaaa`, 24 h.

## Referencias
- `../architecture/glpi11-findings.md` (Forms, Self-Service Portal)
- `../adr/ADR-0001-platform-architecture.md`
