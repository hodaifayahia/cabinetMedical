<?php

namespace App\Configuration;

use App\Models\Act;
use App\Models\BilanType;
use App\Models\ConsultationFee;
use App\Models\Exam;
use App\Models\PaymentMethod;
use App\Models\Practitioner;
use Illuminate\Validation\Rule;

/**
 * Central definition of every table-based configuration referential.
 *
 * Each entry drives a generic controller + a single shared Vue page, so a new
 * referential only needs a model, a migration table, and an entry here.
 */
class ReferentialRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'bilan-types' => [
                'model' => BilanType::class,
                'title' => 'Catégories de bilans',
                'description' => 'Catégories proposées dans l’espace Bilans.',
                'section' => 'referentials',
                'columns' => [
                    ['key' => 'name', 'label' => 'Nom'],
                    ['key' => 'description', 'label' => 'Description'],
                ],
                'fields' => [
                    ['key' => 'name', 'label' => 'Nom', 'type' => 'text', 'required' => true],
                    ['key' => 'description', 'label' => 'Description', 'type' => 'textarea', 'required' => true],
                ],
                'rules' => [
                    'name' => ['required', 'string', 'max:200'],
                    'description' => ['required', 'string', 'max:5000'],
                ],
                'money' => [],
                'searchable' => ['name', 'description'],
            ],

            'exams' => [
                'model' => Exam::class,
                'title' => 'Examens',
                'description' => 'Catalogue des examens complémentaires.',
                'section' => 'referentials',
                'columns' => [
                    ['key' => 'name', 'label' => 'Nom'],
                    ['key' => 'category', 'label' => 'Catégorie'],
                ],
                'fields' => [
                    ['key' => 'name', 'label' => 'Nom', 'type' => 'text', 'required' => true],
                    [
                        'key' => 'category',
                        'label' => 'Catégorie',
                        'type' => 'select',
                        'required' => true,
                        'options_source' => 'bilan-categories',
                    ],
                ],
                'rules' => [
                    'name' => ['required', 'string', 'max:200'],
                    'category' => [
                        'required',
                        'string',
                        'max:200',
                        Rule::exists('bilan_types', 'name')->where('is_active', true),
                    ],
                ],
                'money' => [],
                'searchable' => ['name', 'category'],
            ],

            'practitioners' => [
                'model' => Practitioner::class,
                'title' => 'Annuaire des médecins',
                'description' => 'Médecins correspondants et praticiens du cabinet.',
                'section' => 'referentials',
                'columns' => [
                    ['key' => 'name', 'label' => 'Nom'],
                    ['key' => 'specialty', 'label' => 'Spécialité'],
                    ['key' => 'phone', 'label' => 'Téléphone'],
                    ['key' => 'email', 'label' => 'Email'],
                    ['key' => 'order_number', 'label' => 'N° d’ordre'],
                ],
                'fields' => [
                    ['key' => 'name', 'label' => 'Nom', 'type' => 'text', 'required' => true],
                    ['key' => 'specialty', 'label' => 'Spécialité', 'type' => 'text'],
                    ['key' => 'phone', 'label' => 'Téléphone', 'type' => 'text'],
                    ['key' => 'email', 'label' => 'Email', 'type' => 'text'],
                    ['key' => 'address', 'label' => 'Adresse', 'type' => 'text'],
                    ['key' => 'order_number', 'label' => 'Numéro d’ordre', 'type' => 'text'],
                ],
                'rules' => [
                    'name' => ['required', 'string', 'max:150'],
                    'specialty' => ['nullable', 'string', 'max:150'],
                    'phone' => ['nullable', 'string', 'max:40'],
                    'email' => ['nullable', 'email', 'max:190'],
                    'address' => ['nullable', 'string', 'max:255'],
                    'order_number' => ['nullable', 'string', 'max:60'],
                ],
                'money' => [],
                'searchable' => ['name', 'specialty'],
            ],

            'consultation-fees' => [
                'model' => ConsultationFee::class,
                'title' => 'Actes',
                'description' => 'Actes médicaux utilisés pour la facturation.',
                'section' => 'finance',
                'columns' => [
                    ['key' => 'label', 'label' => 'Libellé'],
                    ['key' => 'amount', 'label' => 'Montant (DA)'],
                    ['key' => 'category', 'label' => 'Catégorie'],
                ],
                'fields' => [
                    ['key' => 'label', 'label' => 'Libellé', 'type' => 'text', 'required' => true],
                    ['key' => 'amount', 'label' => 'Montant (DA)', 'type' => 'money'],
                    ['key' => 'category', 'label' => 'Catégorie', 'type' => 'text'],
                ],
                'rules' => [
                    'label' => ['required', 'string', 'max:150'],
                    'amount' => ['nullable', 'numeric', 'min:0'],
                    'category' => ['nullable', 'string', 'max:100'],
                ],
                'money' => ['amount' => 'amount_minor'],
                'searchable' => ['label', 'category'],
            ],

            'acts' => [
                'model' => Act::class,
                'title' => 'Catégories & actes',
                'description' => 'Actes médicaux et leurs tarifs.',
                'section' => 'finance',
                'columns' => [
                    ['key' => 'code', 'label' => 'Code'],
                    ['key' => 'name', 'label' => 'Nom'],
                    ['key' => 'price', 'label' => 'Prix (DA)'],
                    ['key' => 'category', 'label' => 'Catégorie'],
                ],
                'fields' => [
                    ['key' => 'code', 'label' => 'Code', 'type' => 'text'],
                    ['key' => 'name', 'label' => 'Nom', 'type' => 'text', 'required' => true],
                    ['key' => 'price', 'label' => 'Prix (DA)', 'type' => 'money'],
                    ['key' => 'category', 'label' => 'Catégorie', 'type' => 'text', 'required' => true],
                ],
                'rules' => [
                    'code' => ['nullable', 'string', 'max:60'],
                    'name' => ['required', 'string', 'max:200'],
                    'price' => ['nullable', 'numeric', 'min:0'],
                    'category' => ['required', 'string', 'max:100'],
                ],
                'money' => ['price' => 'price_minor'],
                'searchable' => ['name', 'code', 'category'],
            ],

            'payment-methods' => [
                'model' => PaymentMethod::class,
                'title' => 'Moyens de paiement',
                'description' => 'Moyens de paiement acceptés au cabinet.',
                'section' => 'finance',
                'columns' => [
                    ['key' => 'name', 'label' => 'Nom'],
                ],
                'fields' => [
                    ['key' => 'name', 'label' => 'Nom', 'type' => 'text', 'required' => true],
                ],
                'rules' => [
                    'name' => ['required', 'string', 'max:100'],
                ],
                'money' => [],
                'searchable' => ['name'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function for(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }
}
