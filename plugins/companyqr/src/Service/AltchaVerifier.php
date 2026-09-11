<?php

/**
 * Verificación de solución Altcha (anti-bot) usando el AltchaManager NATIVO de GLPI.
 *
 * GLPI 11.0.8: `Glpi\Altcha\AltchaManager` es singleton; `verifySolution()` y
 * `removeChallenge()` son métodos de INSTANCIA. Tras verificar una solución válida se
 * elimina el challenge para impedir REPLAY.
 *
 * Servicio aislado para poder testear el punto de integración (rechazo de payload
 * vacío/ilegible) de forma determinista.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Service;

final class AltchaVerifier
{
    /** ¿Está disponible el AltchaManager nativo? */
    public function available(): bool
    {
        return class_exists(\Glpi\Altcha\AltchaManager::class);
    }

    /**
     * Verifica una solución Altcha. Rechaza payload vacío/ilegible sin tocar el manager.
     * Para una solución válida elimina el challenge (anti-replay) y devuelve true.
     */
    public function isValid(string $payload): bool
    {
        if (trim($payload) === '') {
            return false;
        }
        if (!$this->available()) {
            return false;
        }
        try {
            $manager = \Glpi\Altcha\AltchaManager::getInstance();
            if (!$manager->verifySolution($payload)) {
                return false;
            }
            $manager->removeChallenge($payload); // impedir replay
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
