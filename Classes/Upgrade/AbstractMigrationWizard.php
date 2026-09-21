<?php

declare(strict_types=1);

namespace Subugoe\Typo3Cap\Upgrade;

use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Install\Updates\ChattyInterface;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Shared migration of captcha form definitions to Cap: rewrites .form.yaml files
 * and swaps the old static template include. Subclasses name the source extension.
 */
abstract class AbstractMigrationWizard implements UpgradeWizardInterface, ChattyInterface
{
    protected array $migratedForms = [];
    protected int $migratedTemplates = 0;
    protected int $failedStorages = 0;

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    public function updateNecessary(): bool
    {
        try {
            foreach ($this->findFormFiles() as $file) {
                $definition = Yaml::parse($file->getContents());
                if (is_array($definition) && FormDefinitionMigrator::needsMigration($definition, $this->getFromType())) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return true;
        }

        return false;
    }

    public function execute(): bool
    {
        $this->migrate();

        return true;
    }

    public function executeWizard(): string
    {
        $this->migrate();
        $count = count($this->migratedForms);
        $output = $count > 0
            ? '<p>Migrated '.$count.' form definition(s):</p><ul><li>'
                .implode('</li><li>', array_map(htmlspecialchars(...), $this->migratedForms)).'</li></ul>'
            : '<p>No form definitions needed migration.</p>';
        if ($this->migratedTemplates > 0) {
            $output .= '<p>Switched the static template include to Cap in '.$this->migratedTemplates.' sys_template record(s).</p>';
        }
        if ($this->failedStorages > 0) {
            $output .= '<p>Could not read '.$this->failedStorages.' storage folder(s); check their form definitions manually.</p>';
        }
        $output .= '<p>Manual steps:</p><ul>'.$this->getManualSteps().'</ul>';

        return $output;
    }

    public function getPrerequisites(): array
    {
        return [];
    }

    abstract protected function getFromType(): string;

    /**
     * The old extension's TypoScript path fragment inside sys_template records,
     * e.g. 'hcaptcha/Configuration/TypoScript'.
     */
    abstract protected function getStaticTemplateFragment(): string;

    abstract protected function getManualSteps(): string;

    private function migrate(): void
    {
        foreach ($this->findFormFiles() as $file) {
            try {
                $definition = Yaml::parse($file->getContents());
            } catch (\Throwable) {
                continue;
            }
            if (!is_array($definition) || !FormDefinitionMigrator::needsMigration($definition, $this->getFromType())) {
                continue;
            }

            try {
                $file->setContents(Yaml::dump(FormDefinitionMigrator::migrateDefinition($definition, $this->getFromType()), 20, 2));
                $this->migratedForms[] = $file->getIdentifier();
            } catch (\Throwable) {
                // Non-writable storage or invalid YAML: skipped, reported in the wizard output.
            }
        }
        $this->migrateTemplates();
    }

    private function migrateTemplates(): void
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_template');
            $rows = $queryBuilder
                ->select('uid', 'include_static_file')
                ->from('sys_template')
                ->where($queryBuilder->expr()->like(
                    'include_static_file',
                    $queryBuilder->createNamedParameter('%'.$this->getStaticTemplateFragment().'%')
                ))
                ->executeQuery()
                ->fetchAllAssociative()
            ;
        } catch (\Throwable) {
            return;
        }
        $replacement = 'typo3_cap/Configuration/TypoScript';
        foreach ($rows as $row) {
            $include = (string) ($row['include_static_file'] ?? '');
            $updated = str_replace($this->getStaticTemplateFragment(), $replacement, $include);
            if ($updated === $include) {
                continue;
            }
            $this->connectionPool
                ->getConnectionForTable('sys_template')
                ->update('sys_template', ['include_static_file' => $updated], ['uid' => (int) $row['uid']])
            ;
            ++$this->migratedTemplates;
        }
    }

    private function findFormFiles(): \Generator
    {
        $storages = GeneralUtility::makeInstance(StorageRepository::class)->findAll();
        foreach ($storages as $storage) {
            if (!$storage->isOnline() || !$storage->isBrowsable()) {
                continue;
            }

            yield from $this->collectFormFiles($storage, $storage->getRootLevelFolder()->getIdentifier(), 0);
        }
    }

    // ponytail: depth 5, raise if form definitions nest deeper in your storage
    private function collectFormFiles(ResourceStorage $storage, string $folderIdentifier, int $depth): \Generator
    {
        if ($depth > 5) {
            return;
        }

        try {
            foreach ($storage->getFilesInFolder($folderIdentifier) as $file) {
                if ($file instanceof File && str_ends_with($file->getName(), '.form.yaml')) {
                    yield $file;
                }
            }
            foreach ($storage->getFoldersInFolder($folderIdentifier) as $folder) {
                yield from $this->collectFormFiles($storage, $folder->getIdentifier(), $depth + 1);
            }
        } catch (\Throwable) {
            ++$this->failedStorages;
        }
    }
}
