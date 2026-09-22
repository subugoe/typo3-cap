<?php

declare(strict_types=1);

namespace Subugoe\Typo3Cap\Upgrade;

/**
 * Pure form-definition transformation: a captcha form element and validator -> Cap.
 */
final class FormDefinitionMigrator
{
    public static function needsMigration(array $definition, string $from): bool
    {
        return self::migrateDefinition($definition, $from) !== $definition;
    }

    public static function migrateDefinition(array $definition, string $from): array
    {
        if (isset($definition['renderables']) && is_array($definition['renderables'])) {
            $definition['renderables'] = self::migrateRenderables($definition['renderables'], $from);
        }
        if (isset($definition['renderingOptions']['fieldState']) && is_array($definition['renderingOptions']['fieldState'])) {
            $definition['renderingOptions']['fieldState'] = self::migrateRenderables($definition['renderingOptions']['fieldState'], $from);
        }

        return $definition;
    }

    private static function migrateRenderables(array $renderables, string $from): array
    {
        foreach ($renderables as $key => $renderable) {
            if (!is_array($renderable)) {
                continue;
            }
            if (($renderable['type'] ?? '') === $from) {
                $renderable['type'] = 'Cap';
            }
            if (isset($renderable['renderables']) && is_array($renderable['renderables'])) {
                $renderable['renderables'] = self::migrateRenderables($renderable['renderables'], $from);
            }
            if (isset($renderable['validators']) && is_array($renderable['validators'])) {
                foreach ($renderable['validators'] as $validatorKey => $validator) {
                    if (is_array($validator) && ($validator['identifier'] ?? '') === $from) {
                        $renderable['validators'][$validatorKey]['identifier'] = 'Cap';
                    }
                }
            }
            $renderables[$key] = $renderable;
        }

        return $renderables;
    }
}
