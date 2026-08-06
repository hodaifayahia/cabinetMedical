<?php

namespace App\Services;

use App\Configuration\ApplicationSettingDefinition;
use App\Configuration\ApplicationSettingRegistry;
use App\Models\ApplicationSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

final class ApplicationSettingService
{
    public function __construct(private readonly ApplicationSettingRegistry $registry) {}

    public function get(string $key): mixed
    {
        return $this->resolve($this->registry->get($key))['value'];
    }

    /**
     * Persist one editable, registered override.
     */
    public function set(string $key, mixed $value): mixed
    {
        return $this->setMany([$key => $value])[$key];
    }

    /**
     * Persist multiple editable overrides atomically.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function setMany(array $values): array
    {
        return $this->persistMany($values, false);
    }

    /**
     * Store an internal registered value such as the installation identifier
     * or machine seed. This explicit API keeps those keys out of UI writes.
     */
    public function setInternal(string $key, mixed $value): mixed
    {
        return $this->persistMany([$key => $value], true)[$key];
    }

    public function reset(string $key): mixed
    {
        $definition = $this->registry->get($key);

        if (! $definition->editable) {
            throw new InvalidArgumentException("Application setting [{$key}] is internal and cannot be edited.");
        }

        ApplicationSetting::query()->where('key', $key)->delete();

        return $definition->defaultValue();
    }

    /**
     * Values and metadata safe for an authenticated settings interface.
     *
     * @return array<string, array<string, mixed>>
     */
    public function editableSettings(?string $group = null): array
    {
        $settings = [];

        foreach ($this->registry->editable($group) as $definition) {
            $resolved = $this->resolve($definition);
            $settings[$definition->key] = [
                ...$definition->metadata(),
                'value' => $definition->sensitive ? null : $resolved['value'],
                'source' => $resolved['source'],
                'configured' => $resolved['source'] === 'override',
            ];
        }

        return $settings;
    }

    /**
     * Return redacted metadata for any registered key, including internal keys.
     *
     * @return array<string, mixed>
     */
    public function describe(string $key): array
    {
        $definition = $this->registry->get($key);
        $resolved = $this->resolve($definition);

        return [
            ...$definition->metadata(),
            'value' => $definition->sensitive ? null : $resolved['value'],
            'source' => $resolved['source'],
            'configured' => $resolved['source'] === 'override',
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function persistMany(array $values, bool $allowInternal): array
    {
        if ($values === []) {
            return [];
        }

        $normalized = [];
        $definitions = [];

        foreach ($values as $key => $value) {
            $definition = $this->registry->get($key);

            if (! $definition->editable && ! $allowInternal) {
                throw new InvalidArgumentException("Application setting [{$key}] is internal and cannot be edited.");
            }

            $definitions[$key] = $definition;
            $normalized[$key] = $definition->normalize($value);
        }

        $this->validateCombinedUploadLimits($normalized);

        DB::transaction(function () use ($normalized, $definitions): void {
            foreach ($normalized as $key => $value) {
                $definition = $definitions[$key];

                if ($value === $definition->defaultValue()) {
                    ApplicationSetting::query()->where('key', $key)->delete();

                    continue;
                }

                ApplicationSetting::putValue(
                    key: $key,
                    value: $value,
                    encrypted: $definition->sensitive,
                    type: $definition->type->value,
                    group: $definition->group,
                );
            }
        });

        $effective = [];

        foreach (array_keys($normalized) as $key) {
            $effective[$key] = $this->get($key);
        }

        return $effective;
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function validateCombinedUploadLimits(array $pending): void
    {
        $individualKey = ApplicationSettingRegistry::UPLOAD_MAXIMUM_INDIVIDUAL_BYTES;
        $totalKey = ApplicationSettingRegistry::UPLOAD_MAXIMUM_TOTAL_BYTES;

        if (! array_key_exists($individualKey, $pending) && ! array_key_exists($totalKey, $pending)) {
            return;
        }

        $individual = $pending[$individualKey] ?? $this->get($individualKey);
        $total = $pending[$totalKey] ?? $this->get($totalKey);

        if ($individual > $total) {
            throw ValidationException::withMessages([
                $individualKey => 'La taille maximale d\'un fichier ne peut pas dépasser la taille totale.',
            ]);
        }
    }

    /**
     * Invalid, mismatched, or incorrectly encrypted database rows never become
     * effective configuration. The safe code default wins without mutating the
     * database during a read.
     *
     * @return array{value: mixed, source: 'default'|'override'}
     */
    private function resolve(ApplicationSettingDefinition $definition): array
    {
        $setting = ApplicationSetting::query()->where('key', $definition->key)->first();

        $rawEncryptedValue = $setting?->getRawOriginal('encrypted_value');

        if ($setting === null
            || $setting->type !== $definition->type->value
            || $setting->group !== $definition->group
            || ($setting->plain_value !== null && $rawEncryptedValue !== null)
            || ($definition->sensitive && $rawEncryptedValue === null)) {
            return ['value' => $definition->defaultValue(), 'source' => 'default'];
        }

        try {
            $value = ApplicationSetting::valueFor($definition->key);

            if ($value === null && ! $definition->nullable) {
                return ['value' => $definition->defaultValue(), 'source' => 'default'];
            }

            return ['value' => $definition->normalize($value), 'source' => 'override'];
        } catch (Throwable) {
            return ['value' => $definition->defaultValue(), 'source' => 'default'];
        }
    }
}
