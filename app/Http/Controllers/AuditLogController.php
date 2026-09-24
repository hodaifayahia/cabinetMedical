<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Qui a fait quoi, et quand » — read-only view of the cabinet's audit
 * trail (payments, refunds, prescriptions, patient file changes…).
 */
class AuditLogController extends Controller
{
    /** Human labels for the most common actions; others show their key. */
    private const LABELS = [
        'auth.login' => 'Connexion',
        'auth.logout' => 'Déconnexion',
        'payment.collected' => 'Paiement encaissé',
        'payment.updated' => 'Paiement modifié',
        'payment.refunded' => 'Remboursement',
        'expense.created' => 'Charge ajoutée',
        'expense.updated' => 'Charge modifiée',
        'expense.deleted' => 'Charge supprimée',
        'expense.recurring_copied' => 'Charges récurrentes reportées',
        'patient.alert_added' => 'Fiche de sécurité : ajout',
        'patient.alert_deactivated' => 'Fiche de sécurité : retrait',
        'patient.alert_deleted' => 'Fiche de sécurité : suppression',
        'prescription.allergy_override' => 'Ordonnance malgré une allergie',
        'prescription_protocol.created' => 'Protocole d’ordonnance créé',
        'prescription_protocol.deleted' => 'Protocole d’ordonnance supprimé',
        'patient.recall_created' => 'Rappel programmé',
        'patient.recall_done' => 'Rappel effectué',
        'patient.recall_cancelled' => 'Rappel annulé',
        'patient.recall_contacted' => 'Patient contacté (rappel)',
        'appointment.reminded' => 'Patient prévenu (rendez-vous)',
        'consultation.diagnoses_coded' => 'Diagnostic codé (CIM-10)',
        'patient.vaccination_recorded' => 'Vaccination enregistrée',
        'patient.vaccination_deleted' => 'Vaccination supprimée',
    ];

    public function __invoke(Request $request): Response
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:80'],
            'user' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $logs = AuditLog::query()
            ->with('user:id,name')
            ->when(filled($filters['action'] ?? null), static fn (Builder $query) => $query->where('action', 'like', $filters['action'].'%'))
            ->when(filled($filters['user'] ?? null), static fn (Builder $query) => $query->where('user_id', $filters['user']))
            ->when(filled($filters['from'] ?? null), static fn (Builder $query) => $query->whereDate('created_at', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), static fn (Builder $query) => $query->whereDate('created_at', '<=', $filters['to']))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString()
            ->through(static fn (AuditLog $log): array => [
                'id' => $log->getKey(),
                'at' => $log->created_at?->toIso8601String(),
                'action' => $log->action,
                'label' => self::LABELS[$log->action] ?? $log->action,
                'user' => $log->user?->name,
                'subject' => $log->subject_type !== null
                    ? class_basename((string) $log->subject_type).' #'.$log->subject_id
                    : null,
                'details' => self::details(is_array($log->metadata) ? $log->metadata : []),
                'ip' => $log->ip_address,
            ]);

        return Inertia::render('audit/Index', [
            'logs' => $logs,
            'filters' => [
                'action' => (string) ($filters['action'] ?? ''),
                'user' => isset($filters['user']) ? (string) $filters['user'] : '',
                'from' => (string) ($filters['from'] ?? ''),
                'to' => (string) ($filters['to'] ?? ''),
            ],
            'actions' => collect(self::LABELS)->map(static fn (string $label, string $key): array => ['value' => $key, 'label' => $label])->values(),
            'users' => User::query()
                ->when($request->user()?->cabinet_id !== null, static fn (Builder $query) => $query->where('cabinet_id', $request->user()?->cabinet_id))
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * A short, readable summary of the metadata (no nested dumps).
     *
     * @param  array<string, mixed>  $metadata
     */
    private static function details(array $metadata): string
    {
        $parts = [];

        foreach ($metadata as $key => $value) {
            if (is_array($value) || $value === null || $value === '') {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? 'oui' : 'non';
            } elseif (str_ends_with((string) $key, '_minor') && is_numeric($value)) {
                $key = substr((string) $key, 0, -6);
                $value = number_format(((int) $value) / 100, 2, ',', ' ');
            }

            $parts[] = str_replace('_', ' ', (string) $key).' : '.$value;

            if (count($parts) >= 6) {
                break;
            }
        }

        return implode(' · ', $parts);
    }
}
