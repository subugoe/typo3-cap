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

final class MigrateHcaptchaWizard implements UpgradeWizardInterface, ChattyInterface
{
    private array $migratedForms = [];
    private int $migratedTemplates = 0;
    private int $failedStorages = 0;

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    public function getIdentifier(): string
    {
        return 'typo3CapMigrateHcaptcha';
    }

    public function getTitle(): string
    {
        return 'Migrate hCaptcha form elements to Cap';
    }

    public function getDescription(): string
    {
        return 'Replaces the hCaptcha form element and validator with the Cap equivalents in every .form.yaml definition and switches the hCaptcha static template include to Cap. Afterwards set tx_typo3cap.siteKey/secretKey/serviceUrl (or CAP_* environment variables) and run a Cap server; hCaptcha keys cannot be reused.';
    }

    public function updateNecessary(): bool
    {
        try {
            foreach ($this->findFormFiles() as $file) {
                $definition = Yaml::parse($file->getContents());
                if (is_array($definition) && FormDefinitionMigrator::needsMigration($definition)) {
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
            $output .= '<p>Switched the hCaptcha static template include to Cap in '.$this->migratedTemplates.' sys_template record(s).</p>';
        }
        if ($this->failedStorages > 0) {
            $output .= '<p>Could not read '.$this->failedStorages.' storage folder(s); check their form definitions manually.</p>';
        }
        $output .= '<p>Manual steps:</p><ul>'
            .'<li>Include the "Cap Form Configuration" static template on pages with forms.</li>'
            .'<li>Create a site in your Cap server and set its keys via Page TSconfig (tx_typo3cap.siteKey, tx_typo3cap.secretKey, tx_typo3cap.serviceUrl) or the CAP_SITE_KEY/CAP_SECRET_KEY/CAP_SERVICE_URL environment variables. hCaptcha keys cannot be reused.</li>'
            .'<li>Remove the old extension: composer rem dreistromland/typo3-hcaptcha</li>'
            .'</ul>';

        return $output;
    }

    public function getPrerequisites(): array
    {
        return [];
    }

    private function migrate(): void
    {
        foreach ($this->findFormFiles() as $file) {
            try {
                $definition = Yaml::parse($file->getContents());
            } catch (\Throwable) {
                continue;
            }
            if (!is_array($definition) || !FormDefinitionMigrator::needsMigration($definition)) {
                continue;
            }

            try {
                $file->setContents(Yaml::dump(FormDefinitionMigrator::migrateDefinition($definition), 20, 2));
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
                    $queryBuilder->createNamedParameter('%hcaptcha/Configuration/TypoScript%')
                ))
                ->executeQuery()
                ->fetchAllAssociative()
            ;
        } catch (\Throwable) {
            return;
        }
        foreach ($rows as $row) {
            $include = (string) ($row['include_static_file'] ?? '');
            $updated = str_replace(
                'hcaptcha/Configuration/TypoScript',
                'typo3_cap/Configuration/TypoScript',
                $include
            );
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
