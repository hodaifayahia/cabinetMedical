<?php

namespace App\Enums;

enum PermissionName: string
{
    case PATIENTS_VIEW = 'patients.view';
    case PATIENTS_CREATE = 'patients.create';
    case PATIENTS_UPDATE = 'patients.update';
    case PATIENTS_DELETE = 'patients.delete';
    case PATIENTS_VIEW_MEDICAL_RECORD = 'patients.view-medical-record';

    case APPOINTMENTS_VIEW = 'appointments.view';
    case APPOINTMENTS_CREATE = 'appointments.create';
    case APPOINTMENTS_UPDATE = 'appointments.update';
    case APPOINTMENTS_CANCEL = 'appointments.cancel';
    case APPOINTMENTS_CHECK_IN = 'appointments.check-in';
    case APPOINTMENTS_CONFIGURE = 'appointments.configure';

    case CONSULTATIONS_VIEW = 'consultations.view';
    case CONSULTATIONS_CREATE = 'consultations.create';
    case CONSULTATIONS_UPDATE = 'consultations.update';
    case CONSULTATIONS_COMPLETE = 'consultations.complete';

    case ENCOUNTERS_VIEW = 'encounters.view';
    case ENCOUNTERS_CREATE = 'encounters.create';
    case ENCOUNTERS_UPDATE = 'encounters.update';
    case ENCOUNTERS_SIGN = 'encounters.sign';
    case ENCOUNTERS_AMEND = 'encounters.amend';

    case PRESCRIPTIONS_VIEW = 'prescriptions.view';
    case PRESCRIPTIONS_CREATE = 'prescriptions.create';
    case PRESCRIPTIONS_PRINT = 'prescriptions.print';

    case PRODUCTS_VIEW = 'products.view';
    case PRODUCTS_CREATE = 'products.create';
    case PRODUCTS_UPDATE = 'products.update';
    case PRODUCTS_DELETE = 'products.delete';

    case STOCK_VIEW = 'stock.view';
    case STOCK_PURCHASE = 'stock.purchase';
    case STOCK_ADJUST = 'stock.adjust';
    case STOCK_VIEW_COST = 'stock.view-cost';

    case SALES_VIEW = 'sales.view';
    case SALES_CREATE = 'sales.create';
    case SALES_REFUND = 'sales.refund';
    case SALES_CANCEL = 'sales.cancel';

    case PAYMENTS_VIEW = 'payments.view';
    case PAYMENTS_CREATE = 'payments.create';
    case PAYMENTS_REFUND = 'payments.refund';

    case REPORTS_VIEW = 'reports.view';
    case REPORTS_EXPORT = 'reports.export';

    case STAFF_MANAGE = 'staff.manage';
    case SETTINGS_MANAGE = 'settings.manage';
    case CONFIGURATION_MANAGE = 'configuration.manage';
    case CONFIGURATION_BRANDING_MANAGE = 'configuration.branding.manage';
    case CONFIGURATION_CONNECTIVITY_MANAGE = 'configuration.connectivity.manage';
    case CONFIGURATION_BACKUPS_MANAGE = 'configuration.backups.manage';
    case CONFIGURATION_RESTORE_MANAGE = 'configuration.restore.manage';
    case CONFIGURATION_DRIVE_MANAGE = 'configuration.drive.manage';
    case CONFIGURATION_LICENSING_MANAGE = 'configuration.licensing.manage';
    case CONFIGURATION_DIAGNOSTICS_VIEW = 'configuration.diagnostics.view';
    case AUDIT_LOGS_VIEW = 'audit-logs.view';

    public function label(): string
    {
        return match ($this) {
            self::PATIENTS_VIEW => 'Consulter les patients',
            self::PATIENTS_CREATE => 'Ajouter des patients',
            self::PATIENTS_UPDATE => 'Modifier les patients',
            self::PATIENTS_DELETE => 'Supprimer les patients',
            self::PATIENTS_VIEW_MEDICAL_RECORD => 'Consulter le dossier médical',
            self::APPOINTMENTS_VIEW => 'Consulter les rendez-vous',
            self::APPOINTMENTS_CREATE => 'Créer des rendez-vous',
            self::APPOINTMENTS_UPDATE => 'Modifier et confirmer les rendez-vous',
            self::APPOINTMENTS_CANCEL => 'Annuler des rendez-vous',
            self::APPOINTMENTS_CHECK_IN => 'Enregistrer l’arrivée des patients',
            self::APPOINTMENTS_CONFIGURE => 'Configurer les disponibilités',
            self::CONSULTATIONS_VIEW => 'Consulter les consultations',
            self::CONSULTATIONS_CREATE => 'Démarrer des consultations',
            self::CONSULTATIONS_UPDATE => 'Modifier les consultations',
            self::CONSULTATIONS_COMPLETE => 'Clôturer les consultations',
            self::ENCOUNTERS_VIEW => 'Consulter les rencontres cliniques',
            self::ENCOUNTERS_CREATE => 'Créer des rencontres cliniques',
            self::ENCOUNTERS_UPDATE => 'Modifier les rencontres cliniques',
            self::ENCOUNTERS_SIGN => 'Signer les rencontres cliniques',
            self::ENCOUNTERS_AMEND => 'Amender les rencontres signées',
            self::PRESCRIPTIONS_VIEW => 'Consulter les ordonnances',
            self::PRESCRIPTIONS_CREATE => 'Créer des ordonnances',
            self::PRESCRIPTIONS_PRINT => 'Imprimer les ordonnances',
            self::PRODUCTS_VIEW => 'Consulter les produits',
            self::PRODUCTS_CREATE => 'Ajouter des produits',
            self::PRODUCTS_UPDATE => 'Modifier les produits',
            self::PRODUCTS_DELETE => 'Supprimer les produits',
            self::STOCK_VIEW => 'Consulter le stock',
            self::STOCK_PURCHASE => 'Enregistrer les achats de stock',
            self::STOCK_ADJUST => 'Ajuster le stock',
            self::STOCK_VIEW_COST => 'Consulter les coûts du stock',
            self::SALES_VIEW => 'Consulter les ventes',
            self::SALES_CREATE => 'Enregistrer des ventes',
            self::SALES_REFUND => 'Rembourser des ventes',
            self::SALES_CANCEL => 'Annuler des ventes',
            self::PAYMENTS_VIEW => 'Consulter les paiements',
            self::PAYMENTS_CREATE => 'Enregistrer les paiements',
            self::PAYMENTS_REFUND => 'Rembourser les paiements',
            self::REPORTS_VIEW => 'Consulter les rapports',
            self::REPORTS_EXPORT => 'Exporter les rapports',
            self::STAFF_MANAGE => 'Gérer les utilisateurs et leurs rôles',
            self::SETTINGS_MANAGE => 'Gérer les paramètres de sécurité',
            self::CONFIGURATION_MANAGE => 'Gérer les catalogues et la comptabilité',
            self::CONFIGURATION_BRANDING_MANAGE => 'Gérer l’identité du cabinet',
            self::CONFIGURATION_CONNECTIVITY_MANAGE => 'Gérer la connectivité',
            self::CONFIGURATION_BACKUPS_MANAGE => 'Gérer les sauvegardes',
            self::CONFIGURATION_RESTORE_MANAGE => 'Restaurer les sauvegardes',
            self::CONFIGURATION_DRIVE_MANAGE => 'Gérer Google Drive',
            self::CONFIGURATION_LICENSING_MANAGE => 'Gérer la licence',
            self::CONFIGURATION_DIAGNOSTICS_VIEW => 'Consulter les diagnostics',
            self::AUDIT_LOGS_VIEW => 'Consulter le journal d’audit',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::PATIENTS_VIEW,
            self::PATIENTS_CREATE,
            self::PATIENTS_UPDATE,
            self::PATIENTS_DELETE,
            self::PATIENTS_VIEW_MEDICAL_RECORD => 'patients',
            self::APPOINTMENTS_VIEW,
            self::APPOINTMENTS_CREATE,
            self::APPOINTMENTS_UPDATE,
            self::APPOINTMENTS_CANCEL,
            self::APPOINTMENTS_CHECK_IN,
            self::APPOINTMENTS_CONFIGURE => 'appointments',
            self::CONSULTATIONS_VIEW,
            self::CONSULTATIONS_CREATE,
            self::CONSULTATIONS_UPDATE,
            self::CONSULTATIONS_COMPLETE => 'consultations',
            self::ENCOUNTERS_VIEW,
            self::ENCOUNTERS_CREATE,
            self::ENCOUNTERS_UPDATE,
            self::ENCOUNTERS_SIGN,
            self::ENCOUNTERS_AMEND => 'encounters',
            self::PRESCRIPTIONS_VIEW,
            self::PRESCRIPTIONS_CREATE,
            self::PRESCRIPTIONS_PRINT => 'prescriptions',
            self::PRODUCTS_VIEW,
            self::PRODUCTS_CREATE,
            self::PRODUCTS_UPDATE,
            self::PRODUCTS_DELETE,
            self::STOCK_VIEW,
            self::STOCK_PURCHASE,
            self::STOCK_ADJUST,
            self::STOCK_VIEW_COST => 'inventory',
            self::SALES_VIEW,
            self::SALES_CREATE,
            self::SALES_REFUND,
            self::SALES_CANCEL => 'sales',
            self::PAYMENTS_VIEW,
            self::PAYMENTS_CREATE,
            self::PAYMENTS_REFUND => 'payments',
            self::REPORTS_VIEW,
            self::REPORTS_EXPORT => 'reports',
            self::STAFF_MANAGE,
            self::SETTINGS_MANAGE => 'administration',
            self::CONFIGURATION_MANAGE,
            self::CONFIGURATION_BRANDING_MANAGE,
            self::CONFIGURATION_CONNECTIVITY_MANAGE,
            self::CONFIGURATION_BACKUPS_MANAGE,
            self::CONFIGURATION_RESTORE_MANAGE,
            self::CONFIGURATION_DRIVE_MANAGE,
            self::CONFIGURATION_LICENSING_MANAGE,
            self::CONFIGURATION_DIAGNOSTICS_VIEW => 'configuration',
            self::AUDIT_LOGS_VIEW => 'audit',
        };
    }

    public static function groupLabel(string $group): string
    {
        return match ($group) {
            'patients' => 'Patients',
            'appointments' => 'Rendez-vous',
            'consultations' => 'Consultations',
            'encounters' => 'Rencontres cliniques',
            'prescriptions' => 'Ordonnances',
            'inventory' => 'Produits et stock',
            'sales' => 'Ventes',
            'payments' => 'Paiements',
            'reports' => 'Rapports',
            'administration' => 'Administration',
            'configuration' => 'Configuration',
            'audit' => 'Audit',
            default => $group,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $permission): string => $permission->value,
            self::cases(),
        );
    }
}
