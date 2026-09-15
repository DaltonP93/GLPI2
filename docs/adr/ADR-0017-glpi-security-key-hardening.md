# ADR-0017: Pre-provisión de `glpicrypt.key` — workaround del bug de `kernel.secret` (GLPI 11)

- **Estado:** Aceptado
- **Fecha:** 2026-09-15
- **Decisores:** Plataforma / Infraestructura
- **Módulo/área:** Transversal — infraestructura de instalación/CI (seguridad)

## Contexto

GLPI 11.0.8 genera una **clave de seguridad** en `config/glpicrypt.key`: una clave
**binaria de exactamente 32 bytes** producida con
`sodium_crypto_aead_chacha20poly1305_ietf_keygen()` (bytes crudos, sin codificar).

En GLPI 11 esa clave alimenta el `kernel.secret` de Symfony. En
`dependency_injection/services.php`:

```php
$parameters->set('glpi.default_secret', bin2hex(random_bytes(32)));
$parameters->set('env(APP_SECRET_FILE)', $projectDir . '/config/glpicrypt.key');
$parameters->set('kernel.secret', env('default:glpi.default_secret:file:APP_SECRET_FILE'));
```

Es decir, **`kernel.secret` = contenido crudo de `config/glpicrypt.key`**.

### Síntoma observado

`bin/console database:enable_timezones` (y potencialmente **cualquier** `bin/console`
que bootee los plugins) falla de forma **intermitente** con:

```
Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException
The parameter "kernel.secret" has a dependency on a non-existent parameter "…".
```

### Causa raíz (bug upstream de GLPI 11)

Symfony, al **compilar** el contenedor (`ResolveParameterPlaceHoldersPass`),
interpreta `%...%` dentro de los valores de parámetros como **referencias a otros
parámetros**. Si la clave binaria de 32 bytes contiene el byte `%` (0x25), el valor
de `kernel.secret` contiene `%algo%` y Symfony intenta resolver un parámetro
inexistente → `ParameterNotFoundException`. Rompe la compilación del contenedor de
plugins (`src/Glpi/DependencyInjection/PluginContainer.php`) y por lo tanto cualquier
comando de consola. Es **intermitente**: depende de que la clave aleatoria contenga
un `%` (~11–12% de las claves de 32 bytes).

> Es un bug **upstream** de GLPI 11 (la clave binaria no se escapa/normaliza antes de
> usarse como `kernel.secret`). **No** es un problema de nuestros plugins ni de nuestra
> infraestructura, pero nos afecta en CI y en instalaciones limpias.

## Decisión

**Pre-provisionar `config/glpicrypt.key` ANTES de `database:install`** con una clave
**criptográficamente aleatoria de 32 bytes** que **no contenga `%`**, para que
`kernel.secret` quede siempre libre de `%`. Es un **workaround de infraestructura**,
sin tocar el core ni cambiar el algoritmo criptográfico de GLPI.

Es viable porque GLPI **sólo genera la clave si no existe**. En el comando de
instalación (`src/Glpi/Console/Database/InstallCommand.php`):

```php
$glpikey = new GLPIKey();
if (!$glpikey->keyExists() && !$glpikey->generate(update_db: false)) { /* error */ }
```

Al existir ya una clave válida, GLPI la **respeta** (no la regenera) y cifra todo con ella.

Implementación (`infra/docker/glpi-config/provision-security-key.sh`, ejecutado por CI
antes de la instalación):

1. Genera, **dentro del contenedor y como `www-data`**, `random_bytes(32)` (CSPRNG).
2. **Rejection sampling:** descarta cualquier candidata que contenga `%` **antes de
   escribirla** (tope de seguridad fail-closed), siempre con aleatoriedad criptográfica.
3. Escribe **exactamente 32 bytes** (válidos para Sodium), `chmod 0640`, propietario
   `www-data`. **Nunca imprime la clave.**
4. Verifica fail-closed: existe, 32 bytes, propietario correcto, sin acceso para "otros",
   sin `%`.

Regresión (`tests/security/verify-glpi-security-key.sh` +
`tests/security/glpi-kernel-secret-repro.php`), ejecutada en CI:

- **Reproducción determinista** del bug con el **mismo** componente
  `symfony/dependency-injection`: un `kernel.secret` con `%...%` rompe `compile()`;
  sin `%` compila. (Demuestra el riesgo **antes** del fix y que el fix lo elimina.)
- La clave provisionada cumple todas las invariantes (32 bytes, `www-data`, sin `%`,
  sin acceso "otros").
- Varios `bin/console` consecutivos **sin** `ParameterNotFoundException`
  (contenedor Symfony + plugins compilando).
- El generador **nunca** produce `%` (muestreo CSPRNG con N grande): el origen del
  flake queda eliminado por construcción.

### Prohibiciones respetadas

- **No** clave estática/commiteada; **no** contraseña legible.
- **No** hex de 64 chars ni base64 usados crudos como `glpicrypt.key` (GLPI espera 32
  **bytes** binarios; un hex/base64 crudo tendría longitud/semántica incorrecta).
- **No** borrado silencioso de la clave; **no** `|| true`; **no** "reintentar hasta que
  por azar salga una clave buena" (el rejection sampling descarta la candidata con `%`
  antes de escribirla, no reintenta el pipeline hasta pasar).
- La entropía se mantiene: excluir el byte `%` reduce el espacio de 256^32 a 255^32
  (~0.18 bits en total), despreciable; la clave sigue siendo uniformemente aleatoria y
  criptográficamente fuerte.

## Alternativas consideradas

- **A — Regenerar/rotar la clave después de `database:install`** (p. ej.
  `security:change_key`). Rechazada: ese comando **bootea el kernel completo**, así que
  fallaría con el mismo `ParameterNotFoundException` si la clave ya tiene `%`
  (problema del huevo y la gallina); además re-cifra datos innecesariamente.
- **B — Escribir la clave cruda tras la instalación** (bypass de GLPIKey). Rechazada:
  huérfanaría secretos ya cifrados durante la instalación con la clave anterior.
- **C — (elegida) Pre-provisionar antes de `database:install`.** GLPI la respeta, cifra
  todo con ella desde el inicio, sin huérfanos y sin tocar core.
- **D — `|| true` / reintentos de CI.** Rechazada: oculta el error y deja el flake vivo.
- **E — Parchear el core para escapar `%` en `kernel.secret`.** Rechazada por Regla 0
  (requeriría excepción aprobada). Este workaround evita tocar core.

Si en algún momento no fuera posible un workaround seguro sin alterar la semántica
criptográfica de GLPI, la decisión es **detenerse y reportar**, no improvisar.

## Consecuencias

**Positivas**
- CI e instalaciones limpias **deterministas**: desaparece el `ParameterNotFoundException`.
- Sin tocar core ni cambiar el algoritmo/semántica criptográfica (sigue siendo una clave
  Sodium de 32 bytes de un CSPRNG).
- Regresión que reproduce el bug y evita reintroducirlo.

**Negativas / costos**
- Un script y una prueba adicionales de infraestructura.
- Acoplamiento a un detalle upstream (`kernel.secret` = clave cruda). Cuando GLPI corrija
  el bug upstream (escapando/normalizando la clave), este workaround podrá retirarse; la
  regresión seguirá siendo válida como prueba de no-regresión.

## Cumplimiento de la Regla 0

No se modifica el **core** de GLPI. Sólo se agrega un script de infraestructura
(`infra/docker/glpi-config/provision-security-key.sh`), pruebas
(`tests/security/*`) y un paso de CI. Se escribe un archivo de configuración que **GLPI
mismo genera y soporta** (`config/glpicrypt.key`), respetando su formato (32 bytes
binarios). No hay parche de core ni cambio del algoritmo criptográfico.
