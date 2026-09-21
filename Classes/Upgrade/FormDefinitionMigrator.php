<?php

declare(strict_types=1);

namespace Subugoe\Typo3Cap\Upgrade;

/**
 * Pure form-definition transformation: hCaptcha form element and validator -> Cap.
 */
final class FormDefinitionMigrator
{
    public static function needsMigration(array $definition): bool
    {
        return self::migrateDefinition($definition) !== $definition;
    }

    public static function migrateDefinition(array $definition): array
    {
        if (isset($definition['renderables']) && is_array($definition['renderables'])) {
            $definition['renderables'] = self::migrateRenderables($definition['renderables']);
        }

        return $definition;
    }

    private static function migrateRenderables(array $renderables): array
    {
        foreach ($renderables as $key => $renderable) {
            if (!is_array($renderable)) {
                continue;
            }
            if (($renderable['type'] ?? '') === 'Hcaptcha') {
                $renderable['type'] = 'Cap';
            }
            if (isset($renderable['renderables']) && is_array($renderable['renderables'])) {
                $renderable['renderables'] = self::migrateRenderables($renderable['renderables']);
            }
            if (isset($renderable['validators']) && is_array($renderable['validators'])) {
                foreach ($renderable['validators'] as $validatorKey => $validator) {
                    if (is_array($validator) && ($validator['identifier'] ?? '') === 'Hcaptcha') {
                        $renderable['validators'][$validatorKey]['identifier'] = 'Cap';
                    }
                }
            }
            $renderables[$key] = $renderable;
        }

        return $renderables;
    }
}
