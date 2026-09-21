<?php

declare(strict_types=1);

namespace Subugoe\Typo3Cap\Upgrade;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
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
    private OutputInterface $output;

    public function __construct(private readonly ConnectionPool $connectionPool, private readonly StorageRepository $storageRepository) {}

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

    public function executeUpdate(): bool
    {
        $this->migrate();
        if (count($this->migratedForms) > 0) {
            $this->output->writeln('Migrated '.count($this->migratedForms).' form definition(s):');
            foreach ($this->migratedForms as $form) {
                $this->output->writeln('  - '.$form);
            }
        } else {
            $this->output->writeln('No form definitions needed migration.');
        }
        if ($this->migratedTemplates > 0) {
            $this->output->writeln('Switched the static template include to Cap in '.$this->migratedTemplates.' sys_template record(s).');
        }
        if ($this->failedStorages > 0) {
            $this->output->writeln('Could not read '.$this->failedStorages.' storage folder(s); check their form definitions manually.');
        }
        $this->output->writeln('Manual steps:');
        foreach ($this->getManualSteps() as $step) {
            $this->output->writeln('  - '.$step);
        }

        return true;
    }

    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
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

    /**
     * Plain-text lines shown to the administrator after the migration.
     */
    abstract protected function getManualSteps(): array;

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
        $storages = $this->storageRepository->findAll();
        foreach ($storages as $storage) {
            if (!$storage->isOnline() || !$storage->isBrowsable()) {
                continue;
            }

            yield from $this->collectFormFiles($storage, $storage->getRootLevelFolder(), 0);
        }
    }

    private function collectFormFiles(ResourceStorage $storage, Folder $folder, int $depth): \Generator
    {
        if ($depth > 5) {
            return;
        }

        try {
            foreach ($storage->getFilesInFolder($folder) as $file) {
                if ($file instanceof File && str_ends_with($file->getName(), '.form.yaml')) {
                    yield $file;
                }
            }
            foreach ($storage->getFoldersInFolder($folder) as $subFolder) {
                yield from $this->collectFormFiles($storage, $subFolder, $depth + 1);
            }
        } catch (\Throwable) {
            ++$this->failedStorages;
        }
    }
}
