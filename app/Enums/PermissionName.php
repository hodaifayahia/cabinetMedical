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
