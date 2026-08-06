export type NativeUpdateMetadata = {
    version: string;
    current_version: string;
    published_at: string | null;
};

export type NativeUpdaterStatus = {
    configured: boolean;
    current_version: string;
    pending_update: NativeUpdateMetadata | null;
    last_checked_at: number | null;
    checking: boolean;
    installing: boolean;
};

export type NativeUpdateCheckResponse = {
    update: NativeUpdateMetadata | null;
    checked_at: number;
};

export type NativeUpdateInstallResponse = {
    accepted: true;
    target_version: string;
    message_fr: string;
};

export type UpdateInstallAuthorization = {
    protocol: 'medismart-update-install-authorization';
    version: 1;
    target_version: string;
    backup_record_id: string;
    backup_sha256: string;
    installation_id: string;
    issued_at: number;
    expires_at: number;
    nonce: string;
    signature: string;
};

export type UpdateInstallPreparation = {
    authorization: UpdateInstallAuthorization;
    backup: {
        id: string;
        filename: string;
        sha256_hint: string;
        completed_at: string;
    };
};

const MAX_VERSION_LENGTH = 64;
const MAX_NATIVE_TIMESTAMP_LENGTH = 64;
const MAX_BACKUP_FILENAME_LENGTH = 255;
const MAX_AUTHORIZATION_LIFETIME_SECONDS = 600;
const MAX_NATIVE_ERROR_MESSAGE_LENGTH = 160;
const SIGNED_UPDATER_FALLBACK_MESSAGE =
    'Le programme de mise à jour signé n’a pas répondu.';
const SIGNED_UPDATE_INSTALL_SUCCESS_MESSAGE =
    'La mise à jour vérifiée est installée; MediSmart va redémarrer.';

const SIGNED_UPDATER_ERROR_MESSAGES = {
    signed_updater_unavailable:
        'Le programme de mise à jour signé n’est pas disponible dans cette version.',
    signed_updater_busy: 'Une opération de mise à jour est déjà en cours.',
    signed_update_check_failed:
        'La recherche de mise à jour a échoué. Vérifiez la connexion puis réessayez.',
    signed_update_not_pending:
        'Aucune mise à jour vérifiée n’attend une installation.',
    update_install_authorization_invalid:
        'L’autorisation d’installation ou sa sauvegarde de sécurité n’est pas valide.',
    update_install_authorization_expired:
        'L’autorisation d’installation a expiré. Recréez la sauvegarde de sécurité.',
    signed_update_install_failed:
        'La mise à jour signée n’a pas pu être téléchargée ou installée.',
} as const;

type SignedUpdaterErrorCode = keyof typeof SIGNED_UPDATER_ERROR_MESSAGES;

const isRecord = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null && !Array.isArray(value);

const hasExactKeys = (
    value: Record<string, unknown>,
    expectedKeys: readonly string[],
): boolean => {
    const keys = Object.keys(value);

    return (
        keys.length === expectedKeys.length &&
        expectedKeys.every((key) =>
            Object.prototype.hasOwnProperty.call(value, key),
        )
    );
};

const isVersion = (value: unknown): value is string =>
    typeof value === 'string' &&
    value.length <= MAX_VERSION_LENGTH &&
    /^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/.test(
        value,
    );

const isUuid = (value: unknown): value is string =>
    typeof value === 'string' &&
    /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(
        value,
    );

const isBoundedNativeTimestamp = (value: unknown): value is string =>
    typeof value === 'string' &&
    value.length > 0 &&
    value.length <= MAX_NATIVE_TIMESTAMP_LENGTH &&
    value.trim() === value &&
    !/[\u0000-\u001f\u007f]/.test(value);

const isRfc3339Timestamp = (value: unknown): value is string => {
    if (typeof value !== 'string' || value.length < 20 || value.length > 35) {
        return false;
    }

    const match =
        /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,9})?(?:Z|[+-](\d{2}):(\d{2}))$/.exec(
            value,
        );

    if (match === null) {
        return false;
    }

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const hour = Number(match[4]);
    const minute = Number(match[5]);
    const second = Number(match[6]);
    const offsetHour = Number(match[7] ?? 0);
    const offsetMinute = Number(match[8] ?? 0);
    const leapYear = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
    const daysInMonth = [
        31,
        leapYear ? 29 : 28,
        31,
        30,
        31,
        30,
        31,
        31,
        30,
        31,
        30,
        31,
    ];

    return (
        year >= 1970 &&
        month >= 1 &&
        month <= 12 &&
        day >= 1 &&
        day <= daysInMonth[month - 1] &&
        hour <= 23 &&
        minute <= 59 &&
        second <= 59 &&
        offsetHour <= 23 &&
        offsetMinute <= 59
    );
};

const isSafeBackupFilename = (value: unknown): value is string =>
    typeof value === 'string' &&
    value.length > '.msbackup'.length &&
    value.length <= MAX_BACKUP_FILENAME_LENGTH &&
    /^[A-Za-z0-9](?:[A-Za-z0-9._-]*[A-Za-z0-9_-])?\.msbackup$/.test(value);

const isSignedUpdaterErrorCode = (
    value: unknown,
): value is SignedUpdaterErrorCode =>
    typeof value === 'string' &&
    Object.prototype.hasOwnProperty.call(SIGNED_UPDATER_ERROR_MESSAGES, value);

const isBoundedNativeErrorMessage = (value: unknown): value is string =>
    typeof value === 'string' &&
    value.length > 0 &&
    value.length <= MAX_NATIVE_ERROR_MESSAGE_LENGTH &&
    value.trim() === value &&
    !/[\u0000-\u001f\u007f]/.test(value);

const isMetadata = (value: unknown): value is NativeUpdateMetadata =>
    isRecord(value) &&
    hasExactKeys(value, ['version', 'current_version', 'published_at']) &&
    isVersion(value.version) &&
    isVersion(value.current_version) &&
    (value.published_at === null ||
        isBoundedNativeTimestamp(value.published_at));

export const normalizeNativeUpdaterStatus = (
    value: unknown,
): NativeUpdaterStatus | null => {
    if (
        !isRecord(value) ||
        !hasExactKeys(value, [
            'configured',
            'current_version',
            'pending_update',
            'last_checked_at',
            'checking',
            'installing',
        ]) ||
        typeof value.configured !== 'boolean' ||
        !isVersion(value.current_version) ||
        (value.pending_update !== null && !isMetadata(value.pending_update)) ||
        (value.last_checked_at !== null &&
            (!Number.isSafeInteger(value.last_checked_at) ||
                Number(value.last_checked_at) <= 0)) ||
        typeof value.checking !== 'boolean' ||
        typeof value.installing !== 'boolean'
    ) {
        return null;
    }

    return value as NativeUpdaterStatus;
};

export const normalizeNativeUpdateCheck = (
    value: unknown,
): NativeUpdateCheckResponse | null => {
    if (
        !isRecord(value) ||
        !hasExactKeys(value, ['update', 'checked_at']) ||
        (value.update !== null && !isMetadata(value.update)) ||
        !Number.isSafeInteger(value.checked_at) ||
        Number(value.checked_at) <= 0
    ) {
        return null;
    }

    return value as NativeUpdateCheckResponse;
};

export const normalizeNativeUpdateInstallResponse = (
    value: unknown,
): NativeUpdateInstallResponse | null => {
    if (
        !isRecord(value) ||
        !hasExactKeys(value, ['accepted', 'target_version', 'message_fr']) ||
        value.accepted !== true ||
        !isVersion(value.target_version) ||
        value.message_fr !== SIGNED_UPDATE_INSTALL_SUCCESS_MESSAGE
    ) {
        return null;
    }

    return value as NativeUpdateInstallResponse;
};

export const normalizeUpdateInstallPreparation = (
    value: unknown,
): UpdateInstallPreparation | null => {
    if (
        !isRecord(value) ||
        !hasExactKeys(value, ['authorization', 'backup']) ||
        !isRecord(value.authorization) ||
        !hasExactKeys(value.authorization, [
            'protocol',
            'version',
            'target_version',
            'backup_record_id',
            'backup_sha256',
            'installation_id',
            'issued_at',
            'expires_at',
            'nonce',
            'signature',
        ]) ||
        !isRecord(value.backup) ||
        !hasExactKeys(value.backup, [
            'id',
            'filename',
            'sha256_hint',
            'completed_at',
        ])
    ) {
        return null;
    }

    const authorization = value.authorization;
    const backup = value.backup;

    if (
        authorization.protocol !== 'medismart-update-install-authorization' ||
        authorization.version !== 1 ||
        !isVersion(authorization.target_version) ||
        !isUuid(authorization.backup_record_id) ||
        typeof authorization.backup_sha256 !== 'string' ||
        !/^[0-9a-f]{64}$/.test(authorization.backup_sha256) ||
        !isUuid(authorization.installation_id) ||
        !Number.isSafeInteger(authorization.issued_at) ||
        Number(authorization.issued_at) <= 0 ||
        !Number.isSafeInteger(authorization.expires_at) ||
        Number(authorization.expires_at) <= Number(authorization.issued_at) ||
        Number(authorization.expires_at) - Number(authorization.issued_at) >
            MAX_AUTHORIZATION_LIFETIME_SECONDS ||
        !isUuid(authorization.nonce) ||
        typeof authorization.signature !== 'string' ||
        !/^[0-9a-f]{64}$/.test(authorization.signature) ||
        !isUuid(backup.id) ||
        backup.id !== authorization.backup_record_id ||
        !isSafeBackupFilename(backup.filename) ||
        typeof backup.sha256_hint !== 'string' ||
        !/^[0-9a-f]{12}…$/.test(backup.sha256_hint) ||
        backup.sha256_hint !== `${authorization.backup_sha256.slice(0, 12)}…` ||
        !isRfc3339Timestamp(backup.completed_at)
    ) {
        return null;
    }

    return value as UpdateInstallPreparation;
};

export const signedUpdaterErrorMessage = (error: unknown): string => {
    if (
        isRecord(error) &&
        hasExactKeys(error, ['code', 'message_fr']) &&
        isSignedUpdaterErrorCode(error.code) &&
        isBoundedNativeErrorMessage(error.message_fr)
    ) {
        const expectedMessage = SIGNED_UPDATER_ERROR_MESSAGES[error.code];

        if (error.message_fr === expectedMessage) {
            return expectedMessage;
        }
    }

    return SIGNED_UPDATER_FALLBACK_MESSAGE;
};
