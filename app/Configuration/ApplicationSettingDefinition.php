<?php

namespace App\Configuration;

use Illuminate\Support\Facades\Validator;

final readonly class ApplicationSettingDefinition
{
    /**
     * @param  list<mixed>  $rules
     * @param  list<string|int|float>|null  $options
     */
    public function __construct(
        public string $key,
        public string $group,
        public string $label,
        public string $helpText,
        public ApplicationSettingType $type,
        private mixed $defaultValue,
        public bool $nullable = false,
        public array $rules = [],
        public int|float|null $minimum = null,
        public int|float|null $maximum = null,
        public ?int $maximumLength = null,
        public ?array $options = null,
        public string $scope = 'clinic',
        public bool $sensitive = false,
        public string $redaction = 'none',
        public string $permission = 'settings.manage',
        public bool $requiresRecentConfirmation = false,
        public string $restart = 'none',
        public bool $audited = true,
        public string $backupPolicy = 'portable',
        public bool $editable = true,
    ) {}

    public function defaultValue(): mixed
    {
        return $this->defaultValue;
    }

    /**
     * @return list<mixed>
     */
    public function validationRules(): array
    {
        $rules = [$this->nullable ? 'nullable' : 'required', ...$this->type->validationRules()];

        if ($this->minimum !== null) {
            $rules[] = 'min:'.$this->minimum;
        }

        if ($this->maximum !== null) {
            $rules[] = 'max:'.$this->maximum;
        }

        if ($this->maximumLength !== null) {
            $rules[] = 'max:'.$this->maximumLength;
        }

        if ($this->options !== null) {
            $rules[] = 'in:'.implode(',', $this->options);
        }

        return [...$rules, ...$this->rules];
    }

    public function normalize(mixed $value): mixed
    {
        $validated = Validator::make(
            ['value' => $value],
            ['value' => $this->validationRules()],
            [],
            ['value' => $this->label],
        )->validate();

        return $this->type->normalize($validated['value'] ?? null);
    }

    /**
     * Metadata safe to return to an authenticated settings interface.
     * Sensitive defaults and effective values are deliberately omitted.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'key' => $this->key,
            'group' => $this->group,
            'label' => $this->label,
            'help_text' => $this->helpText,
            'type' => $this->type->value,
            'nullable' => $this->nullable,
            'default' => $this->sensitive ? null : $this->defaultValue(),
            'minimum' => $this->minimum,
            'maximum' => $this->maximum,
            'maximum_length' => $this->maximumLength,
            'options' => $this->options,
            'scope' => $this->scope,
            'sensitive' => $this->sensitive,
            'redaction' => $this->redaction,
            'permission' => $this->permission,
            'requires_recent_confirmation' => $this->requiresRecentConfirmation,
            'restart' => $this->restart,
            'audited' => $this->audited,
            'backup_policy' => $this->backupPolicy,
            'editable' => $this->editable,
        ];
    }
}
