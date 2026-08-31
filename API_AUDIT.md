# API Audit — mobile client readiness

This backend is **drclick/drclick**, a French-language, multi-tenant medical practice management application (Laravel 13, Inertia/Vue, Filament back office) built as a **local-first desktop product for single-doctor cabinets** — SQLite on the clinician's machine, a LAN "Cabinet Hub" mode, and a hosted control plane running this same codebase — with a small Sanctum-token API v1 that exists to serve **cabinet staff and desktop companion/sync clients, not patients**. The single most important finding: every patient-facing capability the planned mobile client needs (patient identity and phone auth, public doctor/clinic discovery by wilaya→baladiya→specialty, clinic detail content, family members, patient booking/cancel, notifications, patient-visible prescriptions, Arabic localization) is absent — and the one architectural mechanism that would have to carry patient accounts, the `BelongsToCabinet` tenancy scope, **fails open for exactly the account shape a patient would take** (a `User` with `cabinet_id = null` gets no tenant filter at all, `app/Models/Concerns/BelongsToCabinet.php:28-35`), so the mobile rollout is not an extension of this API but a green-field build that must first make tenancy fail closed.

## 1. Stack

### 1.1 Stack & exact versions

Package versions pinned in `composer.lock` (constraints from `composer.json:12-23`):

| Package | Constraint (composer.json) | Locked version (composer.lock) |
|---|---|---|
| PHP | `^8.3` (composer.json:12) | — |
| laravel/framework | `^13.17` (composer.json:18) | v13.23.0 (composer.lock:2666-2667) |
| laravel/fortify | `^1.37.2` (composer.json:17) | v1.37.3 (composer.lock:2602-2603) |
| laravel/sanctum | `^4.3` (composer.json:19) | v4.3.3 (composer.lock:3020-3021) |
| filament/filament | `^5.7` (composer.json:14) | v5.7.3 (composer.lock:1427-1428) |
| spatie/laravel-permission | `^8.3` (composer.json:23) | 8.3.0 (composer.lock:6526-6527) |
| inertiajs/inertia-laravel | `^3.0` (composer.json:15) | v3.2.0 (composer.lock:2415-2416) |

Also present: barryvdh/laravel-dompdf ^3.1, spatie/laravel-backup ^10.3, laravel/wayfinder, laravel/chisel (composer.json:13-22). Project name is `drclick/drclick`, a "medical practice management application" (composer.json:3-5).

**Database:** default connection is **SQLite** (`config/database.php:20` — `env('DB_CONNECTION', 'sqlite')`; `.env.example:127` — `DB_CONNECTION=sqlite`), tuned for a local-first desktop runtime: WAL journal mode, busy timeout, deferred transactions (config/database.php:35-45). MySQL/MariaDB connections are defined but commented out in `.env.example:134-138`. Sessions, queue, and cache all use the `database` driver on that same SQLite file (.env.example:140-152), with `SESSION_ENCRYPT=true` and session blocking. The `.env.example` makes the deployment model explicit: a **desktop launcher** supplies the SQLite path in AppData (.env.example:128-129), and there is a "Cabinet Hub" split — `HUB_MODE=false` for the hosted control plane vs. an always-on LAN machine bound to exactly one cabinet (.env.example:179-189, enforced by `EnforceHubCabinetBinding` prepended to the api group at bootstrap/app.php:65-67). Locale defaults are French/Algeria: `APP_LOCALE=fr`, `APP_TIMEZONE=Africa/Algiers`, currency DZD (.env.example:80-96).

**Deployment topology (verified):** the "online service the mobile app uses" is **not a third-party system — it is another deployment of this same codebase**. The importer states it outright ("Both sides run this codebase and encode with the same flags", `app/Services/Sync/AppointmentImporter.php:385-386`), and `config/hub.php:8-10, 23-25` confirms: the hosted control plane runs this app with `HUB_MODE=false` and serves `/api/v1` for all cabinets, while desktops sync against it using a staff-minted Sanctum token stored per-installation in `application_settings` (`sync.mobile.endpoint`/`sync.mobile.token`, `app/Services/Sync/MobileSyncSettings.php:10-42`). Notably, `MobileSyncSettings::configure()` has **no caller in `app/`** — only the test suite calls it (tests/Feature/Sync/MobileAppointmentSyncTest.php:59) — so desktop-to-cloud pairing is currently manual/unbuilt.

### 1.2 Auth mechanism — session (web) + Sanctum tokens (API v1)

- **Guards:** exactly one guard, `web`, driver `session` (config/auth.php:40-45). No token guard in auth.php; API auth is Sanctum's `auth:sanctum` bearer-token layer. Sanctum falls back to the `web` guard for stateful requests (config/sanctum.php:40) and has **no token expiration** (`'expiration' => null`, config/sanctum.php:53).
- **Fortify** runs the whole web auth surface on the `web` guard with `username = email` (config/fortify.php:18,48). Enabled features: registration, password reset, email verification, 2FA (confirmed), and passkeys/WebAuthn (config/fortify.php:163-175). Views are Inertia pages (`auth/Login`, `auth/Register`, etc., app/Providers/FortifyServiceProvider.php:71-102). Rate limiters exist for login, 2FA, passkeys, plus desktop-specific ones (`desktop-cabinet-login`, `desktop-pin-login`, `desktop-pin-enroll`, FortifyServiceProvider.php:107-153) — there is also a desktop offline PIN credential system (`DesktopPinCredential`, revoked on password change at app/Models/User.php:100-118).
- **Sanctum is genuinely used, not just installed.** `User` uses `HasApiTokens` (app/Models/User.php:25,52). `routes/api.php` defines an API v1 explicitly documented as serving "desktop/companion clients" with Sanctum personal access tokens (routes/api.php:11-21): public `POST /api/v1/auth/token` (throttle:login), `POST /api/v1/cabinets/register`, `POST /api/v1/cabinets/join`; then behind `auth:sanctum`: `auth/logout`, `me`; and behind `auth:sanctum` + `cabinet.active.api`: appointments CRUD, appointment sync pull/ack/push, `schedule`, and read-only patients (routes/api.php:23-58).
- **Token issuance** (`app/Http/Controllers/Api/V1/AuthController.php:23-56`): email + password + `device_name` → verifies hash, checks `CabinetAccessService::denialReason()` (403 with a machine-readable `reason`/`status` for pending/suspended cabinets, expired/inactive licenses, unapproved members), then `createToken($device_name)` and returns `{token: plainTextToken, user: UserResource}`. `UserResource` includes `roles` and flattened `permissions` plus the cabinet with its license (app/Http/Resources/UserResource.php:19-28). Tokens are created with **default wildcard abilities and never expire** (AuthController.php:50; config/sanctum.php:53). Logout deletes only the current token (AuthController.php:61-76).

### 1.3 Fortify registration flow

`CreateNewUser` delegates entirely to `RegisterCabinetAction` (app/Actions/Fortify/CreateNewUser.php:24-27). That action (app/Actions/Fortify/RegisterCabinetAction.php):

1. Validates profile fields + `phone` (regex, free text — never verified), `cabinet_name`, `specialization`, `wilaya` (integer 1–58 via `Wilayas::MIN/MAX`, RegisterCabinetAction.php:45-54; app/Support/Wilayas.php:11-13 confirms the 58-wilaya catalogue in config/wilayas.php).
2. In one transaction: creates a `Cabinet` in `PENDING` status with wilaya code (lines 95-100), creates the owner `User` with `email_verified_at` and `approved_at` force-set to now (lines 102-111 — email verification is stamped, never performed), assigns the `ADMINISTRATOR` role (an alias of `Doctor`, line 112, resolved via `findOrCreate` at lines 153-161), creates a `CabinetSetting` row, a `DoctorProfile` (specialty code, fee, duration), and a default Mon–Fri 09:00–17:00 `DoctorSchedule` per weekday (lines 186-202), then writes an `AuditLog`.

So web registration = **doctor/cabinet-owner signup only**. The second path, `JoinCabinetAction` (shared by web and the API `cabinets/join`), lets a staff member register against an existing cabinet by the **owner's email**: created with no role and `approved_at = null`, reserving one of `Cabinet::MAX_SEATS = 3` seats under a row lock (app/Actions/Cabinet/JoinCabinetAction.php:36-91; app/Models/Cabinet.php:43).

### 1.4 Role system — two real roles, spatie/laravel-permission

`RoleName` defines exactly **two** roles; everything else is a compile-time alias (app/Enums/RoleName.php:5-21):

| Role (Spatie, guard `web`) | Aliases pointing at it | Permissions (seeded) |
|---|---|---|
| `Doctor` ("Médecin (Super administrateur)") | SUPER_ADMINISTRATOR, ADMINISTRATOR | all ~51 permissions (database/seeders/RolesAndPermissionsSeeder.php:24-25) |
| `Assistant` | RECEPTIONIST, CASHIER, STOCK_MANAGER, PHARMACIST | 10 permissions: patients view/create/update, appointments view/create/update/cancel/check-in, payments view/create (RolesAndPermissionsSeeder.php:38-52) |

The seeder deletes any role not in the enum (RolesAndPermissionsSeeder.php:33). Permissions are the ~51 cases of `PermissionName` (app/Enums/PermissionName.php:7-67), grouped into patients/appointments/consultations/encounters/prescriptions/inventory/sales/payments/reports/administration/configuration/audit.

**Attachment:** roles attach to users via Spatie's `HasRoles` on `User` (app/Models/User.php:27,52). The owner gets `Doctor` at registration (RegisterCabinetAction.php:112); joined members have **no role until the owner approves them** (`approved_at` null, JoinCabinetAction.php:81-84). Per-cabinet customization exists: `CabinetRolePermissionSet` stores a cabinet-specific permission allow-list per role name (an empty array = fully revoked; no row = global default) (app/Models/CabinetRolePermissionSet.php:9-34), and `User::hasPermissionTo()`/`getAllPermissions()` are overridden to route through `CabinetRolePermissionService` whenever `cabinet_id` is set (app/Models/User.php:61-82). Separately, `users.is_platform_admin` (boolean, User.php:42) marks platform staff.

### 1.5 Multi-tenancy — Cabinet as tenant, global-scope enforcement

- Tenant = `Cabinet` (name, status PENDING/ACTIVE/SUSPENDED, specialization, `wilaya_code`, owner, license, max 3 seats) (app/Models/Cabinet.php:28-43).
- Users carry `cabinet_id` (app/Models/User.php:40,47). Tenant-owned models use the `BelongsToCabinet` trait (app/Models/Concerns/BelongsToCabinet.php): a global `cabinet` scope filters every query by `auth()->user()->cabinet_id` (lines 21-36), `creating` force-assigns the current user's `cabinet_id` (lines 38-54), and `updating` throws `AuthorizationException` on any cross-tenant write (lines 56-74). Platform admins bypass the scope (lines 24-26); **users with `cabinet_id = null` also get no filter at all** (lines 28-35 — "legacy unscoped accounts"). `withoutCabinetScope()` is the explicit escape hatch (lines 90-93).
- Access gating is centralized in `CabinetAccessService` (app/Services/Cabinet/CabinetAccessService.php:37-74): pending cabinet → blocked; suspended → blocked; hosted license expired/inactive → blocked; member unapproved → blocked; platform admins and **cabinet-less users are always eligible** (lines 39-48). Enforced identically on the web (`EnsureCabinetIsActive`, redirects, app/Http/Middleware/EnsureCabinetIsActive.php:31-55) and the API (`EnsureApiCabinetIsActive`, 403 JSON with reason codes, app/Http/Middleware/EnsureApiCabinetIsActive.php:26-43; aliased `cabinet.active.api` at bootstrap/app.php:62).

### 1.6 Filament — platform back office at /admin

One panel: `AdminPanelProvider`, id `admin`, path **`/admin`**, its own session login (app/Providers/Filament/AdminPanelProvider.php:34-38). It is the **platform operator's control plane**, not the clinic UI: nav groups "Clients", "Licences & activations", "Site public", "Administration" (lines 51-60), widgets for cabinet growth, pending cabinets, licence-expiry radar (lines 67-73). Access is restricted to `is_platform_admin === true` (`User::canAccessPanel`, app/Models/User.php:157-164); cabinet staff use the Inertia app. (Many tenant-facing Filament resources — Acts, Exams, Devices, AuditLogs, etc. — are deleted in the current working tree per git status, consistent with Filament narrowing to platform administration.)

### 1.7 Licensing layer

Licensing gates whether a cabinet may use the product at all. `License` (app/Models/License.php) holds plan (`TRIAL`/lifetime via `LicensePlan`), `license_type`, status, `issued_at`/`expires_at`/`offline_grace_until`, and a `signed_certificate` (hidden); `effectiveStatus()` computes expiry from the clock so a 7-day trial dies at its exact instant (License.php:101-108). A cabinet points at one license (`cabinets.license_id`, Cabinet.php:109-112). `HostedLicenseGrant` (app/Models/HostedLicenseGrant.php) is a cabinet-bound activation code minted by platform admins in Filament — only a hash + encrypted copy + display suffix stored, with redeem/revoke lifecycle. `LicenseActivation` + `Device` bind a license to a machine via `machine_fingerprint_hash` for the desktop's offline signed-certificate activation (app/Models/LicenseActivation.php:10-42; verification endpoints/public key in `.env.example:55-61` `MEDISMART_LICENSE_*`). Enforcement: `CabinetAccessService` denies web and API access when a hosted entitlement is expired or not active (CabinetAccessService.php:58-67); the only route a blocked owner keeps is `cabinet.license.redeem` plus logout/status pages (EnsureCabinetIsActive.php:57-68). API clients hit the same wall through `cabinet.active.api` and even at token issuance (AuthController.php:41-48). Suspending a cabinet revokes its desktop PIN credentials (Cabinet.php:54-72).

### 1.8 Fit against the planned mobile client (auth area)

The API v1 that exists today is a **staff/desktop-companion API for one cabinet's data**, not a public patient API. Auth is email+password; identity, roles, and tenancy are all built around cabinet staff. There is no patient identity, no phone-based auth, and the "always eligible when cabinet_id is null" rule is exactly backwards for a public patient population (see §4 and §5).

## 2. Routes

### 2a. Web routes

**Registration** (`bootstrap/app.php`): `routes/web.php` is the web group (bootstrap/app.php:29); `routes/api.php` is registered separately under prefix `api` (bootstrap/app.php:30-31, covered in §2b); health endpoints `GET /up` (bootstrap/app.php:33) and `GET /health` → `HealthController` (bootstrap/app.php:35). `routes/settings.php` is `require`d at the end of web.php (routes/web.php:439), so it is part of the web group.

**Web middleware group additions** (bootstrap/app.php:69-87, run on every web route): `MarkJsonOnlyEndpointsAsXhr`, `HandleAppearance`, `EnforceHubCabinetBinding` (hub/cabinet machine boundary), `ThrottleCabinetRegistration` + `DenyCabinetRegistrationOnHub` (self-scope to Fortify `/register`), `EnforceSessionLock`, `EnsureCabinetIsActive` (licence/lifecycle gate), `HandleInertiaRequests`, `AddLinkHeadersForPreloadedAssets`. Global: `SecureResponseHeaders` appended (bootstrap/app.php:42); `EnforceRemoteUploadBoundary` replaces `TrustProxies` (bootstrap/app.php:49). CSRF exempt: `app/configuration/models/*/callback` and `app/clinical-documents/*/callback` (bootstrap/app.php:53-56). Middleware aliases: `role`, `permission`, `role_or_permission` (Spatie), `cabinet.active.api` (bootstrap/app.php:58-63). All auth below is the **session `web` guard** (config/fortify.php:18) — there is no token guard anywhere in the web layer.

#### Public (no auth)

| Method | Path | Handler | Middleware | Auth | Serves |
|---|---|---|---|---|---|
| GET | `/` | closure → Inertia `Welcome` | web | no | Marketing landing page with published `LandingSection` content (routes/web.php:49-90) |
| GET | `/home` | closure redirect | web | no | Legacy alias → `/` (routes/web.php:93-94) |
| GET | `/desktop/download` | `DesktopDownloadLeadController@show` | web | no | Desktop installer download / lead-capture page (routes/web.php:99-100) |
| POST | `/desktop/download` | `DesktopDownloadLeadController@store` | `throttle:desktop-download-leads` | no | Stores a download lead, issues download link (routes/web.php:101-103) |
| GET | `/desktop/download/file/{lead}` | `DesktopDownloadController` (invokable) | `signed`, `throttle:desktop-download-files` | no (signed URL) | Streams the desktop installer file (routes/web.php:104-106) |
| GET | `/up` | framework health | — | no | Liveness check (bootstrap/app.php:33) |
| GET | `/health` | `HealthController` | — | no | App health status (bootstrap/app.php:35) |
| GET | `/.well-known/passkey-endpoints` | closure JSON | web | no | Advertises passkey enroll/manage URLs (routes/settings.php:43-48) |

#### Guest auth — desktop & cabinet flows

| Method | Path | Handler | Middleware | Auth | Serves |
|---|---|---|---|---|---|
| GET | `/desktop/cabinet-login` | `DesktopCabinetLoginController@create` | `guest` | no | Desktop cabinet login form (routes/web.php:109-110) |
| POST | `/desktop/cabinet-login` | `DesktopCabinetLoginController@store` | `guest`, `throttle:desktop-cabinet-login` | no | Desktop cabinet credential login (routes/web.php:111-113) |
| POST | `/desktop/pin/login` | `DesktopPinLoginController` | `throttle:desktop-pin-login` | no (device token + PIN are the credential) | PIN-based session login for desktop builds (routes/web.php:117-119) |
| POST | `/desktop/pin/enroll` | `DesktopPinEnrollmentController` | `auth`, `verified`, `throttle:desktop-pin-enroll` | yes (web) | Enroll a device PIN (routes/web.php:121-123) |
| GET | `/join` | `JoinCabinetController@create` | `throttle:cabinet-join` | no | Public "join an existing cabinet" (staff) form (routes/web.php:126-127) |
| POST | `/join` | `JoinCabinetController@store` | `throttle:cabinet-join` | no | Submits staff join request → pending approval (routes/web.php:128) |

#### Guest auth — Fortify-registered routes (not in web.php; registered by the package)

Fortify runs with `guard: web`, `middleware: ['web']`, no prefix, `views => true`, and features `registration`, `resetPasswords`, `emailVerification`, `twoFactorAuthentication` (confirmed), `passkeys` (config/fortify.php:18, 104, 134, 163-175). Username is **email** (config/fortify.php:48) — no phone-based login. Standard routes so registered:

| Method | Path | Middleware | Auth | Serves |
|---|---|---|---|---|
| GET/POST | `/login` | `guest`, POST `throttle:login` | no | Email+password session login |
| POST | `/logout` | `auth` | yes | Session logout |
| GET/POST | `/register` | `guest` (+ `ThrottleCabinetRegistration`, `DenyCabinetRegistrationOnHub` self-scoped, bootstrap/app.php:79-82) | no | **Cabinet owner** registration (`RegisterCabinetAction`), not patient signup |
| GET/POST | `/forgot-password`, GET `/reset-password/{token}`, POST `/reset-password` | `guest` | no | Email password reset |
| GET | `/email/verify`, GET `/email/verify/{id}/{hash}` (signed), POST `/email/verification-notification` | `auth` | yes | Email verification |
| GET/POST | `/user/confirm-password`, GET `/user/confirmed-password-status` | `auth` | yes | Password re-confirmation (legacy alias GET `/password/confirm` redirects here, routes/web.php:95-97) |
| GET/POST | `/two-factor-challenge` | `guest`, `throttle:two-factor` | no | 2FA challenge |
| POST/DELETE | `/user/two-factor-authentication` + qr-code/secret-key/recovery-codes GETs, POST `/user/confirmed-two-factor-authentication` | `auth`, `password.confirm` | yes | 2FA management |
| various | passkey registration/assertion endpoints | per `Features::passkeys` | mixed | WebAuthn passkeys (config/fortify.php:145-150, 172-174) |

#### Authenticated — cabinet lifecycle & session lock (all `auth`, web guard)

| Method | Path | Handler | Extra middleware | Serves |
|---|---|---|---|---|
| GET | `/cabinet/pending` | `CabinetStatusController@pending` | — | Awaiting-licence screen (routes/web.php:133-134) |
| GET | `/cabinet/license/redeem` | closure redirect → cabinet.pending | — | Installer/email deep link (routes/web.php:136-137) |
| GET | `/cabinet/awaiting-approval` | `CabinetStatusController@awaitingApproval` | — | Pending-member wait screen (routes/web.php:138-139) |
| POST | `/cabinet/sign-out` | `CabinetStatusController@signOut` | — | Sign out from gated state (routes/web.php:140-141) |
| POST | `/cabinet/license/redeem` | `RedeemHostedLicenseCodeController` | `throttle:license-activation` | Redeem hosted licence code (routes/web.php:142-144) |
| GET | `/session/locked` | `SessionLockController@show` | — | Lock screen (routes/web.php:148) |
| POST | `/session/lock` | `SessionLockController@lock` | — | Manual lock (routes/web.php:149) |
| POST | `/session/lock/idle` | `SessionLockController@lockIdle` | — | Idle auto-lock (routes/web.php:150) |
| POST | `/session/activity` | `SessionLockController@activity` | — | Activity heartbeat (routes/web.php:151) |
| POST | `/session/unlock` | `SessionLockController@unlock` | `throttle:session-unlock` | Unlock with credential/PIN (routes/web.php:152-154) |

#### Authenticated app (`auth` + `verified`; prefix `/app`, names `app.*`) — routes/web.php:157-418

| Method | Path | Handler | Extra middleware | Serves |
|---|---|---|---|---|
| GET | `/dashboard` | `DashboardController` | — | Role-aware dashboard (routes/web.php:158) |
| GET | `/app` | redirect | — | → `/dashboard` (routes/web.php:160) |
| GET | `/app/patients` | `PatientController@index` | — | Patient list (routes/web.php:163) |
| GET | `/app/patients/create` | `PatientController@create` | — | New patient form |
| POST | `/app/patients` | `PatientController@store` | — | Create patient |
| GET | `/app/patients/{patient}` | `PatientController@show` | — | Patient record |
| GET | `/app/patients/{patient}/edit` | `PatientController@edit` | — | Edit form |
| PUT/PATCH | `/app/patients/{patient}` | `PatientController@update` | — | Update patient |
| GET | `/app/patients/{patient}/json` | `PatientController@showJson` | — | Patient as JSON (routes/web.php:164) |
| POST | `/app/patients/json` | `PatientController@storeJson` | — | Create patient via JSON (routes/web.php:165) |
| PUT | `/app/patients/{patient}/json` | `PatientController@updateJson` | — | Update patient via JSON (routes/web.php:166) |
| GET | `/app/patients/{patient}/encounters` | `EncounterController@index` | — | Encounter list (routes/web.php:169) |
| GET | `/app/patients/{patient}/encounters/create` | `EncounterController@create` | — | New encounter form |
| POST | `/app/patients/{patient}/encounters` | `EncounterController@store` | — | Create encounter |
| GET | `/app/patients/{patient}/encounters/{encounter}` | `EncounterController@show` | — | Encounter detail |
| GET | `/app/patients/{patient}/encounters/{encounter}/edit` | `EncounterController@edit` | — | Edit encounter |
| PUT/PATCH | `/app/patients/{patient}/encounters/{encounter}` | `EncounterController@update` | — | Update encounter |
| POST | `.../encounters/{encounter}/sign` | `EncounterController@sign` | — | Sign (finalise) encounter (routes/web.php:170-171) |
| GET | `.../encounters/{encounter}/amend` | `EncounterController@createAmendment` | — | Amendment form (routes/web.php:172-173) |
| POST | `.../encounters/{encounter}/amend` | `EncounterController@storeAmendment` | — | Store amendment (routes/web.php:174-175) |
| GET | `/app/appointments` | `AppointmentController@index` | — | Appointment book (routes/web.php:178) |
| GET | `/app/appointments/print` | `AppointmentController@printList` | — | Printable day list (routes/web.php:179-180) |
| POST | `/app/appointments` | `AppointmentController@store` | — | Create appointment (staff-side) (routes/web.php:181) |
| POST | `/app/appointments/prestations` | `AppointmentController@storePrestation` | `permission:configuration.manage` | Create prestation/act (routes/web.php:183-184) |
| PUT | `/app/appointments/prestations/{source}/{id}` | `AppointmentController@updatePrestation` | `permission:configuration.manage` | Update prestation (routes/web.php:185-186) |
| PATCH | `/app/appointments/{appointment}/confirm` | `AppointmentController@confirm` | — | Confirm appointment (routes/web.php:188-189) |
| PATCH | `/app/appointments/{appointment}/check-in` | `AppointmentController@checkIn` | — | Check in patient (routes/web.php:190-191) |
| PATCH | `/app/appointments/{appointment}/cancel` | `AppointmentController@cancel` | — | Cancel appointment (routes/web.php:192-193) |
| POST | `/app/appointments/sync-with-mobile` | `MobileSyncController` | policy: `create Appointment` (app/Http/Controllers/Sync/MobileSyncController.php:28) | Clinician-triggered two-way sync with the online mobile service (routes/web.php:197-198) |
| POST | `/app/appointments/mobile-sync` | `AppointmentController@syncMobileDay` | — | Republish a day snapshot to outgoing stream (routes/web.php:199-200) |
| POST | `/app/appointments/{appointment}/mobile-sync` | `AppointmentController@syncMobile` | — | Republish one appointment (routes/web.php:201-202) |
| GET | `/app/appointments/availability/month` | `AvailabilityController@month` | — | Month availability grid (routes/web.php:203-204) |
| GET | `/app/appointments/availability/day` | `AvailabilityController@day` | — | Day slot availability (routes/web.php:205-206) |
| GET | `/app/consultations` | `ConsultationController@index` | `permission:consultations.view` | Consultation worklist (routes/web.php:209) |
| GET | `/app/patients/{patient}/consultation-history` | `ConsultationHistoryController@index` | `permission:consultations.view` | Patient consultation history (routes/web.php:210-211) |
| GET | `/app/consultation-history/{consultation}` | `ConsultationHistoryController@show` | `permission:consultations.view` | Past consultation detail (routes/web.php:212-213) |
| GET | `/app/consultations/{consultation}` | `ConsultationController@show` | `permission:consultations.view` | Live consultation screen (routes/web.php:214) |
| POST | `/app/consultations/{appointment}/start` | `ConsultationController@start` | `permission:consultations.create` | Start consultation from appointment (routes/web.php:216-217) |
| PUT | `/app/consultations/{consultation}` | `ConsultationController@update` | `permission:consultations.update` | Save consultation notes (routes/web.php:218-219) |
| PUT | `/app/consultations/{consultation}/patient` | `ConsultationController@savePatient` | `permission:consultations.update` | Update patient inside consultation (routes/web.php:220-221) |
| POST | `/app/consultations/{consultation}/schedule-next` | `ConsultationController@scheduleNext` | `permission:consultations.update` | Book follow-up (routes/web.php:222-223) |
| POST | `/app/consultations/{consultation}/measurements` | `ConsultationController@storeMeasurement` | `permission:consultations.update` | Add vitals/measurement (routes/web.php:224-225) |
| DELETE | `/app/consultations/{consultation}/measurements/{measurement}` | `ConsultationController@deleteMeasurement` | `permission:consultations.update` | Remove measurement (routes/web.php:226-227) |
| POST | `/app/consultations/{consultation}/prescriptions` | `ConsultationController@storePrescription` | `permission:prescriptions.create` | Create prescription (routes/web.php:228-229) |
| POST | `.../prescriptions/{prescription}/word-document` | `ConsultationController@createPrescriptionDocument` | `permission:prescriptions.create` | Generate prescription Word doc (routes/web.php:230-231) |
| POST | `/app/consultations/{consultation}/documents` | `ConsultationController@storeDocument` | `permission:consultations.update` | Attach generated document (routes/web.php:232-233) |
| POST | `/app/consultations/{consultation}/uploaded-documents` | `ConsultationController@uploadFile` | `permission:consultations.update` | Upload file to consultation (routes/web.php:234-235) |
| DELETE | `.../uploaded-documents/{document}` | `ConsultationController@destroyUploadedFile` | `permission:consultations.update` | Delete uploaded file (routes/web.php:236-237) |
| POST | `/app/consultations/{consultation}/word-documents` | `ClinicalDocumentController@store` | `permission:consultations.update` | Create OnlyOffice clinical doc (routes/web.php:238-239) |
| POST | `.../word-documents/{document}/convert` | `ClinicalDocumentController@convert` | `permission:consultations.update` | Convert doc format (routes/web.php:240-241) |
| GET | `/app/appointments/configure` | `ScheduleController@edit` | `permission:appointments.configure` | Doctor working-hours editor (routes/web.php:244) |
| PUT | `/app/appointments/schedule` | `ScheduleController@update` | `permission:appointments.configure` | Save weekly schedule (routes/web.php:245) |
| POST | `/app/appointments/open-months` | `OpenMonthController@store` | `permission:appointments.configure` | Open a bookable month (routes/web.php:246) |
| DELETE | `/app/appointments/open-months/{openMonth}` | `OpenMonthController@destroy` | `permission:appointments.configure` | Close a month (routes/web.php:247-248) |
| POST | `/app/appointments/time-off` | `TimeOffController@store` | `permission:appointments.configure` | Add leave/time-off block (routes/web.php:249) |
| DELETE | `/app/appointments/time-off/{timeOff}` | `TimeOffController@destroy` | `permission:appointments.configure` | Remove time-off (routes/web.php:250-251) |
| GET | `/app/payments` | `PaymentController@index` | `permission:payments.view` | Payments ledger (routes/web.php:255) |
| GET | `/app/payments/print` | `PaymentController@printReport` | `permission:payments.view` | Printable payments report (routes/web.php:256) |
| GET | `/app/payments/{consultation}/print` | `PaymentController@printReceipt` | `permission:payments.view` | Receipt (routes/web.php:257-258) |
| PATCH | `/app/payments/{consultation}` | `PaymentController@update` | `permission:payments.create` | Adjust payment (routes/web.php:260-262) |
| POST | `/app/consultations/{consultation}/payments` | `PaymentController@store` | `permission:payments.create` | Record payment (routes/web.php:263-265) |
| GET | `/app/staff` | `StaffIndexController` | `permission:staff.manage` | Staff list (routes/web.php:406) |
| POST | `/app/staff` | `StaffIndexController@store` | `permission:staff.manage` | Create staff account (routes/web.php:407) |
| PUT | `/app/staff/{user}` | `StaffIndexController@update` | `permission:staff.manage` | Update staff (routes/web.php:408) |
| DELETE | `/app/staff/{user}` | `StaffIndexController@destroy` | `permission:staff.manage` | Remove staff (routes/web.php:409) |
| GET | `/app/staff/pending` | `PendingMemberController@index` | `permission:staff.manage` | Pending join requests (routes/web.php:411) |
| POST | `/app/staff/pending/{user}/approve` | `PendingMemberController@approve` | `permission:staff.manage` | Approve join request (routes/web.php:412-413) |
| DELETE | `/app/staff/pending/{user}` | `PendingMemberController@reject` | `permission:staff.manage` | Reject join request (routes/web.php:414-415) |

#### Authenticated app — `/app/configuration/*` (inside same `auth`+`verified` group; routes/web.php:267-403)

| Method | Path | Handler | Extra middleware | Serves |
|---|---|---|---|---|
| GET | `/app/configuration` | closure | big `permission:` OR-list (routes/web.php:286) | Redirect to first permitted config screen (routes/web.php:268-287) |
| GET | `/app/configuration/roles-permissions` | `RolePermissionController@index` | none at route level — controller enforces `CabinetRolePermissionAuthorizer::canManage` (app/Http/Controllers/Configuration/RolePermissionController.php:253-262) | Role/permission matrix (routes/web.php:289-290) |
| PUT | `/app/configuration/roles-permissions` | `RolePermissionController@update` | controller-level authz (same) | Save permission matrix (routes/web.php:291-292) |
| PUT | `/app/configuration/roles-permissions/users/{user}` | `RolePermissionController@assignRole` | controller-level authz; `whereNumber` | Assign role to user (routes/web.php:293-295) |
| GET/POST | `/app/configuration/medications` | `MedicationController@index/@store` | `permission:configuration.manage` | Medication catalogue (routes/web.php:298-299) |
| PUT/DELETE | `/app/configuration/medications/{medication}` | `MedicationController@update/@destroy` | `permission:configuration.manage` | Edit/delete medication (routes/web.php:300-301) |
| GET/PUT | `/app/configuration/accounting` | `AccountingController@edit/@update` | `permission:configuration.manage` | Accounting settings (routes/web.php:302-303) |
| GET/POST | `/app/configuration/ref/{referential}` | `ReferentialController@index/@store` | `permission:configuration.manage` | Generic referential lists (acts, bilan types…) (routes/web.php:304-305) |
| PUT/DELETE | `/app/configuration/ref/{referential}/{id}` | `ReferentialController@update/@destroy` | `permission:configuration.manage` | Edit/delete referential row (routes/web.php:306-307) |
| GET/POST | `/app/configuration/identity` | `ClinicIdentityController@edit/@update` | `permission:configuration.branding.manage` | Clinic identity/branding (routes/web.php:311-312) |
| DELETE | `/app/configuration/identity/logo` | `ClinicIdentityController@destroyLogo` | `permission:configuration.branding.manage` | Remove logo (routes/web.php:313) |
| GET | `/app/configuration/identity/specialty/confirm` | `@confirmSpecialtyCorrection` | branding perm | Specialty-change confirm screen (routes/web.php:314-315) |
| PATCH | `/app/configuration/identity/specialty` | `@correctSpecialty` | branding perm + `password.confirm` | Correct clinic specialty (routes/web.php:316-318) |
| GET | `/app/configuration/connectivity-backup` | `ConnectivityAndBackupController@edit` | `permission:` OR-list (routes/web.php:322) | Connectivity/backup settings page (routes/web.php:321-323) |
| GET | `.../connectivity-backup/confirm-sensitive-actions` | `@confirmSensitiveActions` | `permission:` OR-list | Sensitive-action confirm (routes/web.php:324-326) |
| PUT | `/app/configuration/connectivity-backup` | `@update` | `permission:configuration.connectivity.manage\|configuration.backups.manage` | Save settings (routes/web.php:327-329) |
| GET | `/app/configuration/backup/local` | `BackupController@local` | `permission:configuration.backups.manage` + `password.confirm` | Download local backup (routes/web.php:332-334) |
| POST | `/app/configuration/backup/local/encrypted` | `@encryptedLocal` | same | Encrypted local backup (routes/web.php:335-337) |
| POST | `/app/configuration/backup/restore` | `@restore` | `permission:configuration.restore.manage` + `password.confirm` | Restore from backup (routes/web.php:341-343) |
| POST | `/app/configuration/backup/restore/prepare` | `PrepareOfflineRestoreController` | same + `throttle:offline-restore-prepare` | Prepare offline restore (routes/web.php:344-346) |
| POST | `/app/configuration/backup/google/prepare` | `BackupController@prepareGoogleOAuth` | `permission:configuration.drive.manage` + `password.confirm` | Start Google Drive OAuth (routes/web.php:350-352) |
| GET | `/app/configuration/backup/google/files` | `@driveFiles` | drive perm | List Drive backups (routes/web.php:353) |
| POST | `.../google/files/{fileId}/download` | `@downloadDriveFile` | drive perm + `password.confirm` | Download Drive backup (routes/web.php:354-357) |
| DELETE | `.../google/files/{fileId}` | `@deleteDriveFile` | drive perm + `password.confirm` | Delete Drive backup (routes/web.php:358-361) |
| DELETE | `/app/configuration/backup/google` | `@disconnectDrive` | drive perm + `password.confirm` | Disconnect Drive (routes/web.php:362-364) |
| POST | `/app/configuration/backup/google/test` | `@testDriveConnection` | drive perm | Test Drive connection (routes/web.php:365) |
| POST | `/app/configuration/backup/drive` | `@storeDrive` | drive perm + `password.confirm` | Upload backup to Drive (routes/web.php:366-368) |
| DELETE | `.../backup/drive/{backupRecordId}/upload` | `@cancelDriveUpload` | drive perm, `whereUuid` | Cancel Drive upload (routes/web.php:369-371) |
| POST | `/app/configuration/license/activate` | `LicenseController@store` | `permission:configuration.licensing.manage` + `password.confirm` + `throttle:license-activation` | Activate licence (routes/web.php:375-377) |
| POST | `/app/configuration/license/refresh` | `@refresh` | same | Refresh licence (routes/web.php:378-380) |
| DELETE | `/app/configuration/license` | `@destroy` | same | Remove licence (routes/web.php:381-383) |
| POST | `/app/configuration/updates/prepare-install` | `PrepareUpdateInstallController` | `permission:configuration.connectivity.manage` + `password.confirm` + `throttle:update-install-prepare` | Stage app update (routes/web.php:387-389) |
| POST | `.../connectivity-backup/upload-sessions` | `UploadSessionController@store` | connectivity perm | Create QR upload session (routes/web.php:390-391) |
| POST | `.../upload-sessions/{uploadSession}/test` | `@test` | connectivity perm | Test upload session (routes/web.php:392-393) |
| DELETE | `.../upload-sessions/{uploadSession}` | `@destroy` | connectivity perm | End upload session (routes/web.php:394-395) |
| GET | `.../uploaded-documents/{uploadedDocument}/preview` | `@preview` | connectivity perm | Preview received upload (routes/web.php:396-397) |
| POST | `.../uploaded-documents/{uploadedDocument}/accept` | `@accept` | connectivity perm | Accept upload into record (routes/web.php:398-399) |
| POST | `.../uploaded-documents/{uploadedDocument}/reject` | `@reject` | connectivity perm | Reject upload (routes/web.php:400-401) |

#### Special unauthenticated endpoints (outside `auth` group)

| Method | Path | Handler | Middleware | Auth | Serves |
|---|---|---|---|---|---|
| GET | `/app/configuration/backup/google/callback` | `BackupController@googleCallback` | `EnsureGoogleOAuthLoopback` only | no session auth; loopback-restricted | Google OAuth redirect target (routes/web.php:420-422) |
| GET | `/upload/{selector}` | `PublicUploadController@show` | `throttle:public-uploads`, `SecurePublicUploadHeaders`; selector pattern `[A-Za-z0-9_-]{22}` (routes/web.php:424) | no — selector lookup, 404 if unknown (app/Http/Controllers/PublicUploadController.php:29-30) | QR phone-upload landing page (routes/web.php:430) |
| POST | `/upload/{selector}/authorize` | `PublicUploadController@session` | same | selector + verifier token + audience check (PublicUploadController.php:80-84) | Authorize upload session (routes/web.php:431) |
| POST | `/upload/{selector}/files` | `PublicUploadController@store` | same | same token model | Receive files from phone (routes/web.php:432) |
| POST | `/upload/{selector}/complete` | `PublicUploadController@complete` | same | same token model | Finish upload session (routes/web.php:433) |
| GET | `/app/clinical-documents/{document}/file` | `ClinicalDocumentController@file` | none (web group only) | **signed URL only** (`hasValidRelativeSignature`, app/Http/Controllers/Consultations/ClinicalDocumentController.php:74) | Streams clinical Word doc to OnlyOffice/editor (routes/web.php:436) |
| POST | `/app/clinical-documents/{document}/callback` | `ClinicalDocumentController@callback` | none; **CSRF-exempt** (bootstrap/app.php:55) | signed URL + OnlyOffice JWT — but JWT check passes when `onlyoffice.jwt_secret` is `''` (app/ClinicalDocuments/ClinicalDocumentOnlyOffice.php:89, 193-195) | OnlyOffice save callback, writes document content (routes/web.php:437) |

#### Settings (routes/settings.php)

| Method | Path | Handler | Middleware | Auth | Serves |
|---|---|---|---|---|---|
| GET | `/settings` | redirect | `auth` | yes (web) | → `/settings/profile` (settings.php:11) |
| GET | `/settings/profile` | `ProfileController@edit` | `auth` | yes | Profile form (settings.php:13) |
| PATCH | `/settings/profile` | `ProfileController@update` | `auth` | yes | Update profile (settings.php:14) |
| DELETE | `/settings/profile` | `ProfileController@destroy` | `auth`, `verified` | yes | Delete account (settings.php:18) |
| GET | `/settings/security` | `SecurityController@edit` | `auth`, `verified`, `RequirePassword` | yes | Security page (password/2FA/passkeys/PIN) (settings.php:20-22) |
| PUT | `/settings/password` | `SecurityController@update` | `auth`, `verified`, `throttle:6,1` | yes | Change password (settings.php:24-26) |
| POST | `/settings/local-pin` | `LocalPinController@store` | `auth`, `verified`, `password.confirm`, `throttle:6,1` | yes | Set local PIN (settings.php:28-30) |
| DELETE | `/settings/local-pin` | `LocalPinController@destroy` | same | yes | Remove local PIN (settings.php:32-34) |
| PUT | `/settings/idle-lock` | `IdleLockController@update` | same | yes | Idle-lock timeout (settings.php:36-38) |
| GET | `/settings/appearance` | Inertia `settings/Appearance` | `auth`, `verified` | yes | Theme settings page (settings.php:40) |

#### Admin / Filament panel (`/admin`, app/Providers/Filament/AdminPanelProvider.php)

| Method | Path | Handler | Middleware | Auth | Serves |
|---|---|---|---|---|---|
| GET/POST | `/admin/login` | Filament login (`->login()`, AdminPanelProvider.php:38) | Filament stack (EncryptCookies…PreventRequestForgery + `EnforceSessionLock`, AdminPanelProvider.php:85-96) | no (guest) | Platform back-office login |
| GET | `/admin` | `PlatformDashboard` + widgets | Filament stack + `Authenticate` (AdminPanelProvider.php:97-99) | yes — web guard AND `is_platform_admin === true` (app/Models/User.php:160-164) | Platform dashboard (cabinets, licences, growth) |
| various | `/admin/{resource}/…` | auto-discovered resources in `app/Filament/Resources` (AdminPanelProvider.php:62-63) | same | platform admin only | CRUD for cabinets, licences, landing content, etc. (many resources recently deleted per git status; remaining discovered resources define the exact paths) |

#### Observations relevant to the mobile client

The entire web surface is a **staff-facing, session-cookie, Inertia desktop/clinic app** plus a platform back office. There is no patient-facing route of any kind in the web layer: no phone-number signup (Fortify username is email, config/fortify.php:48; `/register` creates a cabinet owner), no token login, no public doctor/clinic search or clinic detail pages, no family-member, patient-booking, patient-cancel, notification, or patient-prescription routes. The only "mobile" touchpoints are clinician-triggered sync actions (`/app/appointments/sync-with-mobile` and the mobile-sync republish routes, routes/web.php:197-202) that exchange appointment events with the hosted instance — they do not serve mobile clients. Doctor working hours and time-off editors exist (routes/web.php:243-252) but their data is never exposed publicly. Appointment status transition routes are confirm / check-in / cancel only (routes/web.php:188-193); no decline, reschedule, or no_show transition routes exist in the web layer (in_progress/completed are set by the consultation start/finish workflow, app/Http/Controllers/Consultations/ConsultationController.php:101,447).

### 2b. API routes

#### Route inventory

The entire token API lives in `routes/api.php` (read in full, 59 lines) under prefix `/api/v1` (`apiPrefix: 'api'` at bootstrap/app.php:31, `Route::prefix('v1')` at routes/api.php:23). It exists to serve a **desktop/companion client for cabinet staff**, authenticated by Sanctum personal access tokens (routes/api.php:11-21). There is no patient-facing API surface at all.

The `api` middleware group is prepended with `EnforceHubCabinetBinding` (bootstrap/app.php:65-67), which is a no-op unless the install is a self-hosted "Hub" (app/Http/Middleware/EnforceHubCabinetBinding.php:39-41). `cabinet.active.api` aliases `EnsureApiCabinetIsActive` (bootstrap/app.php:62), which 403s members of pending/suspended cabinets or unapproved accounts (app/Http/Middleware/EnsureApiCabinetIsActive.php:26-43).

| Method | Path | Controller@method | Middleware | Auth actually required | Returns |
|---|---|---|---|---|---|
| POST | /api/v1/auth/token | Api\V1\AuthController@token | throttle:login (5/min by email+IP, app/Providers/FortifyServiceProvider.php:113-117) | **None** (public credential exchange; email+password+device_name) | JSON `{token, user}` — plain-text Sanctum token + `UserResource` incl. roles, permissions, cabinet+license (AuthController.php:50-55) |
| POST | /api/v1/cabinets/register | Api\V1\CabinetController@register | throttle:registration (5/10min by email+IP, app/Providers/AppServiceProvider.php:112-119) | **None** | JSON `{cabinet_id, status:'pending'}` (CabinetController.php:23-26) |
| POST | /api/v1/cabinets/join | Api\V1\CabinetController@join | throttle:cabinet-join (8/10min, AppServiceProvider.php:121-128) | **None** | JSON `{message, cabinet_id, status:'awaiting_approval'}` (CabinetController.php:37-42) |
| POST | /api/v1/auth/logout | Api\V1\AuthController@logout | auth:sanctum | Sanctum token | JSON `{message}`; revokes current token (AuthController.php:61-76) |
| GET | /api/v1/me | Api\V1\AuthController@me | auth:sanctum | Sanctum token | `UserResource` (id, name, email, is_platform_admin, roles, permissions, cabinet+license) (AuthController.php:81-87; app/Http/Resources/UserResource.php:19-28) |
| GET | /api/v1/appointments | Api\V1\AppointmentController@index | auth:sanctum, cabinet.active.api | Token + policy `viewAny Appointment` | Paginated `AppointmentResource::collection` with nested `PatientResource` (AppointmentController.php:39-49) |
| POST | /api/v1/appointments | Api\V1\AppointmentController@store | auth:sanctum, cabinet.active.api | Token + policy `create Appointment` | `AppointmentResource` 201; Idempotency-Key replay support; slot availability enforced (AppointmentController.php:63-128) |
| GET | /api/v1/appointments/{appointment} | Api\V1\AppointmentController@show | auth:sanctum, cabinet.active.api | Token + policy `view` (route model binding tenant-scoped by `BelongsToCabinet` global scope, app/Models/Appointment.php:59; app/Models/Concerns/BelongsToCabinet.php:21-36) | `AppointmentResource` |
| PATCH | /api/v1/appointments/{appointment} | Api\V1\AppointmentController@update | auth:sanctum, cabinet.active.api | Token + policy `update` | `AppointmentResource`, or 409 JSON on `sync_version` conflict (If-Match / expected_version) (AppointmentController.php:134-161, 290-298). Status transitions limited to confirmed / checked_in / cancelled (AppointmentController.php:188-219) |
| DELETE | /api/v1/appointments/{appointment} | Api\V1\AppointmentController@destroy | auth:sanctum, cabinet.active.api | Token + policy **`cancel`** (but performs `delete()`, a soft delete — see risk §5.7) | JSON `{message}` (AppointmentController.php:166-179) |
| GET | /api/v1/sync/appointments | Api\V1\AppointmentSyncController@index | auth:sanctum, cabinet.active.api | Token + policy `viewAny Appointment` | JSON cursor stream of `AppointmentSyncEvent` rows: **raw `payload` array echoed verbatim** plus sha256, version, action (AppointmentSyncController.php:42-58) |
| POST | /api/v1/sync/appointments/ack | Api\V1\AppointmentSyncController@acknowledge | auth:sanctum, cabinet.active.api | Token + policy `viewAny` (tenant isolation relies on `BelongsToCabinet` on `AppointmentSyncEvent`, app/Models/AppointmentSyncEvent.php:43) | JSON `{acknowledged_cursor, acknowledged_count}` |
| POST | /api/v1/sync/appointments/push | Api\V1\AppointmentSyncController@push | auth:sanctum, cabinet.active.api | Token + policy `create Appointment` | JSON `{applied, results[]}` per-event outcomes; exception messages deliberately not echoed (AppointmentSyncController.php:149-159) |
| GET | /api/v1/schedule | Api\V1\ScheduleController@index | auth:sanctum, cabinet.active.api | Token + policy `viewAny Appointment` | Hand-built JSON: doctor, `schedules[]` (**one entry per `doctor_schedules` row** — multiple rows per weekday would each appear; no `is_active` filter, ScheduleController.php:34-42), `time_off[]`, `open_months[]` (ScheduleController.php:34-72) — read-only, own cabinet only |
| GET | /api/v1/patients | Api\V1\PatientController@index | auth:sanctum, cabinet.active.api | Token + policy `viewAny Patient` | Paginated `PatientResource::collection` — full PII: phone, secondary_phone, email, address, DOB, blood group (app/Http/Resources/PatientResource.php:19-35) |
| GET | /api/v1/patients/{patient} | Api\V1\PatientController@show | auth:sanctum, cabinet.active.api | Token + policy `view` (binding tenant-scoped) | `PatientResource` |

#### Related routes outside routes/api.php

| Method | Path | Controller@method | Middleware | Auth actually required | Returns |
|---|---|---|---|---|---|
| GET | /health | HealthController@__invoke | Global stack only (registered in bootstrap/app.php:35; exempt from Hub binding, EnforceHubCabinetBinding.php:45-47) | **None** for summary; full details require direct-local request + `X-MediSmart-Health-Key` matching `medismart.health.details_key` (HealthController.php:19-27) | JSON: status, **hub identity, app name+version always public** (HealthController.php:25-36); 503 when unhealthy |
| GET | /up | Laravel built-in | — | **None** | Framework health page (bootstrap/app.php:33) |
| POST | /app/appointments/sync-with-mobile | Sync\MobileSyncController@__invoke | web group, `auth`+`verified` (routes/web.php:157,162,197) | **Session** + policy `create Appointment` | RedirectResponse with flash message — desktop-only button, not an API (MobileSyncController.php:21-54) |
| GET | /upload/{selector} | PublicUploadController@show | web, throttle:public-uploads, SecurePublicUploadHeaders (routes/web.php:426-430) | **None** — capability URL (22-char selector pattern, routes/web.php:424) | Blade view with strict CSP nonce (PublicUploadController.php:37-71) |
| POST | /upload/{selector}/authorize | PublicUploadController@session | same | **None** — selector + 43-char `verifier` secret (PKCE-style, PublicUploadController.php:179-184) | JSON session payload: limits, mime allowlist, uploaded file names/sizes (PublicUploadController.php:186-212) |
| POST | /upload/{selector}/files | PublicUploadController@store | same (12/min, AppServiceProvider.php:95-101) | **None** — selector+verifier | JSON 201 `{message, session}`; file rules are only `required|file` at HTTP layer, limits enforced fail-closed in `UploadDocumentService` (PublicUploadController.php:99-106; see Appendix) |
| POST | /upload/{selector}/complete | PublicUploadController@complete | same | **None** — selector+verifier | JSON `{message, status}` or 422 |

#### Raw-model exposure check

No endpoint returns a bare Eloquent model via `response()->json($model)` — all appointment/patient/user payloads pass through API Resource classes or hand-built arrays. **One exception in spirit:** `GET /api/v1/sync/appointments` echoes each event's stored `payload` array verbatim (`'payload' => $event->payload`, AppointmentSyncController.php:49), a full appointment attribute snapshot written by `AppointmentSyncService` — its field set is not curated at the response boundary, so whatever the publisher stores (clinical `reason`, `reception_notes`, patient identity block) crosses the wire to any token holding `appointments.viewAny` (see risk §5.4).

#### Exact response shapes (all four API resources)

No `JsonResource::withoutWrapping()` call exists anywhere in `app/Providers` and none of the four resources sets `$wrap`, so Laravel defaults apply: a single resource is wrapped as `{"data": {...}}`; a paginated `::collection(...)` returns `{"data": [...], "links": {...}, "meta": {...}}`.

**AppointmentResource** (app/Http/Resources/AppointmentResource.php:20-42) — keys in order, all unconditional except `patient`: `id` (:21), `public_id` (:22), `sync_version` (int, :23), `patient_id` (:24), `patient` (`whenLoaded('patient')` → nested PatientResource, :25), `appointment_date` (`YYYY-MM-DD`, :26), `starts_at`/`ends_at` (ISO-8601, :27-28), `status` (enum string, :29), **`reason`** (:30 — clinical visit reason), `prestation` (:31), **`reception_notes`** (:32 — staff-internal notes), **`cancellation_reason`** (:33), derived flags `can_confirm`/`can_check_in`/`can_cancel` (:34-36), `confirmed_at`/`checked_in_at` (:37-38), `created_at`/`updated_at` (:39-40), `deleted_at` (:41, non-null on soft-deleted replay rows).

**PatientResource** (app/Http/Resources/PatientResource.php:19-35) — 15 unconditional keys: `id`, `patient_number`, `first_name`, `last_name`, `full_name`, `date_of_birth`, `gender`, `blood_group`, `phone`, `secondary_phone`, `email`, `address`, `city`, `created_at`, `updated_at`. The full demographic record crosses the wire with no field-level conditioning; medical free-text fields are deliberately omitted.

**UserResource** (app/Http/Resources/UserResource.php:19-28): `id`, `name`, `email`, `is_platform_admin` (:23), `approved` (:24), `cabinet` (`whenLoaded`, nested CabinetResource or null, :25), **`roles`** (flattened Spatie role names, :26), **`permissions`** (complete flattened permission-name array, :27).

**CabinetResource** (app/Http/Resources/CabinetResource.php:19-35): `id`, `name`, `status`, `specialization`, `wilaya` `{code, name}` (:24-27, always present), `license` (`whenLoaded`, :28-34 — `{plan, plan_label, status, status_label, expires_at}` with computed effective status).

**Resource → route map** (all routes: routes/api.php:23-59):

| Route | Return shape | Evidence |
|---|---|---|
| `POST /api/v1/auth/token` | `{"token": "<plainTextToken>", "user": {UserResource, unwrapped via ->resolve()}}`; `user.cabinet` + `cabinet.license` always present (`$user->load('cabinet.license')`); 403 denial shape `{message, reason, status}` | AuthController.php:43-47, 50-55 |
| `POST /api/v1/auth/logout` | `{"message": "Déconnexion réussie."}` | AuthController.php:75 |
| `GET /api/v1/me` | `{"data": {UserResource}}`; cabinet+license always loaded | AuthController.php:81-87 |
| `POST /api/v1/cabinets/register` | `{"cabinet_id", "status": "pending"}` 201 — no resource class | CabinetController.php:23-26 |
| `POST /api/v1/cabinets/join` | `{"message", "cabinet_id", "status": "awaiting_approval"}` 201 — no resource class | CabinetController.php:38-42 |
| `GET /api/v1/appointments` | Paginated envelope of AppointmentResource, per_page 1-100 default 15; **`->with('patient')` (AppointmentController.php:40) embeds the full nested PatientResource in every list item** | AppointmentController.php:39-49 |
| `GET /api/v1/appointments/{appointment}` | `{"data": {AppointmentResource}}` with `->load('patient')` | AppointmentController.php:52-57 |
| `POST /api/v1/appointments` | 201 `{"data": {AppointmentResource}}`; idempotent replay 200 + `Idempotency-Replayed: true` (:89-91); key-reuse conflict 409 `{message, reason: "idempotency_key_reused"}` (:83-87) | AppointmentController.php:63-128 |
| `PATCH /api/v1/appointments/{appointment}` | `{"data": {AppointmentResource}}`; version conflict 409 `{message, reason: "sync_version_conflict", public_id, current_version}` | AppointmentController.php:134-161, 290-298 |
| `DELETE /api/v1/appointments/{appointment}` | `{"message": "Rendez-vous supprimé."}`; same 409 conflict shape | AppointmentController.php:166-179 |
| `GET /api/v1/patients` | Paginated envelope of PatientResource (per_page 1-100 default 15) | PatientController.php:16-35 |
| `GET /api/v1/patients/{patient}` | `{"data": {PatientResource}}` | PatientController.php:37-42 |
| `GET /api/v1/sync/appointments` | Hand-built: `{"data": [{cursor, event_id, appointment_public_id, version, action, payload, payload_sha256, created_at}...], "meta": {requested_cursor, next_cursor, has_more}}` — `payload` is the raw stored event payload | AppointmentSyncController.php:43-59 |
| `POST /api/v1/sync/appointments/ack` | `{"acknowledged_cursor", "acknowledged_count"}` | AppointmentSyncController.php:86-89 |
| `POST /api/v1/sync/appointments/push` | `{"applied": <int>, "results": [{appointment_public_id, outcome, reason}...]}` (exception messages replaced by `"import_failed"`, :150-159) | AppointmentSyncController.php:163-166 |
| `GET /api/v1/schedule` | Hand-built: `{"doctor": {id, doctor_name, specialty, consultation_duration} \| null, "schedules": [{id, day_of_week, starts_at, ends_at, slot_duration, is_active}], "time_off": [{id, starts_at, ends_at, is_all_day, reason}], "open_months": [{id, year, month, is_open, note}]}`; all-empty when no doctor (:26-31) | ScheduleController.php:19-73 |

None of the four resources has any per-role field gating; reuse for a patient-facing role would expose everything above as-is (risk §5.3).

#### The mobile sync wire contract (verified — both sides are this codebase)

The desktop's `MobileSyncClient` calls exactly the three sync routes this repo itself serves (routes/api.php:47-51), against a per-installation endpoint + Sanctum token stored in `application_settings` (`app/Services/Sync/MobileSyncSettings.php:18-42`, token minted by `POST /api/v1/auth/token` on the remote, encrypted at rest under the local `APP_KEY`, :10-15, 67-72; HTTP client `baseUrl()->withToken()`, 5s/20s timeouts, `MobileSyncClient.php:23-25, 109-115`):

- **Pull** `GET {endpoint}/api/v1/sync/appointments?cursor&limit` (MobileSyncClient.php:42-45) — cursor = event row id, limit 1-100 (AppointmentSyncController.php:25-31).
- **Ack** `POST .../sync/appointments/ack` `{cursor}` (MobileSyncClient.php:66-69).
- **Push** `POST .../sync/appointments/push` `{events: [...]}` (MobileSyncClient.php:85-88), each envelope `{appointment_public_id: uuid, version: int≥1, action: upsert|delete, payload: object, payload_sha256: 64-char|null}`, max 100/batch (AppointmentSyncController.php:113-120; envelope built at app/Services/Sync/MobileAppointmentSynchroniser.php:210-216).

The `payload` shape is defined once in `app/Services/Appointments/AppointmentSyncService.php:104-133`: `public_id`, `legacy_id`, `patient_id` (installation-local, explicitly non-portable, :108-113), an inlined portable patient identity `{public_id, patient_number, first_name, last_name, date_of_birth, gender, phone, email}` (:154-163), `appointment_date`, ISO starts/ends, `status`, `reason`, `prestation`, `reception_notes`, `cancellation_reason`, lifecycle timestamps, `version`, `created_at`, `updated_at`. Conflict rule: last-writer by `sync_version`, local wins on equal-version divergence (`version_conflict`), enforced identically in both directions by the shared importer (app/Services/Sync/AppointmentImporter.php:89-107); echo suppression via `STATUS_IMPORTED` events (AppointmentImporter.php:17-26, 163-172; MobileAppointmentSynchroniser.php:160-166). "Both sides run this codebase" (AppointmentImporter.php:385-386). No production URL exists anywhere in the repo (tests fake `https://sync.drclick.test`, tests/Feature/Sync/MobileAppointmentSyncTest.php:33, 59); the Hub is *not* part of this path (config/hub.php:8-16; app/Services/Hub/HubMode.php:8-15).

#### Fit with the mobile client

This API is a **staff/desktop tenant API, not a patient API**. Sanctum tokens exist and login returns roles/permissions (close to "token + role"), and appointment CRUD with idempotency and optimistic versioning is solid groundwork. But token issuance is email+password only and refuses anyone who is not an approved member of an active cabinet (AuthController.php:41-48) — except cabinet-less accounts, which are waved through with no gate at all (CabinetAccessService.php:45-48); tokens never expire (config/sanctum.php:53); every data route is scoped to the caller's own cabinet by the `BelongsToCabinet` global scope. Because the "online service" is a hosted deployment of this same codebase, there is no separate booking backend to integrate with: the mobile client's server surface **is** this API v1, and everything patient-facing must be added to it (§4). The one provisioning gap on the existing path: nothing in the product writes `sync.mobile.endpoint`/`token` on a desktop (`MobileSyncSettings::configure()` is test-only), so desktop-to-cloud pairing must be built before any mobile-originated booking can reach a clinic.

## 3. Models

### 3a. Identity & scheduling models

All models use Laravel attribute syntax (`#[Fillable]`, `#[Hidden]`) instead of `$fillable`/`$hidden` properties, and the `casts()` method. None define `$guarded`. Tenant models share the `BelongsToCabinet` concern (described in §1.5; key hazard: no filter at all for cabinet-less non-admin users, BelongsToCabinet.php:28-35).

#### User — table `users` (app/Models/User.php)

| Column | Type | Source |
|---|---|---|
| id | bigint PK | database/migrations/0001_01_01_000000_create_users_table.php:15 |
| name | string | 0001_01_01_000000:16 |
| email | string, unique | 0001_01_01_000000:17 |
| email_verified_at | timestamp nullable | 0001_01_01_000000:18 |
| password | string | 0001_01_01_000000:19 |
| local_pin_hash | string nullable | 2026_08_05_010000_add_local_pin_hash_to_users_table.php:12 |
| two_factor_secret / two_factor_recovery_codes | text nullable | 2025_08_14_170933_add_two_factor_columns_to_users_table.php:15-16 |
| two_factor_confirmed_at | timestamp nullable | 2025_08_14_170933:17 |
| cabinet_setting_id | FK cabinet_settings, nullable, nullOnDelete | 2026_08_03_010000_add_payment_service_and_cabinet_assignment.php:16-20 |
| cabinet_id | FK cabinets, nullable, indexed, nullOnDelete | 2026_08_06_000200_add_cabinet_columns_to_users_table.php:13-18 |
| is_platform_admin | boolean default false | 2026_08_06_000200:22 |
| approved_at | timestamp nullable (null = awaiting owner approval) | 2026_08_06_000200:28 |
| remember_token | string | 0001_01_01_000000:20 |
| created_at / updated_at | timestamps | 0001_01_01_000000:21 |

- Fillable: `name, email, password, cabinet_setting_id, cabinet_id, is_platform_admin, approved_at` (User.php:47). Hidden: `password, local_pin_hash, two_factor_secret, two_factor_recovery_codes, remember_token` (User.php:48). Casts: `email_verified_at:datetime, password:hashed, two_factor_confirmed_at:datetime, is_platform_admin:boolean, approved_at:datetime` (User.php:89-98).
- Traits: `HasApiTokens` (Sanctum; `personal_access_tokens` table at database/migrations/2019_12_14_000001), `HasRoles` (Spatie), `Notifiable`, `PasskeyAuthenticatable`, `TwoFactorAuthenticatable` (User.php:52). **No phone column; no `MustVerifyEmail` implementation** (import commented out at User.php:5; class implements only FilamentUser, PasskeyUser at :49), so Laravel email verification is inert despite the enabled Fortify feature.
- Relationships: `doctorProfile()` HasOne DoctorProfile (User.php:123-126); `desktopPinCredentials()` HasMany (User.php:131-134); `cabinet()` BelongsTo (User.php:141-144); `cabinetSettings()` BelongsTo CabinetSetting — "legacy link retained for compatibility" (User.php:151-154).
- Permission override: when `cabinet_id !== null`, `hasPermissionTo()`/`getAllPermissions()` are answered by `CabinetRolePermissionService` instead of Spatie's global roles (User.php:61-82). Password change revokes all desktop PIN credentials (User.php:100-118). Approval helpers `isApproved()`/`isPendingApproval()` (User.php:180-188).

#### Cabinet — table `cabinets` (app/Models/Cabinet.php)

Columns (database/migrations/2026_08_06_000000_create_cabinets_table.php:16-28): `id`; `name` string; `status` string(20) default `'pending'` indexed (cast to `CabinetStatus`); `specialization` string nullable; `wilaya_code` unsignedTinyInteger nullable (**not indexed**); `owner_user_id` FK users nullable nullOnDelete; `activated_at` timestamp nullable; `license_id` FK licenses nullable nullOnDelete; timestamps; index `(status, created_at)`.

- Fillable: `name, status, specialization, wilaya_code, owner_user_id, activated_at, license_id` (Cabinet.php:28-36). Casts: `status => CabinetStatus, wilaya_code => integer, activated_at => immutable_datetime` (Cabinet.php:45-52). No hidden.
- Relationships: `owner()` (Cabinet.php:77-80); `users()` HasMany (Cabinet.php:85-88); `hostedLicenseGrants()` (Cabinet.php:93-96); `desktopPinCredentials()` (Cabinet.php:101-104); `license()` (Cabinet.php:109-112); `settings()` HasOne CabinetSetting (Cabinet.php:117-120).
- `MAX_SEATS = 3` — hard cap on members; `seatsInUse()` counts **all** attached users, approved or pending (Cabinet.php:43, 163-171). Computed `wilaya_name` via `App\Support\Wilayas` (Cabinet.php:176-179).

#### CabinetSetting — table `cabinet_settings` (app/Models/CabinetSetting.php)

Columns (database/migrations/2026_07_29_000000_create_cabinet_settings_table.php:15-31, plus alters): `id`; `cabinet_id` FK nullable **unique** cascadeOnDelete (2026_08_06_000300:16-22); `name`; `address` nullable; `city` string(120) nullable (2026_08_03_020000:12); **`phone` string(30) nullable — a single phone**; `email` nullable; `logo_path` nullable; `currency_code` default 'DZD'; `timezone` default 'UTC'; `default_appointment_duration` smallint default 30; `default_consultation_fee_minor` bigint default 0; `receipt_footer`/`prescription_footer` text nullable; `low_stock_threshold`; `expiry_warning_days`; timestamps.

- Fillable: all business columns plus `cabinet_id` (CabinetSetting.php:10-26). Casts: four integer columns (CabinetSetting.php:108-116). No hidden.
- `CabinetSetting::current()` resolves (and lazily `firstOrCreate`s) the row for the signed-in user's cabinet; **outside a request context it falls back to the earliest row in the table** (CabinetSetting.php:55-71) — a cross-tenant hazard for any future unauthenticated/public code path (risk, §5).

#### CabinetRolePermissionSet — table `cabinet_role_permission_sets` (app/Models/CabinetRolePermissionSet.php)

Columns (2026_08_09_130000:11-19): `id`; `cabinet_id` FK cascadeOnDelete; `role_name` string; `permissions` json; timestamps; unique `(cabinet_id, role_name)`. Fillable: `cabinet_id, role_name, permissions` (:18). Casts `permissions => array` (:23-28). Uses `BelongsToCabinet`. Semantics: per-cabinet allow-list overriding the seeded Spatie role; empty array = fully revoked; no row = global default (:9-17).

#### Practitioner — table `practitioners` (app/Models/Practitioner.php)

Columns (2026_07_31_020000_create_configuration_tables.php:32-43 + cabinet_id from 2026_08_06_000100:38): `id`; `cabinet_id` FK nullable; `name` string(150); `specialty` string(150) nullable; `phone`/`email`/`address`/`order_number` nullable; `is_active` boolean; timestamps. Fillable at Practitioner.php:9; `is_active` cast (:17-20). Uses `BelongsToCabinet`; **no `user_id`, no relation to DoctorProfile/User** — a configuration/directory table (e.g. referring practitioners), not the bookable doctor.

#### DoctorProfile — table `doctor_profiles` (app/Models/DoctorProfile.php)

Columns (2026_07_28_230100:15-27, desktop-identity alter 2026_08_04_030000:13-27, cabinet_id from 2026_08_06_000100:20): `id`; `cabinet_id` FK nullable; `user_id` FK users **unique** cascadeOnDelete; `specialty` string(150) indexed; `specialty_code` string(100) nullable indexed; `professional_identifier` / `medical_order_number` nullable unique; `doctor_name`, `clinic_name`, `phone` string(40), `email`, `city` string(120), `full_address` string(500), `footer_extra_line`, `logo_path` — all nullable; `specialty_locked_at` nullable; `consultation_duration` smallint nullable; `consultation_fee_minor` bigint nullable; `is_active` boolean indexed; timestamps.

- Fillable: all 18 business columns incl. `user_id` (DoctorProfile.php:17-35). Casts at :44-52. **No hidden** — `phone`, `email`, `medical_order_number`, `professional_identifier` serialize by default.
- Relationships: `user()` (:127-131); `schedules()` HasMany DoctorSchedule via `doctor_id` (:133-137); `timeOff()` HasMany DoctorTimeOff (:139-143); `openMonths()` HasMany DoctorOpenMonth (:145-149); `cabinet()` via trait.
- Specialty locking: `specialty_code` auto-slugged and `specialty_locked_at` stamped on first set; once locked, any change throws, except the audited `correctLockedSpecialty()` admin path (:54-75, 96-125). `DoctorProfile::current()` returns the single active profile in the cabinet scope (:85-88) — **`query()->active()->first()`, so for an unscoped caller it returns an arbitrary tenant's doctor**.

#### DoctorSchedule — table `doctor_schedules` (app/Models/DoctorSchedule.php)

Columns (2026_07_28_230100:30-42 + cabinet_id): `id`; `cabinet_id`; `doctor_id` FK doctor_profiles cascadeOnDelete; `day_of_week` unsignedTinyInteger (cast to `Weekday` int enum, 1=Monday…7=Sunday — app/Enums/Weekday.php:5-13); `starts_at` time; `ends_at` time; `slot_duration` smallint nullable; `is_active` boolean; timestamps. Index `(doctor_id, day_of_week, is_active)`; unique `(doctor_id, day_of_week, starts_at, ends_at)` (2026_07_28_230100:41). Fillable at DoctorSchedule.php:18-25; casts :34-41; `doctor()` :51-54.

#### DoctorTimeOff — table `doctor_time_off` (app/Models/DoctorTimeOff.php, explicit `$table` at :34)

Columns (2026_07_28_230100:45-57 + cabinet_id): `id`; `cabinet_id`; `doctor_id` FK cascadeOnDelete; `starts_at`/`ends_at` datetime; `is_all_day` boolean; `reason` string(150) nullable; `notes` text nullable; timestamps; indexes `(doctor_id, starts_at)` and `(doctor_id, ends_at)`. Fillable :18-25; casts :39-46; `doctor()` :56-59.

#### DoctorOpenMonth — table `doctor_open_months` (app/Models/DoctorOpenMonth.php)

Columns (2026_07_31_000000:17-28 + cabinet_id): `id`; `cabinet_id`; `doctor_id` FK cascadeOnDelete; `year` smallint; `month` tinyint; `is_open` boolean default true; `note` nullable; timestamps; unique `(doctor_id, year, month)`. Fillable :17-23; casts :32-39; `doctor()` :47-52. Booking gate: "A month must be explicitly opened by the doctor before it accepts any bookings" (2026_07_31_000000:14-16).

#### Key answers for the mobile design

**(a) How a "doctor" is represented.** A doctor = a `User` holding the Spatie role `Doctor` **plus** a one-to-one `DoctorProfile` (`doctor_profiles.user_id` unique FK — 2026_07_28_230100:17; `User::doctorProfile()` — User.php:123-126). Roles were deliberately consolidated to exactly `Doctor` and `Assistant` (app/Enums/RoleName.php:7-8; migration 2026_08_10_000000_consolidate_roles_to_doctor_and_assistant.php, irreversible at :248), with old names kept only as constant aliases (RoleName.php:12-22). All scheduling tables FK to `doctor_profiles.id`, not `users`. `Practitioner` is **not** the doctor.

**(b) Specialties.** Free-text string columns, no lookup table, no DB enum: `doctor_profiles.specialty` + auto-slug `specialty_code`, locked after first set (DoctorProfile.php:54-75); `cabinets.specialization` (2026_08_06_000000:20); `practitioners.specialty`. The canonical catalogue exists only as the hard-coded PHP class `App\Support\MedicalSpecialtyCatalog` (~21 slug=>French-label pairs, app/Support/MedicalSpecialtyCatalog.php:10-32) — nothing in the schema enforces it (§3c).

**(c) Working hours — exact shape, and the morning/evening question (verified end-to-end).** One `doctor_schedules` row = one time range on one weekday. The unique key `(doctor_id, day_of_week, starts_at, ends_at)` **permits multiple rows per weekday**, so separate MORNING and EVENING ranges are representable as two rows — and the read side fully handles that: `AvailabilityService::activeSchedules()` groups by weekday and iterates every range when building slots (app/Services/Appointments/AvailabilityService.php:208-213, 150-186), and `GET /api/v1/schedule` returns one `schedules[]` entry **per row** (ScheduleController.php:34-42). **But the write side cannot produce or preserve it:** the schedule editor's payload is one `{day_of_week, is_working, starts_at, ends_at, slot_duration}` entry per weekday (app/Http/Requests/Appointments/UpdateDoctorScheduleRequest.php:23-30), persisted by delete-and-recreate (`SyncDoctorScheduleAction::handle()` runs `schedules()->delete()` then creates one row per working day, app/Actions/Appointments/SyncDoctorScheduleAction.php:17-32); the editor seed collapses multiple rows to one via `->get()->keyBy(day_of_week)` (app/Http/Controllers/Appointments/ScheduleController.php:79-96, resources/js/pages/appointments/Configure.vue:52-55, 86-92). So if two rows per weekday ever existed (only possible via a crafted PUT exploiting the missing `distinct` rule on `days.*.day_of_week`, or direct DB writes), the next save of the Configure screen silently destroys the second range. There is also **no period/label column** (nothing marks MORNING vs EVENING — client must infer from clock time) and **closed days are implicit** (a weekday with no row / only inactive rows; no `is_closed` flag). Slot duration precedence in availability: per-row `slot_duration` ?? `doctor_profiles.consultation_duration` ?? `config('clinic.appointments.default_duration', 30)` (AvailabilityService.php:263-270) — `cabinet_settings.default_appointment_duration` plays no role in this path.

**(d) Availability computation & gates** (`AvailabilityService`, called by `AvailabilityController`, routes/web.php:203-206): month must have an `is_open` `doctor_open_months` row (default closed, :189-196); weekday needs ≥1 active schedule row (:109-113); all-day time off kills the day (:242-250); partial time off blocks overlapping slots (:163, 257-261); blocking-status appointments (`scheduled/confirmed/checked_in/in_progress`, app/Enums/AppointmentStatus.php:15-35) mark slots `booked` (:164, 230-237); past slots unavailable (:162). Month JSON: `{year, month, is_open_month, days:[{date, day, weekday, is_open_month, is_working_day, is_day_off, is_past, available_count, bookable}]}` (:75-93). Day JSON: `{date, reason: null|'month_closed'|'not_working_day'|'day_off'|'no_doctor', slots:[{starts_at, ends_at, label, end_label, available, reason: null|'booked'|'time_off'|'past'}], appointments:[...]}` (AvailabilityController.php:62-91; service :123-127, 166-178). All of this is session-authenticated staff web UI — none is exposed publicly.

**(e) Reception/staff linkage; one doctor per cabinet?** Staff are `users` rows with `cabinet_id` set and an `approved_at` gate; a reception user is an `Assistant`-role user of the cabinet (per-cabinet permission override, User.php:61-82). Design intent is **one cabinet = one doctor**: "Single-doctor cabinet: exactly one active doctor profile is expected" (2026_07_28_230100:14); `DoctorProfile::current()` returns the first active profile (DoctorProfile.php:85-88). The schema does not forbid multiple `doctor_profiles` per cabinet (uniqueness only per `user_id`); application code assumes exactly one. Reception is therefore scoped to one cabinet — and transitively one doctor — **by convention only**, with no explicit reception→doctor link.

### 3b. Patient & clinical models

All models below use the `BelongsToCabinet` trait; `cabinet_id` was added as a **nullable** FK to all tenant tables in database/migrations/2026_08_06_000100_add_cabinet_id_to_tenant_tables.php:17-43 (nullable by design, so an unscoped create silently succeeds — 2026_08_06_000100:10-14, 53-56).

| Model | Table | Key columns (type) | Notable casts | Relationships |
|---|---|---|---|---|
| Patient | `patients` | `patient_number` str24 unique, `public_id` uuid unique (cross-installation identity), `first_name`/`last_name` str100, `date_of_birth` date null, `gender` str20, `marital_status`, `profession`, `smoking_status`, `referred_by`, `phone` str30 (nullable, indexed, **not unique**), `secondary_phone` str30, `email`, `address` str, `city` str120, `emergency_contact_name/phone`, `blood_group` str5, `allergies` text, `antecedents_medical/surgical/family/gyneco/other` text, `notes` text, `created_by` FK users, softDeletes | `date_of_birth`→date, `gender`→`App\Enums\Gender`, `blood_group`→`BloodGroup` (app/Models/Patient.php:80-87) | belongsTo createdBy(User); hasMany appointments, encounters, antecedents, observations (Patient.php:129-166) |
| Appointment | `appointments` | `public_id` uuid unique, `sync_version` bigint, `patient_id` FK, `appointment_date` date, `starts_at`/`ends_at` datetime, `status` str32 default `scheduled`, `reason` text, `prestation` str, `reception_notes` text, `created_by`/`cancelled_by` FK users, `cancellation_reason` text, `confirmed_at`/`checked_in_at`/`started_at`/`completed_at`/`cancelled_at` ts, `mobile_idempotency_key_hash`/`_fingerprint` char64 (unique per cabinet), softDeletes | `status`→`AppointmentStatus`; timestamps immutable_datetime (Appointment.php:149-163) | belongsTo patient (withTrashed), createdBy, cancelledBy; hasMany syncEvents; hasOne latestSyncEvent (Appointment.php:173-215). Route-bindable by uuid `public_id` **with numeric-id fallback** (221-228) |
| AppointmentSyncEvent | `appointment_sync_events` | `event_id` uuid unique, `cabinet_id` FK, `appointment_id` FK null, `appointment_public_id` uuid, `version` bigint, `action` str20, `payload` json, `payload_sha256` char64, `status` str20 (`pending/acknowledged/failed/imported` consts), `attempts`, `last_attempted_at`, `last_error`, `acknowledged_at/by` | `payload`→array (AppointmentSyncEvent.php:58-67) | belongsTo appointment (withTrashed), acknowledgedBy (69-79). Migration 2026_08_09_110000:26-52 |
| Consultation | `consultations` | `patient_id` FK, `appointment_id` FK null, `consulted_at`, `motif`/`examens`/`diagnostic`/`traitement`/`notes` text, `weight_kg`/`height_cm`/`temperature_c` decimal, `blood_pressure` str20, `payment_amount_minor`, `payment_adjustment_minor`, `payment_method` str50, `payment_service`, `payment_notes` text, `is_paid` bool, `payment_settled_at`, `status` str20 default `in_progress`, `completed_at`, `created_by` | money ints, immutable datetimes (Consultation.php:52-62) | belongsTo patient (withTrashed), appointment, createdBy; hasMany payments; ledger helpers `collectedMinor/outstandingMinor/paymentStatus` (Consultation.php:67-127). Migrations 2026_07_31_030000:12-29, 2026_07_31_040000:26-34, 2026_08_09_100000:13-17 |
| ConsultationFee | `consultation_fees` | `label` str150, `amount_minor` bigint null, `category` str100, `is_active` bool | int/bool (ConsultationFee.php:14-20) | none beyond cabinet. Migration 2026_07_31_020000:46-54 |
| Encounter | `encounters` | `patient_id` FK, `appointment_id` FK null, `provider_id` FK users, `status` str20 default `draft`, `occurred_at/started_at/signed_at`, `signed_by` FK, `revision_number`, `amends_encounter_id` self-FK, `amendment_reason` text, `content_hash` char64, `lock_version` | `status`→`EncounterStatus` (`draft/in_progress/signed/void`, app/Enums/EncounterStatus.php:5-10) | belongsTo patient, appointment, provider, signedBy, amendsEncounter; hasMany notes, diagnoses, observations, amendments (Encounter.php:49-118). Migration 2026_07_31_000001:11-31 |
| EncounterNote | `encounter_notes` | `encounter_id` FK cascade, `section` str50, `content_json` json, `content_text` text, `author_id` FK, `revision_number` (unique per encounter+section+revision) | `content_json`→array (EncounterNote.php:28-30) | belongsTo encounter, author (35-46). Migration 2026_07_31_000002:11-23 |
| Prescription | `prescriptions` | `patient_id` FK cascade, `consultation_id` FK null, `document_id` FK documents null, `prescribed_at`, `items` json, `notes` text, `created_by` | `items`→array, `prescribed_at` immutable (Prescription.php:31-37) | belongsTo patient, document — **no consultation() or encounter() relation method despite the FK** (Prescription.php:40-53). Migrations 2026_07_31_050000:29-40, 2026_08_02_000000:29-32 |
| Medication | `medications` | `name` str200, `dci` str200 (generic), `form` str100, `dosage` str100, `notes` text, `is_active` | bool | none; `scopeSearch` over name/dci/form (Medication.php:45-60). Migration 2026_07_31_010000:15-28 |
| Diagnosis | `diagnoses` | `encounter_id` FK cascade, `code` str50, `code_system` str100, `display_label` str255, `notes` text, `status` str20 default `active`, `created_by` | `status`→`DiagnosisStatus` (`active/resolved/ruled_out`, app/Enums/DiagnosisStatus.php:5-9) | belongsTo encounter, createdBy (Diagnosis.php:37-48). Migration 2026_07_31_000003:11-24 |
| ClinicalObservation | `clinical_observations` | `patient_id` FK, `encounter_id` FK null, `type` str50, `numeric_value` dec(10,2), `string_value` str255, `unit` str50, `observed_at`, `source` str50 default `manual`, `note` text, `created_by` | decimal:2, datetime (ClinicalObservation.php:34-37) | belongsTo patient, encounter, createdBy (42-61). Migration 2026_07_31_000004:11-27 |
| PatientAntecedent | `patient_antecedents` | `patient_id` FK cascade, `category` str50, `description` text NOT NULL, `started_on/ended_on` date, `is_active` bool, `source_encounter_id` FK, `created_by` | `category`→`AntecedentCategory` (`medical/surgical/family/gyneco_obstetric/other`, app/Enums/AntecedentCategory.php:5-11) | belongsTo patient, sourceEncounter, createdBy (PatientAntecedent.php:45-64). Migration 2026_07_31_000005:11-24 |
| PatientMeasurement | `patient_measurements` | `patient_id` FK cascade, `measured_at`, `weight_kg/height_cm/bmi/waist_cm/head_cm` dec(5,2), `notes` text, `created_by` | immutable datetime (PatientMeasurement.php:32-37) | belongsTo patient (42-45). Migration 2026_07_31_050000:12-26 |
| Payment | `payments` | `public_id` uuid unique, `consultation_id` FK cascade, `patient_id` FK restrict, `amount_minor` bigint, `method` str50, `notes` text, `received_at`, `received_by` FK, `client_reference` uuid (unique per consultation — idempotency) | int, immutable datetime (Payment.php:44-50) | belongsTo consultation, patient (withTrashed), receivedBy (53-68). Append-only ledger by design (Payment.php:13-16). Migration 2026_08_09_100000:19-36 |
| PaymentMethod | `payment_methods` | `name` str100, `is_active` bool | bool (PaymentMethod.php:9-20) | none. Migration 2026_07_31_020000:68-74 |
| Document | `documents` | `patient_id` FK cascade, `consultation_id` FK null, `medical_model_id` FK null, `category` str40 default `courrier`, `title` str200, `template_key` str120, `paper_size` str2, `content` longText, `file_path`, `original_filename`, `mime_type`, `file_size`, `file_version`, `created_by` | ints (Document.php:36-42) | belongsTo patient (45-50). Migrations 2026_07_31_060000:12-23, 2026_08_02_000000:17-27 |
| UploadedDocument | `uploaded_documents` (uuid PK) | `upload_session_id` uuid FK, `patient_id` FK null, `document_id` FK null, `original_name`, `stored_name`, `disk`, `path` str1000, `mime_type`, `size`, `sha256`, `status` str30 (`quarantined/pending_review/accepted/rejected` consts, UploadedDocument.php:42-48), `uploaded_at`, `reviewed_by/at` | int, immutable datetimes | belongsTo uploadSession, patient, document, reviewer (60-81). Migration 2026_08_04_050000:33-55 |
| Act | `acts` | `code` str60, `name` str200, `price_minor` bigint null, `category` str100, `is_active` | int/bool (Act.php:9-20) | none. Migration 2026_07_31_020000:56-66 |
| Exam | `exams` | `name` str200, `category` str100, `is_active` | bool (Exam.php:9-20) | none. Migration 2026_07_31_020000:22-30 |
| BilanType | `bilan_types` | `name` str200, `description` text, `category` str100, `is_active` | bool (BilanType.php:9-20) | none. Migrations 2026_07_31_020000:12-20, 2026_08_03_000000:12-14 |

#### (a) Appointment status — exact values and lifecycle

Status is a string(32) DB column (default `'scheduled'`, 2026_07_28_230200_create_appointments_table.php:22) cast to `App\Enums\AppointmentStatus` (Appointment.php:155). Exact values (AppointmentStatus.php:7-13): `scheduled`, `confirmed`, `checked_in`, `in_progress`, `completed`, `cancelled`, `no_show`.

- Blocking (occupy the slot): scheduled, confirmed, checked_in, in_progress (AppointmentStatus.php:15-21); used by conflict detection (app/Actions/Appointments/CreateAppointmentAction.php:56, Appointment.php:233-236).
- Creatable at booking: only `scheduled` and `confirmed` (AppointmentStatus.php:40-46; app/Http/Requests/Appointments/StoreAppointmentRequest.php:25).
- Transitions enforced in controllers, not the model: SCHEDULED→CONFIRMED (app/Http/Controllers/Appointments/AppointmentController.php:210-217; API mirror app/Http/Controllers/Api/V1/AppointmentController.php:191-197); SCHEDULED|CONFIRMED→CHECKED_IN (AppointmentController.php:233-240); CHECKED_IN→IN_PROGRESS on consultation start (app/Http/Controllers/Consultations/ConsultationController.php:84-101); →COMPLETED when the consultation finishes (ConsultationController.php:447); any non-terminal→CANCELLED (AppointmentController.php:257-268; Api/V1/AppointmentController.php:206-210). Terminal set = completed/cancelled/no_show (app/Http/Resources/AppointmentResource.php:34-36).
- **`no_show` is declared but no code path ever sets it** (only reads: enum, labels, terminal checks — grep across app/ finds zero assignments).
- Every tracked change bumps `sync_version` and publishes an `AppointmentSyncEvent` via model events (Appointment.php:62-143); soft delete publishes a deletion event (139-143).
- Mobile mapping note: the mobile spec's `pending` does not exist; its closest equivalent is `scheduled` (UI label "Non confirmé", AppointmentController.php:475).

#### (b) Patient ↔ User link and family/dependents

**No link exists.** `patients` has no `user_id`; its only User FK is audit-style `created_by` (Patient.php:129-132; 2026_07_28_230000:30). `User` has no patient relation and no patient role (RoleName.php:5-22). The cross-installation identity is `patients.public_id` uuid (Patient.php:66-73; 2026_08_31_120000:30-32) — an identifier, not an account. The model comment states the desktop, hosted service, and mobile app all refer to a patient by this uuid (Patient.php:68-74), so the sync plumbing anticipates a mobile client, but no account concept exists.

**No family/dependent/guardian concept.** Searching app/Models/ and database/migrations/ for family|dependent|guardian|parent|relative|kin|tuteur|proche yields only: `antecedents_family` (family *medical history* free text, 2026_07_31_040000:20), `AntecedentCategory::Family` (AntecedentCategory.php:9), Telescope's unrelated `family_hash`, and `emergency_contact_name/phone` strings on patients (2026_07_28_230000:26-27) — the nearest existing concept, but a plain text pair with no relation type, no linked profile, no consent flow. The only "family" logic anywhere is a sync-time safeguard that **refuses** to merge family members sharing a phone (app/Services/Sync/PatientResolver.php:24-25, 100-118) — the opposite of phone-based account linking.

#### (c) Patient demographics fields

- Phone: **yes** — `phone` (30, indexed, nullable, **not unique**) + `secondary_phone` (2026_07_28_230000:21-22,36).
- Gender: **yes** — `gender` str20 cast to `Gender` enum, values `male`/`female` only (app/Enums/Gender.php:5-8).
- DOB: **yes** — `date_of_birth` date, nullable, indexed (2026_07_28_230000:19,35). **No place-of-birth field.**
- Address: **yes but unstructured** — `address` (free string) and `city` str120 indexed (2026_07_28_230000:24-25). **No wilaya or commune/baladiya columns anywhere in patients.**

#### (d) How prescriptions relate to consultations/encounters/patients

`prescriptions.patient_id` (required, cascade) and `prescriptions.consultation_id` (nullable, nullOnDelete) (2026_07_31_050000:31-32), plus `document_id` to the generated ordonnance document (2026_08_02_000000:29-32). Medication lines live in `items` json — free-form, **no FK to the `medications` catalogue**. There is **no relation to `encounters` at all**: the codebase carries two parallel clinical record systems — the flat `consultations` row (motif/diagnostic/traitement text) that prescriptions and payments hang off, and the structured `encounters` graph (encounter_notes, diagnoses, clinical_observations) that prescriptions ignore. The Prescription model exposes only `patient()` and `document()` relations; even the existing `consultation_id` FK has no relation method (Prescription.php:40-53).

#### (e) Models holding free-text medical notes (sensitive)

| Model / table | Free-text columns |
|---|---|
| Patient | `notes`, `allergies`, `antecedents_medical/surgical/family/gyneco/other` (2026_07_28_230000:29; 2026_07_31_040000:17-22) |
| Consultation | `motif`, `examens`, `diagnostic`, `traitement`, `notes`, `payment_notes` (2026_07_31_030000:17-21; 2026_08_09_100000:15) |
| EncounterNote | `content_json`, `content_text` — the primary clinical narrative (2026_07_31_000002:15-16) |
| Encounter | `amendment_reason` (2026_07_31_000001:23) |
| Diagnosis | `notes` (2026_07_31_000003:17) |
| ClinicalObservation | `note`, `string_value` (2026_07_31_000004:18,21) |
| PatientAntecedent | `description` (required) (2026_07_31_000005:15) |
| PatientMeasurement | `notes` (2026_07_31_050000:21) |
| Prescription | `notes`, `items` json (2026_07_31_050000:34-35) |
| Document | `content` longText — full rendered certificats/courriers/ordonnances (2026_07_31_060000:18) |
| Appointment | `reason`, `reception_notes` (internal staff notes), `cancellation_reason` (2026_07_28_230200:23-27) |
| AppointmentSyncEvent | `payload` json replicates `reason`, `reception_notes`, `cancellation_reason` per version (2026_08_09_110000 payload(): 122-146) |

None of these models declares `$hidden`, so default serialization emits everything (risk §5.3).

### 3c. Reference data (geography & specialties)

#### Geography: what exists

The only geographic reference data in the entire backend is a **wilaya catalogue** (config array + static helper). There is no baladiya/commune data of any kind.

| Artifact | Location | Detail |
|---|---|---|
| Wilaya catalogue (all 58) | `config/wilayas.php:17-76` | Official 58 Algerian wilayas keyed by zero-padded two-digit code, explicitly including the ten 2019/2021-reform wilayas, codes 49–58 (`config/wilayas.php:8-10`). Names are French/Latin only (`'16' => 'Alger'`, `'06' => 'Béjaïa'`) — **no Arabic names**. |
| `App\Support\Wilayas` helper | `app/Support/Wilayas.php:9` | Read-only wrapper: `MIN = 1`, `MAX = 58` (`:11-13`), `all()` (`:20`), `options()` shaped `{code:int, name:string}` for selects (`:36`), `exists()` (`:47`), `name()` (`:52`), `label()` "02 - Chlef" style (`:65-78`). |
| Persistence: `cabinets.wilaya_code` | `database/migrations/2026_08_06_000000_create_cabinets_table.php:21` | `unsignedTinyInteger('wilaya_code')->nullable()` — the **only** wilaya column in the schema. Not indexed. The backfill migration sets it to `null` for pre-existing cabinets (`database/migrations/2026_08_06_000400_backfill_default_cabinet.php:70`). |
| Model wiring | `app/Models/Cabinet.php:32` (fillable), `:49` (integer cast), `:176-178` (`wilaya_name` accessor via `Wilayas::name()`) | Wilaya lives on the Cabinet only. |
| Input validation | `app/Actions/Fortify/RegisterCabinetAction.php:50` | `'wilaya' => ['required', 'integer', 'between:1,58']` at cabinet self-registration; stored at `:99`. Set once at registration; no edit path found. |
| API exposure | `app/Http/Resources/CabinetResource.php:24-27` | `'wilaya' => ['code' => ..., 'name' => ...]`; used only inside `UserResource` for the authenticated user's own cabinet (`app/Http/Resources/UserResource.php:25`). |
| Frontend | `app/Providers/FortifyServiceProvider.php:95` passes `Wilayas::options()` to the register page; consumed in `resources/js/pages/auth/Register.vue:27,245-250` | No hardcoded wilaya list in JS — the backend catalogue is the single source. |
| Admin display | `app/Filament/Resources/Cabinets/Tables/CabinetsTable.php:50-52`, `app/Filament/Widgets/PendingCabinets.php:68-70` | Formatted via `Wilayas::label()`. |

Free-text "city" strings exist but are **not** reference data (arbitrary user input, no catalogue): `patients.city` string(120) nullable + index (2026_07_28_230000:25,38; Patient.php:42), `cabinet_settings.city` (2026_08_03_020000:12; CabinetSetting.php:14), `doctor_profiles.city` (2026_08_04_030000:20; DoctorProfile.php:27).

#### Geography: what does NOT exist

**Baladiya / commune / daira data is completely absent** — no model, no table, no enum, no seeder, no config file, no hardcoded JS list. Case-insensitive searches for `wilaya|baladiya|commune|daira|province|governorate|city|region` scoped to `app/Models/`, `app/Enums/`, `database/migrations/`, `database/seeders/`, `config/`, `resources/js/`, `app/Http/`, and `baladiya|commune|daira|governorate|municipal` across `app/` and `routes/` find only the medication phrase "Dénomination commune internationale" (INN) in `resources/js/pages/configuration/Medications.vue:177,337` — false positives. "daira" appears nowhere. Additionally:

- `database/seeders/` (full listing): `CabinetDoctorSeeder`, `ConfigurationSeeder`, `DatabaseSeeder`, `ExamSeeder`, `LicenseTypeSeeder`, `MedicationSeeder`, `PlatformAdminSeeder`, `RolesAndPermissionsSeeder` — no geographic seeder; `DatabaseSeeder::run()` calls none (DatabaseSeeder.php:20-26).
- `app/Enums/` (full listing): 11 enums (`AntecedentCategory`, `AppointmentStatus`, `BloodGroup`, `CabinetStatus`, `DiagnosisStatus`, `EncounterStatus`, `Gender`, `LicensePlan`, `PermissionName`, `RoleName`, `Weekday`) — none geographic.
- No wilaya on `patients` or `doctor_profiles` (only free-text `city`); no controller/route filters by `wilaya_code` (grep over `app/Http/` and `routes/` — the only Http hit is the `CabinetResource` serializer above).
- No map coordinates anywhere: `latitude|longitude|lat_|lng|coordinates|geo_` has zero matches in `database/migrations/` and `app/Models/`.
- No Arabic geographic names anywhere (the catalogue is Latin-script only).

#### Medical specialties: what exists

Specialty is a **free-text string with an advisory catalogue** — not an enum, not a table, not a foreign key.

| Artifact | Location | Detail |
|---|---|---|
| `App\Support\MedicalSpecialtyCatalog` | `app/Support/MedicalSpecialtyCatalog.php:7` | 21 hardcoded specialties as `code => French label` (`:10-32`, e.g. `'cardiology' => 'Cardiologie'`); English legacy aliases (`:35-57`); `display()` (`:65`) and `codeFor()` (`:79`). Crucially, `codeFor()` **accepts any unknown string** and slugs it (`:88-92`, fallback `'specialty_'.hash`) — the catalogue suggests, it does not constrain. |
| `doctor_profiles.specialty` | 2026_07_28_230100:18 (string 150, required), `:26` (indexed) | Plus `specialty_code` string(100) nullable indexed and `specialty_locked_at` (2026_08_04_030000:15,24; backfill slugs legacy values `:35-48`). |
| Specialty lock | `app/Models/DoctorProfile.php:64-72` | Once set, editing `specialty`/`specialty_code` throws `AuthorizationException` (`:67`); only the audited `correctLockedSpecialty()` path (`:96-124`, audit event `doctor.specialty_corrected` `:117`) can change it, surfaced at `app/Http/Controllers/Configuration/ClinicIdentityController.php:70-101`. |
| `cabinets.specialization` | 2026_08_06_000000:20 (string, nullable); Cabinet.php:21,31 | Validated only as `['required','string','min:2','max:150']` at registration (RegisterCabinetAction.php:49), normalized through `display()`/`codeFor()` (`:98,174-175`). Exposed in CabinetResource.php:23. |
| `practitioners.specialty` (external-doctor directory) | 2026_07_31_020000:35 (string 150 nullable); Practitioner.php:9 | CRUD via the referential registry with a plain `type => 'text'` field and `['nullable','string','max:150']` rule (app/Configuration/ReferentialRegistry.php:94,102) — pure free text. |
| UI suggestions | FortifyServiceProvider.php:94 and ClinicIdentityController.php:46 pass `MedicalSpecialtyCatalog::labels()` as `specialtySuggestions` | Suggestions only — user may type anything. |
| Seed/factory values | CabinetDoctorSeeder.php:43 seeds `'General Medicine'` (English); database/factories/DoctorProfileFactory.php:23-29 picks from five English names | The same column legitimately holds English and French variants of the same specialty. |
| API exposure | `app/Http/Controllers/Api/V1/ScheduleController.php:66` returns raw `$doctor->specialty` | The unnormalized string is already flowing to an API consumer. |

#### Medical specialties: what does NOT exist

No specialty enum, no `specialties` database table (grep `special` over all of `database/` matches only string columns on `doctor_profiles`, `cabinets`, `practitioners`, `desktop_download_leads` and seed strings), no specialty seeder (ConfigurationSeeder.php:19-99 seeds bilan types, payment methods, consultation fees, acts, exams — not specialties), no validation rule anywhere restricting a specialty to the catalogue (RegisterCabinetAction.php:49, ReferentialRegistry.php:102, ClinicIdentityController.php:82 all accept arbitrary strings), and no Arabic specialty labels (the catalogue is French-only, MedicalSpecialtyCatalog.php:10-32).

## 4. Gaps — what a mobile client needs that does not exist

Ordered by how blocking each gap is for the mobile app. Every gap below was independently verified against the code unless explicitly marked **UNVERIFIED** (claims whose dedicated verification pass did not run; their evidence comes from the draft research only).

### 4.1 No patient identity — patients are records, not accounts

**Missing:** any way for a patient to *be* a user. **Evidence:** `RoleName` has exactly two roles, `Doctor` and `Assistant` (app/Enums/RoleName.php:7-8); the seeder deletes any other role (database/seeders/RolesAndPermissionsSeeder.php:33) and migration 2026_08_10_000000_consolidate_roles_to_doctor_and_assistant.php:55-63 collapsed all legacy roles onto those two (irreversible, :248). `Patient` extends `Model`, not `Authenticatable` (app/Models/Patient.php:56) — no credentials, no `user_id`, no auth traits; its only User FK is `created_by` (Patient.php:134). `config/auth.php` defines a single `web` guard over the single `users` provider (config/auth.php:40-45, 64-68). `patients.public_id` (uuid, 2026_08_31_120000:30-32) is a sync identifier, not an account. **Building it requires:** a patient account concept (either a `users` row linked to `patients` via a new `patient_id`/`user_id` bridge, or a separate authenticatable Patient guard), a `patient` role/authorization surface, and — critically — a tenancy answer, because a patient is not a member of one cabinet and the current scope fails open for cabinet-less users (risk §5.1). Mobile's four roles do not map today: admin and doctor are the same Spatie role, reception is an alias of Assistant, patient does not exist.

### 4.2 No phone-number authentication, OTP, or SMS anywhere

**Missing:** phone-first signup/login for Algerian patients. **Evidence:** Fortify's identity field is email (`'username' => 'email'`, config/fortify.php:48); the API token endpoint validates `email/password/device_name` only (app/Http/Controllers/Api/V1/AuthController.php:25-32); the `users` table has **no phone column** (0001_01_01_000000_create_users_table.php:14-22); phone columns exist only as unverified free text on cabinet_settings, doctor_profiles, desktop_download_leads and patients (nullable, indexed, **not unique** — 2026_07_28_230000:21,36). Greps for `otp|sms|twilio|vonage|nexmo|infobip` return zero matches in app/, config/, routes/, database/, tests/, composer.json, .env.example; the only OTP-named code is the TOTP-2FA input widget (resources/js/components/ui/input-otp, TwoFactorChallenge.vue). **Building it requires:** a unique, verified phone identity column, an SMS/OTP provider integration, and a new Fortify/Sanctum flow; also a phone-canonicalization decision for `patients.phone`, which today is optional and shared between records (the sync layer even refuses to merge family members sharing a phone, app/Services/Sync/PatientResolver.php:24-25, 100-118).

### 4.3 No patient self-signup, and a login/token contract that does not match the mobile spec

**Missing:** a registration path that creates a patient. **Evidence:** the only public registration paths are cabinet-owner registration (`POST /api/v1/cabinets/register` and Fortify `/register`, both landing in `RegisterCabinetAction`, which provisions a whole cabinet + doctor profile + schedule, RegisterCabinetAction.php:112, 125, 163-193) and staff join-by-owner-email (routes/api.php:28-32; JoinCabinetAction.php:69 enforces the 3-seat cap, :83 creates a pending member). A patient signup today would create a cabinet owner or a seat-consuming staff member. On login, `POST /api/v1/auth/token` returns roles as Spatie names (`Doctor`/`Assistant`) plus a full permission dump (UserResource.php:26-27), not the admin/doctor/reception/patient enum the client expects (docs/api.md:126 even shows a stale example the code does not produce); tokens are minted with wildcard abilities and no expiry (AuthController.php:50 is the only `createToken` call; config/sanctum.php:53 hardcodes `'expiration' => null`; no `abilities` middleware registered, zero `tokenCan` usage). **Building it requires:** a patient signup endpoint, a mobile-shaped role field in the login response, and per-token abilities + expiry/refresh (Sanctum's `createToken()` natively accepts both — small plumbing, but currently unused).

### 4.4 No public doctor/clinic discovery (wilaya → baladiya → specialty → practitioner)

**Missing:** any endpoint that lists or searches cabinets/doctors. **Evidence:** the entire public API surface is `POST auth/token`, `cabinets/register`, `cabinets/join` (routes/api.php:25-32); the API `CabinetController` has only `register()` and `join()` (CabinetController.php:19,33); every authenticated route is pinned to the token's own cabinet by the `BelongsToCabinet` scope (BelongsToCabinet.php:21-36), so cross-cabinet search-and-book is impossible with any current token. The public web surface is a marketing landing plus join/register forms (routes/web.php:49-129). The building blocks exist as data — `cabinets.wilaya_code` + `specialization` (CabinetResource.php:23-27), `doctor_profiles.specialty` (2026_07_28_230100:18,26) — but their only consumers are registration and the Filament admin tables (RegisterCabinetAction.php:50,99; CabinetsTable.php:50-52; PendingCabinets.php:68-70). `GET /api/v1/schedule` requires cabinet membership (routes/api.php:53), so availability cannot be browsed publicly. Note the hosted multi-tenant deployment of `/api/v1` **does** exist (config/hub.php:23-26 — the control plane serves all cabinets; desktop sync depends on it) — what is missing is the public directory layer on top of it. **Building it requires:** public search endpoints (wilaya/specialty/practitioner facets), an index on `wilaya_code` (currently nullable and unindexed, 2026_08_06_000000:21), backfill/edit paths for wilaya (legacy cabinets were backfilled to `null`, 2026_08_06_000400:70 — they would silently vanish from a wilaya-first search; *UNVERIFIED as a standalone claim*), a deliberate public-visibility flag on Cabinet (nothing marks a cabinet listable today; *UNVERIFIED*), and a normalized specialty vocabulary (§4.6).

### 4.5 Baladiya (commune) reference data does not exist at all — and no Arabic names

**Missing:** the second level of the mobile search funnel and Arabic labels for the first. **Evidence:** no commune/baladiya/daira model, table, enum, seeder, config, or JS list anywhere (search summary in §3c; the discovery-gap verification independently confirmed the only "commune" hits are the medication INN phrase in Medications.vue:177,337). The wilaya catalogue is French/Latin only (config/wilayas.php:17-76) and the specialty catalogue French only (MedicalSpecialtyCatalog.php:10-32) — the Arabic-RTL client needs an Arabic name per entry (*the Arabic-names claim is UNVERIFIED as a standalone item; the underlying French-only catalogues are directly quoted above*). **Building it requires:** a full commune catalogue (~1,541 communes keyed to wilaya) as a table+seeder or config, `{fr, ar}` name pairs for wilayas/communes/specialties, and a baladiya column on whatever entity becomes searchable.

### 4.6 No controlled specialty vocabulary for a search facet — **UNVERIFIED**

**Missing:** a canonical specialty table/enum with enforced codes. **Evidence (draft research, §3c):** specialty is free text everywhere; `codeFor()` slugs any unknown input into a distinct permanent code (MedicalSpecialtyCatalog.php:88-92) which is then **locked** by `specialty_locked_at` (DoctorProfile.php:64-72 — fixing a locked typo requires a super-admin audited correction per doctor); seeded/factory data is English while the catalogue is French (CabinetDoctorSeeder.php:43 'General Medicine' vs 'Médecine générale'); `Api/V1/ScheduleController.php:66` already ships the raw string. A public specialty filter would fragment across English/French/misspelled variants. **Building it requires:** a specialty table or enum, enforced validation at registration/identity edit, and a data migration normalizing existing strings.

### 4.7 Clinic detail content model missing (about, morning/evening labels, multiple phones, coordinates, gallery) — **UNVERIFIED**

**Missing:** everything the clinic detail page shows beyond a name and address. **Evidence (draft research):** `cabinet_settings` has a single `phone` string(30), one `address`, `city`, one `logo_path` (2026_07_29_000000:15-31 + 2026_08_03_020000:12); `doctor_profiles` adds one phone/full_address/logo_path (DoctorProfile.php:17-35). There is no about/description text, no structure for multiple phone numbers, no latitude/longitude anywhere (`latitude|longitude|lat_|lng|coordinates|geo_` — zero matches in database/migrations/ and app/Models/), and no photo-gallery model. `GET /api/v1/schedule` returns raw ranges with no MORNING/EVENING label and no closed-day flag beyond row absence/`is_active` (ScheduleController.php:34-42; grep for morning/evening/matin/soir across app/ and resources/js: zero hits — this sub-point verified in the working-hours pass). **Building it requires:** new columns/models (about text, phone list, lat/lng, gallery storage), a `period` label or convention on `doctor_schedules`, and a public clinic-detail endpoint.

### 4.8 Working hours: morning/evening not supported end-to-end; no write API for hours or leave

**Missing:** the mobile design's "separate MORNING and EVENING ranges per weekday with closed days," and any API to edit hours/leave. **Evidence:** verified in §3a(c) — the DB schema and availability engine support multiple ranges per weekday, but the editor's request shape is one range per weekday (UpdateDoctorScheduleRequest.php:23-30), persistence is wipe-and-replace (SyncDoctorScheduleAction.php:17-32), and the editor seed collapses extra rows (`keyBy(day_of_week)`, ScheduleController.php:79-96) — a second range would be silently destroyed on the next save. `GET /api/v1/schedule` is read-only (routes/api.php:53); there are **no API endpoints to create/update `DoctorSchedule`, `DoctorTimeOff`, or `DoctorOpenMonth`** (the mobile doctor's working-hours editor and leave management cannot be built on this surface; *the write-API absence was confirmed in the working-hours verification*). Registration seeds a single 09:00–17:00 row per weekday (RegisterCabinetAction.php:186-202). Also no DB-level overlap protection: the unique key only blocks identical rows, so overlapping ranges insert cleanly (*UNVERIFIED*; 2026_07_28_230100:41). **Building it requires:** a two-range (or n-range) editor payload + validation (`distinct` weekdays, overlap checks), a period label, an explicit closed-day representation, and authenticated doctor-facing write endpoints in API v1.

### 4.9 Appointment lifecycle missing mobile semantics: pending, decline, reschedule, no_show setter, cancel cutoff

**Missing:** the mobile status contract (`pending → confirmed → completed` + `cancelled`/`no_show`, patient cancel before a cutoff, doctor confirm/decline/reschedule). **Evidence:** `AppointmentStatus` has no `pending` (closest: `scheduled`, labeled "Non confirmé"); the API `match` permits transitions only to CONFIRMED / CHECKED_IN / CANCELLED and rejects everything else — completed and no_show explicitly (Api/V1/AppointmentController.php:188-219, :215-217); no reschedule route or transition exists anywhere (UpdateAppointmentRequest.php:15-28 accepts no new `starts_at`; no `rescheduled_from/at` columns); `no_show` is declared but never assigned by any code path (§3b(a)); cancellation guards check only non-terminal status — **no time-based cutoff exists anywhere** (AppointmentController.php:257-268; Api/V1/AppointmentController.php:206-210; AppointmentResource.php:36); DELETE hard-removes (soft delete) rather than transitioning (AppointmentController.php:166-179, risk §5.7). Doctor "decline" exists only as staff cancel; COMPLETED/IN_PROGRESS are set by the consultation workflow (ConsultationController.php:101, 447), which a mobile doctor client cannot reach. **Building it requires:** a `pending` (or renamed `scheduled`) patient-booking status, decline + reschedule operations, a no_show setter, a configurable patient-cancel cutoff rule, and patient-scoped authorization on all of it.

### 4.10 Family members / dependents entirely absent

**Missing:** dependent profiles (relation/gender/DOB/place-of-birth), consent-based linking of an existing account by phone, and booking on behalf of a family member. **Evidence:** no family/dependent/guardian model, column, or migration anywhere (all 65 migrations grepped; §3b(b)); `patients` has no `place_of_birth` column; `PatientResource` has no relation fields (PatientResource.php:19-35); appointments have no booked-for/booked-by distinction (only `created_by`, a staff FK); the only "family" logic is the sync safeguard that refuses to merge phone-sharing family members (PatientResolver.php:100-118). **Building it requires:** a relation table (patient↔patient or account↔patient with relation type, consent state), place-of-birth on patients, a consent flow for linking by phone, and `booked_by` semantics on appointments.

### 4.11 No notifications of any kind — push, database, or SMS

**Missing:** "notifications on every status change" and push delivery. **Evidence:** no `app/Notifications/` directory (full app/ listing); no notifications table migration; zero `notify()`/`Notification::` call sites in app/Http, app/Actions, app/Jobs; the only outbound messages are Fortify's password-reset/verification emails and three cabinet-licensing mailables sent via plain `Mail::to()` (app/Services/CabinetFulfillmentService.php:749, 771, 795; app/Mail/ holds only the three licensing mailables). No FCM/APNs/Expo/OneSignal reference in config/ or composer.json; no device-token registration endpoint (routes/api.php in full — the only "push" route is data-sync push, :51); the existing `Device` model is desktop license fingerprinting (Device.php:11-19), and the token login captures only a `device_name` string (AuthController.php:28). Appointment model events publish `AppointmentSyncEvent`s only — no notification hook on confirm/cancel/complete (Appointment.php:115-143; Api/V1/AppointmentController.php:158 writes attributes directly). `User` has `Notifiable` but only Fortify's built-ins consume it (User.php:52; config/fortify.php:165-166). **Building it requires:** a notifications channel stack (FCM/APNs + database), a push-token registration endpoint keyed to Sanctum tokens, dispatch hooks on every appointment transition, and queue-safe tenancy (risk §5.5).

### 4.12 No patient-visible prescriptions endpoint

**Missing:** "patient sees own prescriptions." **Evidence:** routes/api.php exposes **no prescription route at all** (:23-59); prescription data is served only through staff-permission web routes (create: routes/web.php:228-231 behind `permission:prescriptions.create`; read: consultation history behind `permission:consultations.view`, routes/web.php:210-213, ConsultationHistoryController.php:70,145). Nothing scopes prescriptions to "the authenticated patient's own" — impossible anyway without gap 4.1. Data-model caveats for the eventual build (*UNVERIFIED as standalone claims*): `items` is free-form JSON with no FK to the `medications` catalogue and there is no encounter link (§3b(d)), so a patient-facing view cannot rely on normalized drug names. **Building it requires:** a patient-scoped read endpoint + resource (curated: no staff notes), ownership anchoring via the patient account link, and ideally normalized prescription items.

### 4.13 Reception accounts scoped to one doctor — by convention only — **UNVERIFIED**

**Missing:** an explicit reception→doctor binding. **Evidence (draft research, §3a(e)):** an Assistant is scoped to one cabinet via `users.cabinet_id`, and one cabinet is assumed to hold one doctor ("Single-doctor cabinet: exactly one active doctor profile is expected", 2026_07_28_230100:14; `DoctorProfile::current()` at DoctorProfile.php:85-88; API paths assume the implicit current doctor — ScheduleController.php:23, AvailabilityService::forCurrentDoctor() via Api/V1/AppointmentController.php:95; appointments carry no `doctor_id` parameter). The mobile requirement ("reception accounts scoped to exactly one doctor/clinic") is satisfied *today* by the single-doctor assumption, but multi-doctor clinics and an explicit reception→doctor link are unrepresentable. **Building it requires:** either a documented commitment to single-doctor cabinets, or `doctor_id` on appointments plus a reception→doctor assignment.

### 4.14 No Arabic localization; API messages hardcoded in French — **UNVERIFIED**

**Missing:** Arabic (RTL) content negotiation. **Evidence (draft research):** `APP_LOCALE=fr` with `fr` fallback (config/app.php:81-83; .env.example:82-83); `lang/` contains only `fr/`; API responses embed hardcoded French literals ("Rendez-vous supprimé.", "Seuls les rendez-vous programmés peuvent être confirmés." — Api/V1/AppointmentController.php:178, 193, 200, 208, 216; CabinetController.php:39 — these specific literals were confirmed by other verification passes). **Building it requires:** an `ar` translation catalogue, `Accept-Language` negotiation on the API, and Arabic entries in the reference catalogues (§4.5).

### 4.15 Desktop-to-cloud pairing for the existing sync path is unbuilt

**Missing:** any product flow that writes `sync.mobile.endpoint`/`sync.mobile.token` on a desktop. **Evidence:** `MobileSyncSettings::configure()` has no caller in `app/` (grep of app/Http hit only MobileSyncController; app/Console only SyncMobileAppointments) — only the test suite calls it (tests/Feature/Sync/MobileAppointmentSyncTest.php:59). Until pairing exists, no mobile-originated booking accepted by the hosted instance can reach a clinic's desktop. **Building it requires:** a pairing/settings UI (or provisioning flow) that mints a remote token via `POST /api/v1/auth/token` and stores it through `MobileSyncSettings`.

## 5. Risks — what breaks or leaks if exposed to a public mobile client

Ordered by severity. Verified unless marked **UNVERIFIED**.

### 5.1 HIGH — Tenant isolation silently disables for cabinet-less accounts

The entire tenant wall is one conditional: the `BelongsToCabinet` global scope adds a `cabinet_id` WHERE clause only when the authenticated user has a non-null `cabinet_id` (BelongsToCabinet.php:28-35); the `updating` guard also stands down (:59), and the `creating` auto-assignment is skipped (:45-53). `CabinetAccessService::denialReason()` **explicitly waves cabinet-less non-admin users through** ("Legacy / unscoped accounts have no tenant gate", CabinetAccessService.php:45-48), the same service that gates token minting (AuthController.php:41-50) and `cabinet.active.api` (EnsureApiCabinetIsActive.php:31-40) — so such accounts can obtain API tokens unconditionally. Verified nuance: the authorization layer narrows *current* exploitability — every endpoint calls `authorize()`, cabinet-less users fall back to global Spatie roles (User.php:61-72), and a role-less account 403s everywhere; per-record policies require `sharesCabinetWith` (AppointmentPolicy.php:45-57, PatientPolicy.php:45-57). The presently exploitable population is (a) any legacy cabinet-less account holding a global Doctor/Assistant role — it lists **all cabinets'** appointments and patients (`viewAny` checks permission only, PatientPolicy.php:11-14; unscoped `Patient::query()`, PatientController.php:27) and can mass-acknowledge **every tenant's** sync events (AppointmentSyncController.php:75-84) — and (b) platform admins (explicit bypass, BelongsToCabinet.php:24; Gate::before, AppServiceProvider.php:61-63; the dev seeder produces exactly such unscoped role-holders, CabinetDoctorSeeder.php:28-35). The forward risk is the big one: a self-signup patient account would be exactly this shape (a `users` row with no cabinet), and neither the scope, the middleware, nor `viewAny` constrains it. **Mitigation: make the scope fail closed (deny, or force an impossible match, when `cabinet_id` is null and the user is not a platform admin) and deny cabinet-less non-admins at the eligibility gate before any patient account type exists.**

### 5.2 HIGH — API tokens are wildcard-ability and never expire

`createToken($device_name)` is called with no abilities and no expiry (AuthController.php:50 — the only `createToken` call in app/), defaulting to `['*']`; `config/sanctum.php:53` hardcodes `'expiration' => null`; no `tokenCan()`/ability middleware exists anywhere; no pruning is scheduled (routes/console.php:27,31 prunes only oauth attempts and restore preparations). A token leaked from a phone (backup, malware, log) is a permanent full-power credential over its cabinet's entire PHI surface; the only revocation is voluntary logout of that token (AuthController.php:66-68). Platform admins can mint tokens too (CabinetAccessService.php:39-41) — such a token bypasses tenancy *and* every policy, returning all tenants' patients. **Mitigation: scoped abilities per client type, token expiry + refresh/rotation, scheduled pruning, and refuse (or heavily restrict) token issuance to `is_platform_admin` accounts.**

### 5.3 HIGH — Staff-grade resources and un-hidden clinical models would over-expose PII the moment any endpoint is reused for patients

Present-day protection is real: tokens go only to approved staff of active cabinets, and the query-layer scope confines data to the token's cabinet — so today's API does not leak to outsiders. But nothing in the serialization layer is audience-aware: `AppointmentResource` unconditionally ships `reason`, `reception_notes` (internal staff commentary), and `cancellation_reason` (AppointmentResource.php:30-33), and every appointment endpoint embeds the full `PatientResource` — phone, secondary_phone, email, DOB, blood group, gender, address, city (PatientResource.php:19-35; `->with('patient')`, AppointmentController.php:40). `UserResource` flattens the caller's complete roles + permissions arrays (UserResource.php:26-27). No clinical model declares `$hidden`, so any resource-less response (`return $patient;`, `->get()` into JSON — the standard pattern in the Inertia web controllers that would template new endpoints) ships allergies, antecedents, diagnostic/traitement narrative, and payment notes (**UNVERIFIED as a standalone claim**; field inventory in §3b(e); Patient.php:29-55, EncounterNote.php:19-30, Consultation.php:20-44, Document.php:14-28). `DoctorProfile` likewise serializes the doctor's private phone/email/medical_order_number by default (**UNVERIFIED**; DoctorProfile.php:17-35, no `#[Hidden]`), and `CabinetResource` carries license plan/status/expiry — commercial data a public directory response must not reuse (**UNVERIFIED**; CabinetResource.php:28-34). **Mitigation: audience-specific resources (patient-facing variants that whitelist fields), `$hidden` on clinical models as defense-in-depth, and a rule that no mobile endpoint ever returns a bare model or web-controller payload.**

### 5.4 HIGH/MEDIUM — The appointment sync stream bypasses both the resource layer and the finer permission

`GET /api/v1/sync/appointments` requires only `appointments.viewAny` (AppointmentSyncController.php:23) yet echoes each event's stored `payload` verbatim (:49-50), which embeds the patient identity block — public_id, patient_number, name, DOB, gender, **phone, email** — plus `reason`, `reception_notes`, `cancellation_reason` (AppointmentSyncService.php:104-133, 154-163). A staffer denied `patients.view` can harvest patient contact PII through it; the payload field set is not curated at the response boundary, and the model comment promises imported events stay "visible on the outgoing cursor stream, so other clients of this cabinet — notably the mobile app — observe it normally" (AppointmentSyncEvent.php:51-56) — if a patient device ever consumes this stream as designed, it receives *other patients'* appointments and staff-only notes (**that last consequence UNVERIFIED**; the permission-bypass for staff tokens is verified). **Mitigation: filter the payload per audience before delivery (or gate the stream on `patients.view`) and never point a patient client at the cabinet-wide cursor stream.**

### 5.5 MEDIUM — Tenancy depends on the request-bound `auth()` user; queue jobs run unscoped

The scope resolves `auth()->user()` at query time, so queue workers, console commands, and any userless code path read and write across all tenants by design (the trait's own comment, BelongsToCabinet.php:47-51; tenant `cabinet_id` columns are nullable so unscoped creates silently succeed, 2026_08_06_000100:10-14, 53-56; `QUEUE_CONNECTION=database` is a real out-of-request worker, .env.example:150). Every existing background path hand-rolls re-scoping (`SyncMobileAppointments` passes explicit cabinet ids, app/Console/Commands/SyncMobileAppointments.php:43-44, 92-105; `MobileAppointmentSynchroniser` uses `withoutCabinetScope()` + explicit `where('cabinet_id', ...)`, MobileAppointmentSynchroniser.php:147-149, 192-196; `AppointmentImporter` takes an explicit `$cabinetId`, AppointmentImporter.php:62, 196-216). The mobile roadmap (notifications on every status change, push fan-out) is queue-heavy: every new job must copy this manual pattern or cross tenants — 32 models use the trait, including Patient, Appointment, Consultation, Prescription. **Mitigation: a job-context tenancy primitive (e.g. a required explicit tenant on every queued job, enforced by a base job class) rather than per-job discipline.**

### 5.6 MEDIUM — Clinical document files served on a 24-hour signed URL alone; empty OnlyOffice JWT default

`GET /app/clinical-documents/{document}/file` (routes/web.php:436) is outside every auth group; the controller checks only `hasValidRelativeSignature()` — no session, permission, or tenancy check (ClinicalDocumentController.php:72-87; the cabinet scope is inert for guests). URLs are minted with 24-hour validity, exclude the host from the HMAC, and are deliberately handed to the OnlyOffice server and the browser (ClinicalDocumentOnlyOffice.php:47, 79-83, 150-155; ConsultationHistoryController.php:179-184) — unauthenticated bearer capabilities to medical files that on mobile will be logged, cached, and shared into chat apps. Verified boundary: in the designed public deployment, `EnforceRemoteUploadBoundary` (bootstrap/app.php:49) 404s every remote/LAN request except `/health` and `/upload/{selector}` (RemoteUploadBoundary.php:104-123; LanUploadBoundary.php:63-81), making the route loopback-only — but that protection is config-conditional and **off in the default env** (.env.example: `MEDISMART_DESKTOP_SUPERVISED=false`, empty `MEDISMART_REMOTE_UPLOAD_URL`), so a plainly internet-exposed install has exactly the described PHI-streaming route. Related hardening: `ONLYOFFICE_JWT_SECRET` is empty by default and an empty secret disables callback token verification (**UNVERIFIED as an exploit; refuted as an unauthenticated-overwrite vector — see Appendix**; .env.example:78; ClinicalDocumentOnlyOffice.php:193-195). **Mitigation: shorten the signature TTL, require auth+cabinet for browser downloads (keep signature-only just for the OnlyOffice server fetch), and set the OnlyOffice JWT secret.**

### 5.7 MEDIUM — `DELETE /api/v1/appointments/{appointment}` authorizes `cancel` but soft-deletes, bypassing the status guard, unattributed

`destroy()` authorizes the `cancel` ability then calls `$appointment->delete()` (Api/V1/AppointmentController.php:168, 176) — intentional (no delete permission exists, PermissionName.php:13-18; tests assert the tombstone behavior, tests/Feature/Api/AppointmentMobileSyncTest.php:94-104), and the row survives as a soft delete with a sync tombstone (Appointment.php:125-143). But neither the row nor the tombstone records the acting user or a reason (no `deleted_by`; payload has no user field, AppointmentSyncService.php:104-133), unlike the cancel transition's `cancelled_by`/`cancellation_reason` (:206-214) — and `destroy()` performs **no status check**, so an Assistant holding only `appointments.cancel` (RolesAndPermissionsSeeder.php:47) can delete COMPLETED or NO_SHOW appointments the cancel path explicitly refuses (:206-208), silently removing billed visits from all default queries. Same-cabinet only (AppointmentPolicy.php:45-57). **Mitigation: record a deletion actor in the row/tombstone and block deletion of COMPLETED appointments.**

### 5.8 MEDIUM — Shipped defaults would put a public multi-tenant deployment on one single-writer SQLite file

The SQLite + database-driver session/cache/queue defaults (config/database.php:20, 35-44; .env.example:127-152) are deliberate for the offline single-PC desktop (ADR-001:80,114; ADR-003:37) — not what the mobile client talks to. The gap is on the hosted control plane, which ADR-003:56-57 designates as the mobile backend: the repo contains **no env template, deployment doc, or runtime guard** committing that deployment to the defined mysql/mariadb/pgsql connections, so a deploy from shipped defaults serves all cabinets from one SQLite file. Under public traffic the write pressure is Sanctum's per-request `last_used_at` updates, database-cache rate-limiter writes, database-queue polling, and the **unthrottled** authenticated booking/sync routes (routes/api.php:40-57 carry no throttle). Availability/degradation risk, conditional on deployment configuration. **Mitigation: pin the hosted deployment to a client-server DB + Redis (cache/queue/rate-limiter) and add throttles to authenticated API routes.**

### 5.9 MEDIUM — Privilege-bearing fields are mass-assignable across key models — **UNVERIFIED**

No `$guarded = []` or `Model::unguard()` exists anywhere (good), but the allow-lists themselves contain: `User` → `cabinet_id`, **`is_platform_admin`**, `approved_at` (User.php:47); `Cabinet` → `status`, `owner_user_id`, `license_id`, `activated_at` (Cabinet.php:28-36); `CabinetRolePermissionSet` → `permissions` (:18); `Appointment` → `status`, `created_by`, `cancelled_by`, idempotency hashes (:36-55); `Encounter` → `signed_by`, `content_hash`, `lock_version` (:25-39); `License` → `signed_certificate`, `status` (:23-37). `is_platform_admin` is a god-mode flag (bypasses tenancy, every policy, and gates Filament — BelongsToCabinet.php:24-26; PatientPolicy.php:47-49; User.php:160-169). Today no request path passes raw input into `User::create/update` (registration validates and maps explicitly, RegisterCabinetAction.php:45-54, 95-123), but any future mobile profile endpoint written as `$user->update($request->validated())` with permissive rules is instant privilege escalation. **Mitigation: strip privilege/tenancy fields from fillable lists and set them via explicit assignment only.**

### 5.10 LOW/MEDIUM — Unauthenticated desktop PIN login endpoint on the web surface

`POST /desktop/pin/login` (routes/web.php:117-119) is internet-reachable if the web app is exposed — but it is **not** a PIN-only brute-force surface: authentication requires an opaque 32-255-char device token (LoginWithDesktopPinRequest.php:19-26, HMAC-SHA256 hashed at rest, DesktopPinService.php:199-208) plus the 4-digit PIN, tokens bind only via the auth+verified enrollment route (routes/web.php:121-123), and beyond the throttle (FortifyServiceProvider.php:129-137) a DB-backed lockout locks each credential 15 minutes after 5 failures, surviving IP rotation, with timing-equalized dummy hashing and audit logging (DesktopPinService.php:23-25, 117-160, 240-244). Residual: a stolen device token permits ~480 online PIN guesses/day; token holders can DoS-lock a credential; token entropy is client-generated (server enforces only min:32 + charset). **Mitigation: server-issued device tokens and exponential lockout backoff.**

### 5.11 LOW — Public cabinet-join flow enables owner-email enumeration, seat-exhaustion DoS, and email squatting

GET/POST `/join` (routes/web.php:125-129) and `POST /api/v1/cabinets/join` (routes/api.php:31-32) create unauthenticated pending `User` rows guarded only by the `cabinet-join` limiter — keyed on the **attacker-controlled `email` input** + IP (AppServiceProvider.php:121-127), so rotating the email field yields a fresh bucket per request. Differing validation messages reveal which emails own joinable cabinets (JoinCabinetAction.php:31, 45-49, 69-73); `MAX_SEATS=3` with pending members reserving seats means 1-2 spam requests block legitimate staff joins until the owner rejects them (Cabinet.php:43, 160-171); accounts are created with `email_verified_at` forced to now() so the unique-email rule then blocks the address's real owner (JoinCabinetAction.php:81-84). Not an authentication bypass — pending members get no role and are held out by `EnsureCabinetIsActive` (EnsureCabinetIsActive.php:44-52). **Mitigation: key the limiter on IP alone (plus a global cap), uniform error messages, and require email verification before a seat is reserved.**

### 5.12 LOW — Registration stamps `email_verified_at` without verification; phone never verified

Both public registration paths force-fill `email_verified_at = now()` (RegisterCabinetAction.php:108-111; JoinCabinetAction.php:81-84) and accept regex-only free-text phone (RegisterCabinetAction.php:47) — and `User` does not implement `MustVerifyEmail`, so Laravel email verification is inert application-wide despite the enabled Fortify feature (User.php:5, 49; config/fortify.php:166). Consequence: password-reset links go to an unproven address (a registrant entering a third party's email hands that mailbox account recovery), and attackers can squat a victim clinic's email — limited only by a 5-per-10-min email+IP throttle. Impersonation does **not** yield an operating account (cabinets start PENDING; tokens and all surfaces are refused until platform activation, RegisterCabinetAction.php:97; AuthController.php:40-48). **Mitigation: real email (and later SMS) verification before account activation.**

### 5.13 LOW — Cross-tenant patient-ID existence oracle in appointment creation

`StoreAppointmentRequest` validates `patient_id` with an **unscoped** `exists:patients,id` rule (all tenants — Laravel's presence verifier bypasses global scopes; StoreAppointmentRequest.php:20), while the controller's cabinet-scoped `findOrFail` then 404s (Api/V1/AppointmentController.php:112). The 422-vs-404 differential (in fact, just whether the 422 body contains a `patient_id` error) lets any authenticated staff token probe whether a numeric patient ID exists in any other cabinet. Only existence leaks. **Mitigation: `Rule::exists('patients','id')->where('cabinet_id', $user->cabinet_id)`.**

### 5.14 LOW — `/health` exposes hub identity and app version unauthenticated

The public payload deliberately includes hub mode/protocol/hub_id/cabinet_id/hostname/TLS SPKI plus app name and version to any caller (HealthController.php:24-36; HubMode.php:151-160), aiding fingerprinting of internet-exposed instances; detailed checks are correctly local-only + shared-key gated (HealthController.php:18-22; the boundary middleware strips the key header on remote paths, EnforceRemoteUploadBoundary.php:84, 95). Documented in-code as an intentional pairing tradeoff. **Mitigation: drop the version/hub identity from the public branch or gate it behind the pairing handshake.**

### 5.15 LOW — Roles-permissions routes rely solely on controller-internal authorization

GET/PUT `/app/configuration/roles-permissions` and PUT `.../users/{user}` carry no route-level permission middleware (routes/web.php:289-295), unlike every sibling config group (:297, 310, 331, 340, 349, 374, 386, 405); protection is only `authorizedActor()`'s `abort_unless(canManage())` (RolePermissionController.php:253-262 — all three current methods call it, and `assignRole` can only ever grant ASSISTANT, CabinetRolePermissionAuthorizer.php:35-38). A future method added to these routes without the call would be exposed to any authenticated cabinet user. **Mitigation: wrap the block in a custom middleware expressing the owner-or-permission check.**

### 5.16 LOW — Stale CSRF exemption for a non-existent callback path

`validateCsrfTokens` excludes `app/configuration/models/*/callback` (bootstrap/app.php:53-56), but no such route exists anywhere (only `backup/google/callback`, a GET, routes/web.php:420-422) — the string has never matched a shipped route (git log -S). Any future state-changing route added under that path silently ships CSRF-exempt. **Mitigation: delete the entry.**

### 5.17 Additional risks flagged but not independently verified (**UNVERIFIED**, retained from draft research)

- **`CabinetSetting::current()` falls back to the earliest row in the table** outside a request context — a public/console code path would serve one tenant's clinic identity to everyone (CabinetSetting.php:55-71).
- **`Cabinet::MAX_SEATS = 3` counts every attached user** — patient or mobile-reception accounts attached via `cabinet_id` would exhaust seats; keeping them cabinet-less triggers risk 5.1 (Cabinet.php:43, 163-171).
- **No permission surface for a patient role** — cabinet-less token holders are evaluated against global Doctor/Assistant roles only; authorization for new mobile endpoints is undefined by default (User.php:61-82; RoleName.php:7-8).
- **Appointments remain addressable by sequential numeric id** alongside `public_id` (Appointment.php:221-228) — combined with 5.1, incremental-id walking; patient tokens need per-patient (not per-cabinet) authorization that no policy implements.
- **`Patient::scopeSearch` LIKE-matches partial phone/email/name/dossier number** (Patient.php:107-124) — reused behind a public "link account by phone" flow it becomes a patient-enumeration oracle.
- **Consultation rows mix clinical narrative with payment/debt data** (Consultation.php:20-44, 94-127) — a patient "my visits" endpoint must whitelist per audience.
- **Unbounded `->get()` lists in the web controllers that would template mobile endpoints** (ConsultationHistoryController.php:34-38, 70-94; ConsultationController.php:56,130,155,171,179; EncounterController.php:99,215; PaymentController.php:49,196,362,370; DashboardController.php:288,310) — API v1 itself paginates correctly.
- **No DB-level overlap protection on schedule ranges** — only exactly-identical rows are blocked (2026_07_28_230100:41); a mobile hours editor must validate overlaps or slots double-book.
- **Free-text specialty + locked typo codes leak unnormalized data via the API** (MedicalSpecialtyCatalog.php:88-92; DoctorProfile.php:64-72; ScheduleController.php:66).
- **Cabinets with null `wilaya_code` silently vanish from a wilaya-first search**; no post-registration edit path (2026_08_06_000400:70; CabinetsTable.php:50-52).
- **`APP_DEBUG=true` / `LOG_LEVEL=debug` are the committed defaults** (.env.example:4,121) — a hosted clone leaks stack traces; Telescope itself is safely triple-gated (AppServiceProvider.php:42-47; TelescopeServiceProvider.php:70-74; config/telescope.php:20).
- **Patch artifacts `AppointmentController.php.orig`/`.php.rej` committed in the controllers tree** (app/Http/Controllers/Appointments/) — stale authorization code in the autoload path; delete.

## Appendix — claims investigated and refuted

Claims raised during the audit that verification **refuted** (kept here so they are not re-raised):

1. **"Mobile 'sync' is clinician-triggered republish only, not a client API."** Refuted — the backend does expose a client-callable sync API: `POST /api/v1/auth/token` mints bearer tokens (routes/api.php:25-26; AuthController.php:23-56), and behind `auth:sanctum` + `cabinet.active.api` sit the cursor stream `GET /api/v1/sync/appointments`, `POST .../ack`, and batched `POST .../push` with version-based conflict handling (routes/api.php:47-51; AppointmentSyncController.php:21-167). The push docblock explicitly names the mobile app as a consumer (:100-104). The clinician-triggered web routes (routes/web.php:197-202) are the desktop-side *client* of this same API (MobileSyncClient.php:39-70). Feature tests cover it (tests/Feature/Api/AppointmentMobileSyncTest.php, AppointmentSyncPushTest.php, AuthTokenTest.php).

2. **"The login response leaks the full permission matrix and license details to unauthorized callers."** Refuted as a present-day risk — every recipient of `UserResource` is an approved staff member of the cabinet whose data is returned (token issuance gates on cabinet status/license/approval, AuthController.php:41-48; CabinetAccessService.php:37-74); the data is the caller's own (`$user->load('cabinet.license')` is tenancy-scoped), the permission list is standard client-side UI gating with server-side authorization still enforced, and the license block is a curated summary (plan/status/expiry, no secrets — CabinetResource.php:28-34) shown intentionally because login is denied on expiry. A slimmer resource is warranted only if a patient-facing client is added (risk §5.3).

3. **"The OnlyOffice document-save callback accepts unauthenticated writes when jwt_secret is empty."** Refuted as an exploit — the code facts are real (CSRF-exempt route, bootstrap/app.php:53-56; empty-secret shortcut, ClinicalDocumentOnlyOffice.php:193-195; empty default, config/onlyoffice.php:7, .env.example:78), but three layers block the claimed public overwrite: the remote-upload boundary 404s the callback for any public/tunnel request (EnforceRemoteUploadBoundary.php:76-80; RemoteUploadBoundary.php:104-123); the callback first requires an unforgeable APP_KEY-signed URL (ClinicalDocumentOnlyOffice.php:89); and stored content is fetched only from the trusted loopback OnlyOffice origin with redirects disabled (:105-113). Setting the secret remains valid defense-in-depth (risk §5.6).

4. **"Unauthenticated cabinet registration passes an unvalidated request bag to the action."** Refuted — `CabinetController@register` does pass `$request->all()` (CabinetController.php:21), but the action immediately runs `Validator::make(...)->validate()` with required rules on every field (RegisterCabinetAction.php:45-54; unique email, bounded wilaya, `Password::default()` = min 12 + mixed/numbers/symbols in production, AppServiceProvider.php:248-255), `validate()` returns only rule-covered keys, and `provision()` maps to explicit columns (:95-123) — no mass assignment, no reachable privilege field, and the created cabinet is an inert PENDING tenant (no token until platform activation). Residual notes (spam via email-keyed throttle, stamped `email_verified_at`) are carried as risks §5.11-5.12.

5. **"Public upload endpoints accept any file type at the HTTP validation layer."** Refuted as a risk — the HTTP rule is only `required|file` (PublicUploadController.php:99-102), but `UploadDocumentService::receive` runs synchronously in the same request, fail-closed: size caps before storage, a pdf/jpeg/png-only MIME whitelist intersected with the session's list, extension/MIME agreement, total-byte caps under DB lock, post-store finfo re-sniff + sha256 + PDF magic/`%%EOF` + `getimagesize` checks with deletion on failure (UploadDocumentService.php:23-27, 72-100, 173-185, 229-235, 472-536); sessions are staff-created with a config-clamped whitelist (QrUploadService.php:88-100; config/medismart.php:89-93); files land in a never-web-served private quarantine requiring authenticated human review (config/filesystems.php:33-41; routes/web.php:396-401).

Claims that survived but were **materially corrected** during verification (the corrected versions appear in the body):

- "Token issuance is inseparable from cabinet licensing gates" — inverted: cabinet-less accounts bypass the gate entirely (CabinetAccessService.php:46-48) → risk §5.1.
- "No hosted multi-tenant API exists" — wrong: the hosted control plane is this codebase with `HUB_MODE=false` serving `/api/v1` for all cabinets (config/hub.php:23-26); what is missing is the public directory/booking layer → gap §4.4.
- "GET /api/v1/schedule returns one range per weekday" — wrong: it returns one entry per row (ScheduleController.php:34-42); the *editor* is what enforces one range per weekday → §3a(c), gap §4.8.
- "Any cabinet-less token reads and writes across all cabinets" — overstated: policies 403 role-less accounts; the unscoped reads require a global role or platform admin → risk §5.1.
- "/desktop/pin/login is a low-entropy PIN brute-force surface" — wrong: a ~190-bit device token is required alongside the PIN, with persistent lockout → risk §5.10.
- "DELETE appointments loses the audit trail" — overstated: soft delete + sync tombstone survive; what is lost is actor attribution, and the status guard is bypassed → risk §5.7.
- "Clinical-document signed URLs are an open public leak" — deployment-conditional: the remote-upload boundary blocks the route when enforcement is on; default env leaves it off → risk §5.6.
- "SQLite behind the public API" — the desktop's SQLite is by design (ADR-001/ADR-003); the unresolved piece is the absent hosted-deployment DB commitment → risk §5.8.
- "Fortify email verification is bypassed by the stamped `email_verified_at`" — the control never existed: `User` does not implement `MustVerifyEmail` (User.php:5,49) → risk §5.12.
- "Cabinet-join enables cabinet-name enumeration" — it is **owner-email** enumeration, and the throttle is bypassable by rotating the attacker-controlled email key → risk §5.11.

