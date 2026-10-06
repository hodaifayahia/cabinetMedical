<?php

namespace App\Enums;

/**
 * How a relative is related to a patient, as recorded by the cabinet on the
 * patient's dossier (« Ali est le frère de Sara »).
 *
 * Every relation belongs to a kind (parent, child, sibling…) and comes in a
 * male, a female and a neutral form, so the reverse link can be computed from
 * the other patient's sex: the brother of a woman sees her as his sister.
 */
enum PatientRelation: string
{
    case FATHER = 'father';
    case MOTHER = 'mother';
    case PARENT = 'parent';
    case SON = 'son';
    case DAUGHTER = 'daughter';
    case CHILD = 'child';
    case BROTHER = 'brother';
    case SISTER = 'sister';
    case SIBLING = 'sibling';
    case HUSBAND = 'husband';
    case WIFE = 'wife';
    case SPOUSE = 'spouse';
    case GRANDFATHER = 'grandfather';
    case GRANDMOTHER = 'grandmother';
    case GRANDPARENT = 'grandparent';
    case GRANDSON = 'grandson';
    case GRANDDAUGHTER = 'granddaughter';
    case GRANDCHILD = 'grandchild';
    case UNCLE = 'uncle';
    case AUNT = 'aunt';
    case UNCLE_AUNT = 'uncle_aunt';
    case NEPHEW = 'nephew';
    case NIECE = 'niece';
    case NEPHEW_NIECE = 'nephew_niece';
    case COUSIN = 'cousin';
    case OTHER = 'other';

    /**
     * Kind => [male form, female form, neutral form].
     */
    private const KINDS = [
        'parent' => [self::FATHER, self::MOTHER, self::PARENT],
        'child' => [self::SON, self::DAUGHTER, self::CHILD],
        'sibling' => [self::BROTHER, self::SISTER, self::SIBLING],
        'spouse' => [self::HUSBAND, self::WIFE, self::SPOUSE],
        'grandparent' => [self::GRANDFATHER, self::GRANDMOTHER, self::GRANDPARENT],
        'grandchild' => [self::GRANDSON, self::GRANDDAUGHTER, self::GRANDCHILD],
        'uncle' => [self::UNCLE, self::AUNT, self::UNCLE_AUNT],
        'nephew' => [self::NEPHEW, self::NIECE, self::NEPHEW_NIECE],
        'cousin' => [self::COUSIN, self::COUSIN, self::COUSIN],
        'other' => [self::OTHER, self::OTHER, self::OTHER],
    ];

    /** The kind seen from the other side of the link. */
    private const INVERSE_KINDS = [
        'parent' => 'child',
        'child' => 'parent',
        'sibling' => 'sibling',
        'spouse' => 'spouse',
        'grandparent' => 'grandchild',
        'grandchild' => 'grandparent',
        'uncle' => 'nephew',
        'nephew' => 'uncle',
        'cousin' => 'cousin',
        'other' => 'other',
    ];

    public function label(): string
    {
        return match ($this) {
            self::FATHER => 'Père',
            self::MOTHER => 'Mère',
            self::PARENT => 'Parent',
            self::SON => 'Fils',
            self::DAUGHTER => 'Fille',
            self::CHILD => 'Enfant',
            self::BROTHER => 'Frère',
            self::SISTER => 'Sœur',
            self::SIBLING => 'Frère / sœur',
            self::HUSBAND => 'Époux',
            self::WIFE => 'Épouse',
            self::SPOUSE => 'Conjoint(e)',
            self::GRANDFATHER => 'Grand-père',
            self::GRANDMOTHER => 'Grand-mère',
            self::GRANDPARENT => 'Grand-parent',
            self::GRANDSON => 'Petit-fils',
            self::GRANDDAUGHTER => 'Petite-fille',
            self::GRANDCHILD => 'Petit-enfant',
            self::UNCLE => 'Oncle',
            self::AUNT => 'Tante',
            self::UNCLE_AUNT => 'Oncle / tante',
            self::NEPHEW => 'Neveu',
            self::NIECE => 'Nièce',
            self::NEPHEW_NIECE => 'Neveu / nièce',
            self::COUSIN => 'Cousin(e)',
            self::OTHER => 'Proche',
        };
    }

    public function kind(): string
    {
        foreach (self::KINDS as $kind => $forms) {
            if (in_array($this, $forms, true)) {
                return $kind;
            }
        }

        return 'other';
    }

    /**
     * Parents, children, brothers and sisters: the relatives whose diseases
     * weigh on the patient's own risk.
     */
    public function isFirstDegree(): bool
    {
        return in_array($this->kind(), ['parent', 'child', 'sibling'], true);
    }

    /**
     * The relation seen from the relative's side. $patientGender is the sex
     * of the patient who becomes the relative: a man is « frère », a woman
     * « sœur », an unknown sex gives the neutral form.
     */
    public function inverse(?Gender $patientGender): self
    {
        return self::ofKind(self::INVERSE_KINDS[$this->kind()], $patientGender);
    }

    public static function ofKind(string $kind, ?Gender $gender): self
    {
        $forms = self::KINDS[$kind] ?? self::KINDS['other'];

        return match ($gender) {
            Gender::MALE => $forms[0],
            Gender::FEMALE => $forms[1],
            default => $forms[2],
        };
    }

    /**
     * The relations offered when the cabinet links two dossiers. The neutral
     * forms are only produced automatically, when the sex is unknown.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        $offered = [
            self::FATHER, self::MOTHER,
            self::BROTHER, self::SISTER,
            self::SON, self::DAUGHTER,
            self::HUSBAND, self::WIFE,
            self::GRANDFATHER, self::GRANDMOTHER,
            self::GRANDSON, self::GRANDDAUGHTER,
            self::UNCLE, self::AUNT,
            self::NEPHEW, self::NIECE,
            self::COUSIN, self::OTHER,
        ];

        return array_map(
            static fn (self $relation): array => ['value' => $relation->value, 'label' => $relation->label()],
            $offered,
        );
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $relation): string => $relation->value, self::cases());
    }
}
