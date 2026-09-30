<?php

namespace App\Backups;

use App\Configuration\ApplicationSettingRegistry as Setting;
use App\Services\ApplicationSettingService;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Throwable;

/**
 * Whether each scheduled backup is also encrypted and queued for the cabinet's
 * Google Drive.
 *
 * An unattended upload needs a recovery passphrase nobody is there to type, so
 * this is the one backup secret the installation keeps: encrypted at rest with
 * this installation's APP_KEY, never rendered back, and deleted together with
 * the Drive grant. A Drive-only compromise therefore still yields nothing but
 * ciphertext, while the doctor must keep the passphrase to restore elsewhere.
 */
final class AutomaticDriveUploadPolicy
{
    public const MINIMUM_PASSPHRASE_LENGTH = 12;

    public function __construct(private readonly ApplicationSettingService $settings) {}

    public function enabled(): bool
    {
        return $this->passphrase() !== null;
    }

    /**
     * The stored passphrase, or null when the automatic copy is off or its
     * secret can no longer be read (for example after an APP_KEY change).
     */
    public function passphrase(): ?string
    {
        try {
            if ($this->settings->get(Setting::BACKUP_DRIVE_AUTO_UPLOAD) !== true) {
                return null;
            }

            $passphrase = $this->settings->get(Setting::BACKUP_DRIVE_AUTO_UPLOAD_PASSPHRASE);
        } catch (Throwable) {
            return null;
        }

        return is_string($passphrase) && strlen($passphrase) >= self::MINIMUM_PASSPHRASE_LENGTH
            ? $passphrase
            : null;
    }

    public function enable(#[SensitiveParameter] string $passphrase): void
    {
        DB::transaction(function () use ($passphrase): void {
            $this->settings->setInternal(Setting::BACKUP_DRIVE_AUTO_UPLOAD_PASSPHRASE, $passphrase);
            $this->settings->setInternal(Setting::BACKUP_DRIVE_AUTO_UPLOAD, true);
        });
    }

    public function disable(): void
    {
        DB::transaction(function (): void {
            $this->settings->setInternal(Setting::BACKUP_DRIVE_AUTO_UPLOAD, false);
            $this->settings->setInternal(Setting::BACKUP_DRIVE_AUTO_UPLOAD_PASSPHRASE, null);
        });
    }
}
