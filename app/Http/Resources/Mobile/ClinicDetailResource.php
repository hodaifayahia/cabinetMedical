<?php

namespace App\Http\Resources\Mobile;

use App\Enums\Weekday;
use App\Models\Baladiya;
use App\Models\Cabinet;
use App\Models\CabinetPublicProfile;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\Wilaya;
use App\Support\MedicalSpecialtyCatalog;
use App\Support\SpecialtyArabicLabels;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Public detail page of a listed clinic: identity, contact data and the
 * weekly working hours of its active doctor (all 7 ISO weekdays, with
 * multi-range days split into morning/evening periods).
 *
 * @property Cabinet $resource
 */
class ClinicDetailResource extends JsonResource
{
    /**
     * @param  Collection<int, DoctorSchedule>  $schedules
     */
    public function __construct(
        Cabinet $cabinet,
        private readonly CabinetPublicProfile $profile,
        private readonly ?DoctorProfile $doctor,
        private readonly Collection $schedules,
        private readonly ?Wilaya $wilaya,
        private readonly ?Baladiya $baladiya,
    ) {
        parent::__construct($cabinet);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'name' => $this->resource->name,
            'about' => $this->profile->about,
            'address' => $this->profile->address,
            'wilaya' => $this->wilaya === null ? null : [
                'code' => $this->wilaya->code,
                'name_fr' => $this->wilaya->name_fr,
                'name_ar' => $this->wilaya->name_ar,
            ],
            'baladiya' => $this->baladiya === null ? null : [
                'id' => $this->baladiya->id,
                'name_fr' => $this->baladiya->name_fr,
                'name_ar' => $this->baladiya->name_ar,
            ],
            'phones' => $this->profile->phones ?? [],
            'latitude' => $this->profile->latitude === null ? null : (float) $this->profile->latitude,
            'longitude' => $this->profile->longitude === null ? null : (float) $this->profile->longitude,
            'photos' => $this->profile->photos ?? [],
            'specialties' => $this->specialties(),
            'doctor' => $this->doctorPayload(),
            'working_hours' => $this->workingHours(),
        ];
    }

    /**
     * Doctor specialty plus the cabinet's own specialization, deduplicated
     * by catalogue code.
     *
     * @return list<array{code: string, label_fr: string, label_ar: string}>
     */
    private function specialties(): array
    {
        $catalog = app(MedicalSpecialtyCatalog::class);
        $candidates = [];

        if ($this->doctor !== null && $this->doctor->specialty_code !== null) {
            $candidates[] = [$this->doctor->specialty, $this->doctor->specialty_code];
        }

        $specialization = trim((string) $this->resource->specialization);

        if ($specialization !== '') {
            $candidates[] = [$specialization, $catalog->codeFor($specialization)];
        }

        $byCode = [];

        foreach ($candidates as [$label, $code]) {
            // Dedupe on the canonical slug: a clinic whose doctor stored a
            // French-derived code and whose cabinet stored the catalogue label
            // is one specialty, not two.
            $code = SpecialtyArabicLabels::canonicalCode($code) ?? $code;

            if (isset($byCode[$code])) {
                continue;
            }

            $labelFr = $catalog->display($label, $code);

            $byCode[$code] = [
                'code' => $code,
                'label_fr' => $labelFr,
                'label_ar' => SpecialtyArabicLabels::labelFor($code) ?? $labelFr,
            ];
        }

        return array_values($byCode);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function doctorPayload(): ?array
    {
        if ($this->doctor === null) {
            return null;
        }

        $code = $this->doctor->specialty_code;
        $specialty = null;

        if ($code !== null) {
            $labelFr = app(MedicalSpecialtyCatalog::class)->display($this->doctor->specialty, $code);

            $specialty = [
                'code' => $code,
                'label_fr' => $labelFr,
                'label_ar' => SpecialtyArabicLabels::labelFor($code) ?? $labelFr,
            ];
        }

        return [
            'id' => $this->doctor->getKey(),
            'name' => $this->doctor->doctor_name ?? $this->doctor->user?->name,
            'specialty' => $specialty,
        ];
    }

    /**
     * @return list<array{weekday: int, is_closed: bool, ranges: array<int, array<string, mixed>>}>
     */
    private function workingHours(): array
    {
        $byWeekday = $this->schedules->groupBy(
            static fn (DoctorSchedule $schedule): int => $schedule->day_of_week->value,
        );

        $defaultDuration = (int) ($this->doctor->consultation_duration
            ?? config('clinic.appointments.default_duration', 30));

        $hours = [];

        foreach (Weekday::values() as $weekday) {
            $ranges = $byWeekday->get($weekday, new Collection)
                ->sortBy(fn (DoctorSchedule $schedule): string => $this->timeLabel($schedule->starts_at))
                ->values()
                ->map(function (DoctorSchedule $schedule) use ($defaultDuration): array {
                    $startsAt = $this->timeLabel($schedule->starts_at);

                    return [
                        'starts_at' => $startsAt,
                        'ends_at' => $this->timeLabel($schedule->ends_at),
                        'period' => $startsAt < '12:00' ? 'morning' : 'evening',
                        'slot_duration' => (int) ($schedule->slot_duration ?? $defaultDuration),
                    ];
                })
                ->all();

            $hours[] = [
                'weekday' => $weekday,
                'is_closed' => $ranges === [],
                'ranges' => $ranges,
            ];
        }

        return $hours;
    }

    private function timeLabel(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i');
        }

        return substr((string) $value, 0, 5);
    }
}
