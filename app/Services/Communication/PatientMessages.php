<?php

namespace App\Services\Communication;

use App\Models\CabinetSetting;
use Carbon\CarbonInterface;

/**
 * Ready-to-send patient messages and one-click links (WhatsApp, SMS, call).
 *
 * No SMS gateway is needed: the links open WhatsApp or the phone's SMS app
 * with the text already written, so the assistant only presses « Envoyer ».
 */
final class PatientMessages
{
    private const DAYS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];

    private const MONTHS = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    private ?CabinetSetting $cabinet = null;

    /**
     * International digits for wa.me, e.g. « 0555 12 34 56 » → 213555123456.
     * Returns null when the number cannot be a phone number.
     */
    public function internationalPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0') && strlen($digits) === 10) {
            // Algerian national format (05, 06, 07 mobiles; 0x landlines).
            $digits = '213'.substr($digits, 1);
        } elseif (strlen($digits) === 9 && preg_match('/^[567]/', $digits) === 1) {
            $digits = '213'.$digits;
        }

        return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : null;
    }

    /**
     * @return array{whatsapp: string|null, sms: string|null, call: string|null, message: string}
     */
    public function links(?string $phone, string $message): array
    {
        $international = $this->internationalPhone($phone);
        $local = preg_replace('/[^\d+]+/', '', (string) $phone) ?? '';

        return [
            'whatsapp' => $international !== null ? 'https://wa.me/'.$international.'?text='.rawurlencode($message) : null,
            'sms' => $local !== '' ? 'sms:'.$local.'?body='.rawurlencode($message) : null,
            'call' => $local !== '' ? 'tel:'.$local : null,
            'message' => $message,
        ];
    }

    public function appointmentReminder(string $patientName, CarbonInterface $startsAt): string
    {
        return sprintf(
            "Bonjour %s,\nNous vous rappelons votre rendez-vous au %s le %s à %s.\nEn cas d’empêchement, merci de nous prévenir%s.\nCordialement.",
            $patientName,
            $this->cabinetName(),
            $this->longDate($startsAt),
            $startsAt->format('H:i'),
            $this->phoneSuffix(),
        );
    }

    public function recallReminder(string $patientName, string $reason, CarbonInterface $dueOn): string
    {
        return sprintf(
            "Bonjour %s,\nLe %s vous rappelle qu’un contrôle est prévu vers le %s (%s).\nMerci de nous contacter pour fixer un rendez-vous%s.\nCordialement.",
            $patientName,
            $this->cabinetName(),
            $this->longDate($dueOn),
            $reason,
            $this->phoneSuffix(),
        );
    }

    public function balanceReminder(string $patientName, float $amount, string $currency): string
    {
        return sprintf(
            "Bonjour %s,\nUn solde de %s %s reste à régler au %s.\nVous pouvez le régler lors de votre prochaine visite%s.\nMerci et bonne journée.",
            $patientName,
            number_format($amount, 0, ',', ' '),
            $currency,
            $this->cabinetName(),
            $this->phoneSuffix(' ou nous appeler au '),
        );
    }

    private function longDate(CarbonInterface $date): string
    {
        return self::DAYS[(int) $date->dayOfWeek].' '.$date->day.' '.self::MONTHS[(int) $date->month];
    }

    private function cabinetName(): string
    {
        $name = trim((string) $this->cabinet()->name);

        return $name !== '' ? 'cabinet '.$name : 'cabinet médical';
    }

    private function phoneSuffix(string $prefix = ' au '): string
    {
        $phone = trim((string) $this->cabinet()->phone);

        return $phone !== '' ? $prefix.$phone : '';
    }

    private function cabinet(): CabinetSetting
    {
        return $this->cabinet ??= CabinetSetting::current();
    }
}
