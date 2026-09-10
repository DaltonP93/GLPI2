# ADR-0005: Localización Paraguay (es / PYG / America\_Asuncion)

- **Estado:** Aceptado
- **Fecha:** 2026-09-09
- **Decisores:** Producto, plataforma
- **Módulo/área:** Transversal

## Contexto
La plataforma opera en Paraguay. GLPI soporta multi-idioma, zonas horarias por
usuario y monedas, todo configurable de forma nativa.

## Decisión
Valores por defecto de la plataforma:

- **Idioma:** Español (`es_ES`) por defecto; **Inglés** habilitable sin duplicar
  lógica (claves de traducción / catálogos i18n).
- **Zona horaria:** `America/Asuncion` en servidor y aplicación; timestamps
  almacenados de forma consistente y mostrados en hora de Paraguay. Requiere
  cargar las tablas de husos horarios en MariaDB (ver `infra/docker/README.md`).
- **Moneda funcional:** **PYG** (guaraní); importes con separador de miles y sin
  decimales por defecto, salvo configuración explícita.
- **Fecha/hora:** formato configurable; recomendación visual `dd/mm/aaaa` y 24 h.
- **Prohibido hardcodear** textos de negocio, moneda, zona horaria, URLs,
  departamentos, aprobadores o SLA: todo por configuración/catálogos.

## Alternativas consideradas
- **Textos fijos en español en el código** — descartado: impide inglés y viola
  las prohibiciones del proyecto.
- **Configuración nativa de GLPI + i18n en plugins (elegida)**.

## Consecuencias
- (+) Cumple requisitos locales sin código específico de país incrustado.
- (+) Preparado para inglés y para otras entidades/monedas si el negocio lo pide.
- (−) Disciplina permanente de i18n en cada módulo (parte del *Definition of Done*).

## Cumplimiento de la Regla 0
Configuración nativa + i18n en plugins. Sin edición de core.
