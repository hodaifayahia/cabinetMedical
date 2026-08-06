<?php

namespace Database\Seeders;

use App\Models\Act;
use App\Models\BilanType;
use App\Models\ConsultationFee;
use App\Models\Exam;
use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class ConfigurationSeeder extends Seeder
{
    /**
     * Seed sensible cabinet configuration defaults.
     */
    public function run(): void
    {
        foreach ([
            ['Labo', 'Analyses biologiques', 'labo'],
            ['Cardio', 'Explorations cardiaques', 'cardio'],
            ['Radio', 'Imagerie et explorations', 'radio'],
        ] as [$name, $description, $category]) {
            BilanType::query()->updateOrCreate(
                ['name' => $name],
                [
                    'description' => $description,
                    'category' => $category,
                    'is_active' => true,
                ],
            );
        }

        foreach (['Espèces', 'Carte', 'Chèque', 'Virement'] as $method) {
            PaymentMethod::query()->updateOrCreate(['name' => $method], ['is_active' => true]);
        }

        ConsultationFee::query()->updateOrCreate(
            ['label' => 'Consultation standard'],
            ['amount_minor' => 200000, 'category' => 'Consultation', 'is_active' => true],
        );

        $acts = [
            ['ECG', 150000, 'Cardiologie'],
            ['Frottis cervico-vaginal', 100000, 'Gynécologie'],
        ];

        foreach ($acts as [$name, $priceMinor, $category]) {
            Act::query()->updateOrCreate(
                ['name' => $name],
                ['price_minor' => $priceMinor, 'category' => $category, 'is_active' => true],
            );
        }

        $exams = [
            ['Glycémie à jeun', 'labo'],
            ['ECG', 'cardio'],
            ['Épreuve d’effort', 'cardio'],
            ['Holter ECG', 'cardio'],
            ['Échocardiographie', 'cardio'],
            ['IRM', 'radio'],
            ['EEG', 'radio'],
            ['Scanner', 'radio'],
            ['Radiographie thorax', 'radio'],
            ['Échographie abdominale', 'radio'],
        ];

        $categoryNames = BilanType::query()
            ->whereNotNull('category')
            ->orderBy('id')
            ->get(['name', 'category'])
            ->groupBy('category')
            ->map(fn ($categories) => $categories->first()->name);

        foreach ($exams as [$name, $category]) {
            Exam::query()->updateOrCreate(
                ['name' => $name],
                [
                    'category' => $categoryNames->get($category, $category),
                    'is_active' => true,
                ],
            );
        }

        $bilans = [
            ['Bilan lipidique', 'labo'],
            ['Bilan rénal', 'labo'],
        ];

        foreach ($bilans as [$name, $category]) {
            BilanType::query()->updateOrCreate(
                ['name' => $name],
                [
                    'description' => 'Bilan biologique '.strtolower($name),
                    'category' => $category,
                    'is_active' => true,
                ],
            );
        }
    }
}
