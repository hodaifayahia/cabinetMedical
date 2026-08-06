<?php

namespace App\Backups;

final readonly class RestoreTargetSet
{
    /**
     * @param  array{clinical_documents: string, patient_documents: string, medical_models: string, cabinet: string}  $managedRoots
     */
    public function __construct(
        public string $database,
        public array $managedRoots,
    ) {
        if ($database === '' || str_contains($database, "\0")) {
            throw new BackupArchiveException('The active restore database target is invalid.');
        }

        foreach (['clinical_documents', 'patient_documents', 'medical_models', 'cabinet'] as $key) {
            if ($managedRoots[$key] === ''
                || str_contains($managedRoots[$key], "\0")) {
                throw new BackupArchiveException('An active managed restore root is invalid.');
            }
        }
    }

    public static function fromConfiguration(): self
    {
        $database = config('database.connections.sqlite.database');
        $privateRoot = config('filesystems.disks.local.root', storage_path('app/private'));
        $publicRoot = config('filesystems.disks.public.root', storage_path('app/public'));

        if (! is_string($database) || $database === '' || $database === ':memory:'
            || ! is_string($privateRoot) || $privateRoot === ''
            || ! is_string($publicRoot) || $publicRoot === '') {
            throw new BackupArchiveException('The active restore targets are not configured as local files.');
        }

        return new self(
            database: $database,
            managedRoots: [
                'clinical_documents' => rtrim($privateRoot, '\\/').DIRECTORY_SEPARATOR.'clinical-documents',
                'patient_documents' => rtrim($privateRoot, '\\/').DIRECTORY_SEPARATOR.'patient-documents',
                'medical_models' => rtrim($privateRoot, '\\/').DIRECTORY_SEPARATOR.'medical-models',
                'cabinet' => rtrim($publicRoot, '\\/').DIRECTORY_SEPARATOR.'cabinet',
            ],
        );
    }

    /**
     * @return list<array{
     *     key: 'database'|'clinical_documents'|'patient_documents'|'medical_models'|'cabinet',
     *     type: 'file'|'directory',
     *     active: string,
     *     staged: string,
     *     rollback: string
     * }>
     */
    public function items(PreparedRestore $restore): array
    {
        $stagedRoots = $restore->stagedManagedRoots();
        $definitions = [
            ['key' => 'database', 'type' => 'file', 'active' => $this->database, 'staged' => $restore->stagedDatabasePath()],
            ['key' => 'clinical_documents', 'type' => 'directory', 'active' => $this->managedRoots['clinical_documents'], 'staged' => $stagedRoots['clinical_documents']],
            ['key' => 'patient_documents', 'type' => 'directory', 'active' => $this->managedRoots['patient_documents'], 'staged' => $stagedRoots['patient_documents']],
            ['key' => 'medical_models', 'type' => 'directory', 'active' => $this->managedRoots['medical_models'], 'staged' => $stagedRoots['medical_models']],
            ['key' => 'cabinet', 'type' => 'directory', 'active' => $this->managedRoots['cabinet'], 'staged' => $stagedRoots['cabinet']],
        ];
        $items = [];

        foreach ($definitions as $definition) {
            $activeParent = realpath(dirname($definition['active']));
            $stagedParent = realpath(dirname($definition['staged']));

            if (! is_string($activeParent) || ! is_dir($activeParent) || is_link(dirname($definition['active']))
                || ! is_string($stagedParent) || ! is_dir($stagedParent) || is_link(dirname($definition['staged']))) {
                throw new BackupArchiveException('A restore target parent directory is unavailable or unsafe.');
            }

            $active = $activeParent.DIRECTORY_SEPARATOR.basename($definition['active']);
            $staged = $stagedParent.DIRECTORY_SEPARATOR.basename($definition['staged']);
            $activeExists = file_exists($active) || is_link($active);

            if (is_link($active) || is_link($staged)
                || ($definition['type'] === 'file' && ! is_file($staged))
                || ($definition['type'] === 'directory' && ! is_dir($staged))
                || ($activeExists && $definition['type'] === 'file' && ! is_file($active))
                || ($activeExists && $definition['type'] === 'directory' && ! is_dir($active))) {
                throw new BackupArchiveException('A restore target or staged payload has an unsafe filesystem type.');
            }

            $activeStat = @stat($activeParent);
            $stagedStat = @stat($stagedParent);

            if (! is_array($activeStat) || ! is_array($stagedStat)
                || $activeStat['dev'] !== $stagedStat['dev']) {
                throw new BackupArchiveException('A staged restore target is not on the same filesystem as its active target.');
            }

            $rollback = $activeParent.DIRECTORY_SEPARATOR.'.medismart-restore-'
                .$restore->operationId.'-'.$definition['key'].'.rollback';

            if (file_exists($rollback) || is_link($rollback)) {
                throw new BackupArchiveException('A deterministic restore rollback path is already occupied.');
            }

            $items[] = [
                'key' => $definition['key'],
                'type' => $definition['type'],
                'active' => $active,
                'staged' => $staged,
                'rollback' => $rollback,
            ];
        }

        return $items;
    }
}
