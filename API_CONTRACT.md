# Drclick Mobile API — Contract (Phase 1)

Audience: the React Native (patient + staff + platform-admin) mobile developer. This document is
the contract for every endpoint the mobile app uses. All examples below are
**real responses captured from the running backend** (test run of 2026-09-01,
app timezone `Africa/Algiers`, i.e. `+01:00`) — only tokens, UUIDs and ids are
illustrative values from that run.

- **Base URL**: `https://<host>/api/v1`
- **Format**: JSON only. Always send `Accept: application/json` and
  `Content-Type: application/json`.
- **Auth**: `Authorization: Bearer <token>` (Laravel Sanctum personal access
  tokens).
- **Language**: every human-readable `message` is in French. Machine handling
  must rely on HTTP status + the `reason` code, never on message text.
- **Dates**: date-times are ISO-8601 with the clinic offset
  (`2026-09-15T09:00:00+01:00`); plain dates are `Y-m-d`; times of day are
  `HH:MM` (24h). Send booking times as full ISO-8601 strings — a UTC offset is
  accepted and normalised server-side to the clinic timezone.

---

## 1. Tokens & authentication semantics

| Property | Mobile tokens (`POST /auth/register`, `POST /auth/login`) | Desktop/legacy token (`POST /auth/token`) |
|---|---|---|
| Lifetime | **90 days**, then the token is invalid (client must log in again) | No expiry |
| Abilities | `["mobile"]` | default (`*`) |
| Named after | `device_name` request field (defaults to `"mobile"`) | `device_name` (required) |
| Revocation | `POST /auth/logout` deletes **the token used on that request** only | same |

Notes:

- Registration creates **patient accounts only**. Any attempt to send
  `role`, `roles`, `role_id`, `is_platform_admin`, `cabinet_id` or
  `approved_at` is rejected with 422 (see §8.2).
- Staff (doctor/reception) may also log in through `POST /auth/login`; they
  pass the same cabinet-eligibility gate as the desktop API and receive a 403
  with a `reason` when their cabinet is pending/suspended/expired (§6).
- There is no refresh endpoint: on 401 send the user back to login.
- `POST /auth/logout` answers `200 {"message": "Déconnexion réussie."}` and
  revokes the presented token.

## 2. Roles

`role` is returned by register/login and by `GET /my/profile`.

| Mobile role | Backing implementation | What the token can reach |
|---|---|---|
| `patient` | Spatie role `Patient`, `cabinet_id = null`, zero staff permissions | Own profile, own family members, appointments **they booked** (self or family), own prescriptions, own notifications, public discovery. Nothing cabinet-scoped. |
| `doctor` | role `Doctor` + member of one cabinet | Own cabinet only: staff-mobile endpoints + legacy staff endpoints. |
| `reception` | role `Assistant` + member of one cabinet | Own cabinet only (one cabinet = one doctor by design). Permission-gated writes may 403. |
| `admin` (superadmin) | `is_platform_admin = true`, `cabinet_id = null` | The whole platform back office (§8.7): every clinic on the platform, cross-tenant. **Not** a clinic account — staff-mobile endpoints still reject an admin token with `cabinet_membership_required` and the patient endpoints with `patient_role_required`. |

> **Superadmins are provisioned only by the console command `php artisan platform:provision-superadmin`.** They can never be created, promoted or listed through the API: `is_platform_admin` (like `role`, `roles`, `cabinet_id` and `approved_at`) is a **prohibited** request field everywhere, registration mints patients only, and no response ever serialises the flag.

Hard boundaries (enforced server-side, verified by tests):

- A **patient token** on any cabinet endpoint (`/appointments`, `/patients`,
  `/schedule`, `/sync/*`, `/mobile/*`) → `403 {reason: "patient_token_forbidden"}`.
- A **staff token** on any `mobile.patient` endpoint (`/my/*`,
  `/family-members*`) → `403 {reason: "patient_role_required"}`.
- A **platform-admin or cabinet-less token** on `/mobile/*` staff endpoints →
  `403 {reason: "cabinet_membership_required"}`.
- A **non-superadmin token** (patient, doctor **or** reception) on any
  `/admin/*` endpoint → `403 {reason: "platform_admin_required"}` — never a
  404 that hides whether the clinic exists, never a partial 200.
- Staff of cabinet A can never see or act on cabinet B data (404 on lookups).
- A patient can never see another patient's bookings (404), family members, or
  respond to link requests not addressed to them (403).

## 3. Pagination envelope

Every list endpoint is paginated and wrapped in the standard Laravel envelope
(`data` + `links` + `meta`). Mobile lists default to **15 per page** and cap
`per_page` at **50** (`/mobile/appointments/today` defaults to 50;
`/notifications` is fixed at 20; legacy staff lists accept up to 100).
Use `?page=N&per_page=M`; extra query filters are kept in the links.

```json
{
  "data": [ "…resource objects…" ],
  "links": {
    "first": "https://<host>/api/v1/doctors?wilaya_code=16&page=1",
    "last": "https://<host>/api/v1/doctors?wilaya_code=16&page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "links": [
      { "url": null, "label": "« Précédent", "page": null, "active": false },
      { "url": "https://<host>/api/v1/doctors?wilaya_code=16&page=1", "label": "1", "page": 1, "active": true },
      { "url": null, "label": "Suivant »", "page": null, "active": false }
    ],
    "path": "https://<host>/api/v1/doctors",
    "per_page": 15,
    "to": 1,
    "total": 1
  }
}
```

Single resources are wrapped as `{"data": {…}}` — **except** the auth
endpoints (`/auth/register`, `/auth/login`, `/auth/token`) which return a flat
`{token, role, user}` object, and the availability endpoints which return
their own flat shapes (§8.1).

## 4. Appointment statuses

`status` values (string enum):

| Value | Meaning | Blocks the slot? |
|---|---|---|
| `scheduled` | Booked, not yet confirmed by the clinic | yes |
| `confirmed` | Confirmed by the clinic | yes |
| `checked_in` | Patient arrived at the desk | yes |
| `in_progress` | Consultation running | yes |
| `completed` | Done | no |
| `cancelled` | Cancelled (by patient or clinic) | no |
| `no_show` | Patient did not show up | no |

> **Mobile mapping note — `scheduled` ≡ "pending"**: a freshly booked mobile
> appointment is stored as `scheduled`. In the patient UI, display
> `scheduled` as **"en attente de confirmation" (pending)** and `confirmed`
> as confirmed. There is no separate `pending` value on the wire.

Transitions available to the mobile app:

- Patient cancel: allowed from `scheduled`/`confirmed`, **only more than the
  cancel cutoff before start** (config `patient_cancel_cutoff_hours`,
  default **2 h**), else `422 {reason: "cancel_cutoff_passed"}`.
- Staff decline (→ `cancelled`): from `scheduled`/`confirmed`, reason required.
- Staff no-show: from `scheduled`/`confirmed`/`checked_in`.
- Staff reschedule: from `scheduled`/`confirmed`; **keeps** the current status.
- Legacy `PATCH /appointments/{id}`: `status` accepts only
  `confirmed` (from `scheduled`), `checked_in` (from `scheduled`/`confirmed`),
  `cancelled` (from any non-terminal); other targets → 422.

## 5. Rate limits

429 responses use Laravel's default (`{"message": "Too Many Attempts."}` plus
`Retry-After` header).

| Limiter | Applies to | Limit |
|---|---|---|
| `mobile-register` | `POST /auth/register` | 5/hour per IP **and** 3/hour per phone number |
| `mobile-login` | `POST /auth/login` | 10/min per identifier+IP |
| `mobile-password-forgot` | `POST /auth/password/forgot` | 5/hour per identifier **and** 20/hour per IP |
| `mobile-password-reset` | `POST /auth/password/reset` | 10/min per identifier+IP (the code itself dies after 5 wrong tries) |
| `mobile-public` | all public reference/discovery/availability GETs | 60/min per IP |
| `login` | `POST /auth/token` (legacy) | 5/min per email+IP |
| `mobile-admin` | every `/admin/*` endpoint (§8.7) | 60/min per admin account + IP |
| (none) | all other authenticated endpoints | no throttle middleware in Phase 1 |

## 6. Error envelope

| Status | Shape | When |
|---|---|---|
| 401 | `{"message": "Unauthenticated."}` | Missing/invalid/expired token |
| 403 (domain) | `{"message": <fr>, "reason": <code>}` | Ownership/role refusals (`family_member_not_usable`, `not_owner`, `patient_role_required`) |
| 403 (gate) | `{"message": <fr>, "reason": <code>, "status": <state>}` | Cabinet gate & role gate refusals: `patient_token_forbidden` (status `forbidden`), `cabinet_membership_required` (status `forbidden`), `platform_admin_required` (status `forbidden`, every `/admin/*` route), and cabinet eligibility codes `cabinet_pending`/`cabinet_suspended`/`license_expired`/`license_inactive`/`awaiting_approval` (status `pending`/`suspended`/`expired`/`inactive`/`awaiting_approval`). Same shape on a denied staff login. |
| 403 (policy) | `{"message": "This action is unauthorized."}` | Spatie/policy denial without a domain code (e.g. assistant lacking a permission) |
| 404 | `{"message": <text>}` | Not found / not listed / other tenant. Domain 404s carry French messages (`"Cabinet introuvable."`, `"Médecin introuvable."`); model-binding 404s carry Laravel's default text. Treat every 404 as "does not exist for this account". |
| 409 | `{"message": <fr>, "reason": <code>}` | `slot_unavailable`, `already_linked`, `link_not_pending`, `idempotency_key_reused`; admin lifecycle/seat codes `already_active`, `already_suspended`, `cabinet_not_active`, `seat_limit_reached` (§8.7); `sync_version_conflict` additionally carries `public_id` and `current_version` |
| 422 (validation) | `{"message": <first error>, "errors": {field: [messages]}}` | Laravel validation (field messages in French) |
| 422 (domain) | `{"message": <fr>, "reason": <code>}` | `cancel_cutoff_passed`, `member_has_appointments` |
| 429 | `{"message": "Too Many Attempts."}` | Rate limit hit |

Full `reason` code list: `patient_token_forbidden`, `patient_role_required`,
`cabinet_membership_required`, `family_member_not_usable`, `not_owner`,
`cabinet_pending`, `cabinet_suspended`, `license_expired`, `license_inactive`,
`awaiting_approval`, `slot_unavailable`, `already_linked`, `link_not_pending`,
`sync_version_conflict`, `idempotency_key_reused`, `cancel_cutoff_passed`,
`member_has_appointments`, `platform_admin_required`, `already_active`,
`already_suspended`, `cabinet_not_active`, `seat_limit_reached`.

Examples (captured):

```json
// 403 — patient token on GET /api/v1/appointments
{
  "message": "Ce compte patient ne peut pas accéder à l'espace du cabinet.",
  "reason": "patient_token_forbidden",
  "status": "forbidden"
}
```

```json
// 409 — booking an occupied slot
{
  "message": "Ce créneau n'est plus disponible. Veuillez en choisir un autre.",
  "reason": "slot_unavailable"
}
```

---

## 7. Endpoint index

| # | Method & path | Auth |
|---|---|---|
| 1 | `GET /wilayas` | none |
| 2 | `GET /wilayas/{code}/baladiyas` | none |
| 3 | `GET /specialties` | none |
| 3a | `GET /facility-types` | none |
| 4 | `GET /doctors` | none |
| 5 | `GET /clinics/{cabinetId}` | none |
| 6 | `GET /doctors/{doctorId}/availability/month` | none |
| 7 | `GET /doctors/{doctorId}/availability/day` | none |
| 8 | `POST /auth/register` | none |
| 9 | `POST /auth/login` | none |
| 10 | `POST /auth/logout` | token |
| 11 | `POST /devices` | token (any role) |
| 12 | `DELETE /devices` | token (any role) |
| 13 | `GET /notifications` | token (any role) |
| 14 | `POST /notifications/read` | token (any role) |
| 15 | `GET /my/profile` | token + patient |
| 16 | `PATCH /my/profile` | token + patient |
| 17 | `GET /my/appointments` | token + patient |
| 18 | `POST /my/appointments` | token + patient |
| 19 | `GET /my/appointments/{publicId}` | token + patient |
| 20 | `PATCH /my/appointments/{publicId}/cancel` | token + patient |
| 21 | `GET /my/prescriptions` | token + patient |
| 22 | `GET /family-members` | token + patient |
| 23 | `POST /family-members` | token + patient |
| 24 | `POST /family-members/link` | token + patient |
| 25 | `POST /family-members/{id}/respond` | token + patient (the **linked** account) |
| 26 | `DELETE /family-members/{id}` | token + patient (owner) |
| 27 | `GET /mobile/appointments/today` | token + staff (cabinet member) |
| 28 | `PATCH /mobile/appointments/{id}/decline` | token + staff (policy `cancel`) |
| 29 | `PATCH /mobile/appointments/{id}/reschedule` | token + staff (policy `update`) |
| 30 | `PATCH /mobile/appointments/{id}/no-show` | token + staff (policy `update`) |
| 31 | `POST /mobile/patients` | token + staff + permission `patients.create` |
| 32 | `PUT /mobile/schedule` | token + staff + permission `appointments.configure` |
| 33 | `POST /mobile/schedule/time-off` | token + staff + permission `appointments.configure` |
| 34 | `DELETE /mobile/schedule/time-off/{id}` | token + staff + permission `appointments.configure` |
| 35 | `GET /mobile/clinic-profile` | token + staff |
| 36 | `PUT /mobile/clinic-profile` | token + staff + permission `configuration.branding.manage` |
| 37 | `POST /auth/token` (legacy) | none |
| 38 | `GET /me` (legacy) | token |
| 39 | `GET /appointments` (legacy) | token + staff |
| 40 | `POST /appointments` (legacy) | token + staff |
| 41 | `GET /appointments/{id}` (legacy) | token + staff |
| 42 | `PATCH /appointments/{id}` (legacy) | token + staff |
| 43 | `DELETE /appointments/{id}` (legacy) | token + staff (policy `cancel`) |
| 44 | `GET /schedule` (legacy) | token + staff |
| 45 | `GET /patients` (legacy) | token + staff |
| 46 | `GET /patients/{id}` (legacy) | token + staff |
| 47 | `GET /admin/overview` | token + superadmin |
| 48 | `GET /admin/cabinets` | token + superadmin |
| 49 | `POST /admin/cabinets` | token + superadmin |
| 50 | `GET /admin/cabinets/{cabinet}` | token + superadmin |
| 51 | `POST /admin/cabinets/{cabinet}/activate` | token + superadmin |
| 52 | `POST /admin/cabinets/{cabinet}/suspend` | token + superadmin |
| 53 | `POST /admin/cabinets/{cabinet}/staff` | token + superadmin |
| 54 | `PATCH /admin/cabinets/{cabinet}/listing` | token + superadmin |

"token + staff" = `auth:sanctum` + active-cabinet gate (`patient_token_forbidden`
for patients; eligibility codes for blocked cabinets). The `/mobile/*` group
additionally rejects platform admins and cabinet-less accounts
(`cabinet_membership_required`). "token + superadmin" = `auth:sanctum` +
`is_platform_admin = true` + the `mobile-admin` throttle; every other role
gets `403 platform_admin_required` (§8.7).

**Deliberately not documented here** (they exist under `/api/v1` but are not
mobile-app surface): `POST /cabinets/register` and `POST /cabinets/join`
(clinic-owner and staff onboarding for the web/desktop app), and
`GET /sync/appointments`, `POST /sync/appointments/ack`,
`POST /sync/appointments/push` (the local-first desktop replication stream).
The mobile client must not call any of these.

---

## 8. Endpoints in detail

### 8.1 Public reference & discovery (no auth, throttle `mobile-public`)

#### GET /wilayas

Cached 24 h server-side. Ordered by code.

```json
{
  "data": [
    { "code": 16, "name_fr": "Alger", "name_ar": "الجزائر" }
  ]
}
```

#### GET /wilayas/{code}/baladiyas

`{code}` is the wilaya code (integer 1..58). Ordered by `name_fr`.
Unknown wilaya → `404 {"message": "Wilaya introuvable."}`.

```json
{
  "data": [
    { "id": 2, "wilaya_code": 16, "name_fr": "Bab El Oued", "name_ar": "باب الوادي" },
    { "id": 1, "wilaya_code": 16, "name_fr": "Hydra", "name_ar": "حيدرة" }
  ]
}
```

#### GET /specialties

The full bilingual catalogue (21 entries). Use `code` in the doctors filter.

```json
{
  "data": [
    { "code": "general_medicine", "label_fr": "Médecine générale", "label_ar": "الطب العام" },
    { "code": "cardiology", "label_fr": "Cardiologie", "label_ar": "أمراض القلب" },
    { "code": "pediatrics", "label_fr": "Pédiatrie", "label_ar": "طب الأطفال" }
  ]
}
```

(Also available: `family_medicine`, `internal_medicine`,
`occupational_medicine`, `anesthesiology`, `general_surgery`, `dermatology`,
`endocrinology`, `gastroenterology`, `obstetrics_gynecology`, `nephrology`,
`neurology`, `ophthalmology`, `otorhinolaryngology`, `pulmonology`,
`psychiatry`, `radiology`, `rheumatology`, `urology`.)

#### GET /facility-types

The kinds of place the platform admin has switched on, in tab order — the
search tabs to show. Never empty (the admin can't switch off the last one).
A switched-off type is invisible everywhere: `GET /doctors` never returns its
cabinets (filtered or not), and its clinic page, availability and booking
answer 404. Admins manage it with `GET /admin/facility-types` and
`PATCH /admin/facility-types/{type}` (`{ "is_active": bool }`).

```json
{
  "data": [
    { "value": "doctor", "label_fr": "Cabinet médical", "label_ar": "عيادة طبيب" },
    { "value": "clinic", "label_fr": "Clinique", "label_ar": "عيادة متعددة الخدمات" },
    { "value": "radiology", "label_fr": "Centre d'imagerie", "label_ar": "مركز أشعة" }
  ]
}
```

#### GET /doctors

Public directory. Only doctors that are **active**, in an **active** cabinet,
with a **listed** public profile ever appear. Query params (all optional):
`wilaya_code` (1..58), `baladiya_id`, `specialty` (catalogue code), `q`
(matches doctor name or clinic name), `page`, `per_page` (1..50, default 15).

`GET /doctors?wilaya_code=16` →

```json
{
  "data": [
    {
      "id": 1,
      "name": "Dr Karim Boudjema",
      "specialty": {
        "code": "cardiology",
        "label_fr": "Cardiologie",
        "label_ar": "أمراض القلب"
      },
      "clinic": {
        "id": 1,
        "name": "Cabinet El Amel",
        "wilaya": { "code": 16, "name_fr": "Alger", "name_ar": "الجزائر" },
        "baladiya": { "id": 1, "name_fr": "Hydra", "name_ar": "حيدرة" },
        "address": "12 Rue Didouche Mourad, Alger-Centre"
      }
    }
  ],
  "links": { "first": "…", "last": "…", "prev": null, "next": null },
  "meta": { "current_page": 1, "from": 1, "last_page": 1, "links": ["…"], "path": "…/doctors", "per_page": 15, "to": 1, "total": 1 }
}
```

(`specialty`, `wilaya`, `baladiya` and `address` can each be `null`.)
`doctors[].id` is the **doctor id** used for availability and booking;
`clinic.id` is the **cabinet id** used for `GET /clinics/{id}`.

#### GET /clinics/{cabinetId}

Detail page. `404 {"message": "Cabinet introuvable."}` unless the cabinet is
active **and** its public profile is listed.

```json
{
  "data": {
    "id": 1,
    "name": "Cabinet El Amel",
    "about": "Cabinet de cardiologie au centre d'Alger. Consultations sur rendez-vous.",
    "address": "12 Rue Didouche Mourad, Alger-Centre",
    "wilaya": { "code": 16, "name_fr": "Alger", "name_ar": "الجزائر" },
    "baladiya": { "id": 1, "name_fr": "Hydra", "name_ar": "حيدرة" },
    "phones": ["0550203040"],
    "latitude": 36.7525,
    "longitude": 3.042,
    "photos": [],
    "specialties": [
      { "code": "cardiology", "label_fr": "Cardiologie", "label_ar": "أمراض القلب" }
    ],
    "doctor": {
      "id": 1,
      "name": "Dr Karim Boudjema",
      "specialty": { "code": "cardiology", "label_fr": "Cardiologie", "label_ar": "أمراض القلب" }
    },
    "working_hours": [
      { "weekday": 1, "is_closed": true, "ranges": [] },
      {
        "weekday": 2,
        "is_closed": false,
        "ranges": [
          { "starts_at": "09:00", "ends_at": "12:00", "period": "morning", "slot_duration": 30 }
        ]
      },
      { "weekday": 3, "is_closed": true, "ranges": [] },
      { "weekday": 4, "is_closed": true, "ranges": [] },
      { "weekday": 5, "is_closed": true, "ranges": [] },
      { "weekday": 6, "is_closed": true, "ranges": [] },
      { "weekday": 7, "is_closed": true, "ranges": [] }
    ]
  }
}
```

`working_hours` always contains all 7 ISO weekdays (**1 = Monday … 7 =
Sunday**). A day can hold up to 3 ranges; `period` is `morning` when the range
starts before 12:00, else `evening`. `about`, `address`, `wilaya`, `baladiya`,
`latitude`, `longitude` and `doctor` are nullable; `phones`/`photos` default
to `[]`.

#### GET /doctors/{doctorId}/availability/month?year=2026&month=9

`year` (2000..2100) and `month` (1..12) are required. `404
{"message": "Médecin introuvable."}` unless the doctor is visible in the
directory (same rule as discovery).

```json
{
  "year": 2026,
  "month": 9,
  "is_open_month": true,
  "days": [
    {
      "date": "2026-09-01",
      "day": 1,
      "weekday": 2,
      "is_open_month": true,
      "is_working_day": true,
      "is_day_off": false,
      "is_past": false,
      "available_count": 3,
      "bookable": true
    },
    {
      "date": "2026-09-02",
      "day": 2,
      "weekday": 3,
      "is_open_month": true,
      "is_working_day": false,
      "is_day_off": false,
      "is_past": false,
      "available_count": 0,
      "bookable": false
    }
    // … one entry per calendar day of the month, same shape …
  ]
}
```

When the month is not opened by the doctor, `is_open_month` is `false` and
every day has `bookable: false`. Use `bookable` to enable calendar days.

#### GET /doctors/{doctorId}/availability/day?date=2026-09-15

`date` (`Y-m-d`) required. Same 404 rule as the month view.

```json
{
  "date": "2026-09-15",
  "reason": null,
  "slots": [
    {
      "starts_at": "2026-09-15T09:00:00+01:00",
      "ends_at": "2026-09-15T09:30:00+01:00",
      "label": "09:00",
      "end_label": "09:30",
      "available": true,
      "reason": null
    },
    {
      "starts_at": "2026-09-15T09:30:00+01:00",
      "ends_at": "2026-09-15T10:00:00+01:00",
      "label": "09:30",
      "end_label": "10:00",
      "available": true,
      "reason": null
    }
    // … one entry per slot of the day …
  ]
}
```

Top-level `reason` is `null` when slots exist, else one of `month_closed`,
`not_working_day`, `day_off` (with `slots: []`). Per-slot `reason` is `null`
when available, else `booked`, `time_off` or `past`. Book by POSTing the
slot's exact `starts_at`. (Unlike the staff calendar, this payload never
includes other patients' appointments.)

### 8.2 Auth

#### POST /auth/register — throttle `mobile-register`

Creates a **patient** account + demographic profile and returns a signed-in
session. `phone` must match `^0[567][0-9]{8}$` (Algerian mobile) and be
unique; `email` is optional but unique when given.

Request:

```json
{
  "phone": "0550123456",
  "password": "MotDePasse2026",
  "first_name": "Amine",
  "last_name": "Benali",
  "gender": "male",
  "date_of_birth": "1992-04-17",
  "wilaya_code": 16,
  "baladiya_id": 1,
  "email": "amine.benali@example.dz",
  "terms_accepted": true,
  "device_name": "Samsung Galaxy S24"
}
```

Field rules: `password` min 8 (no confirmation field); `gender` in
`male|female`; `date_of_birth` after 1900-01-01 and before today;
`wilaya_code` 1..58 required; `baladiya_id` optional but must belong to the
given wilaya; `terms_accepted` must be true. `place_of_birth` is **not**
accepted here (set it later via `PATCH /my/profile`). The fields `role`,
`roles`, `role_id`, `is_platform_admin`, `cabinet_id`, `approved_at` are
**prohibited**:

```json
// 422 when a prohibited field is present
{
  "message": "Le champ fonction est interdit. (and 1 more error)",
  "errors": {
    "role": ["Le champ fonction est interdit."],
    "cabinet_id": ["Le champ cabinet id est interdit."]
  }
}
```

Response `201`:

```json
{
  "token": "1|sqFiZ6Pprl9ZydGR2XUxsFRX8rcuHSZRYuikMVPr2625fb2c",
  "role": "patient",
  "user": {
    "id": 2,
    "phone": "0550123456",
    "email": "amine.benali@example.dz",
    "role": "patient",
    "first_name": "Amine",
    "last_name": "Benali",
    "gender": "male",
    "date_of_birth": "1992-04-17",
    "place_of_birth": null,
    "wilaya": { "code": 16, "name_fr": "Alger", "name_ar": "الجزائر" },
    "baladiya": { "id": 1, "name_fr": "Hydra", "name_ar": "حيدرة" }
  }
}
```

#### POST /auth/login — throttle `mobile-login`

`identifier` is a phone number **or** an email (auto-detected). Works for
patients and cabinet staff.

```json
{
  "identifier": "0550123456",
  "password": "MotDePasse2026",
  "device_name": "Samsung Galaxy S24"
}
```

Patient response `200` — same `{token, role, user}` shape as register (the
`user` object is the profile resource above).

Staff response `200` — `user` is the staff `UserResource`:

```json
{
  "token": "3|LcwEnHN60smU7Q6Pi8kFT5bTKThZFzNc76CU87Rbc2455c27",
  "role": "doctor",
  "user": {
    "id": 1,
    "name": "Karim Boudjema",
    "email": "k.boudjema@cabinet-elamel.dz",
    "is_platform_admin": false,
    "approved": true,
    "cabinet": {
      "id": 1,
      "name": "Cabinet El Amel",
      "status": "active",
      "specialization": null,
      "wilaya": { "code": 16, "name": "Alger" },
      "license": null
    },
    "roles": ["Doctor"],
    "permissions": ["appointments.cancel", "appointments.check-in", "appointments.configure", "…"]
  }
}
```

(`role` is `doctor` for the Doctor role, `reception` for the Assistant role,
`admin` for platform admins. `is_platform_admin` may be `null` on legacy
accounts — treat `null` as `false`. `cabinet.license` is `null` for
self-hosted cabinets; when present:
`{plan, plan_label, status, status_label, expires_at}`.)

Failures:

- Wrong identifier or password → `422` with `errors.identifier`
  (`"Ces identifiants ne correspondent à aucun compte."`).
- Staff whose cabinet is blocked → `403`:

```json
{
  "message": "Votre cabinet est actuellement suspendu. Contactez le support Drclick.",
  "reason": "cabinet_suspended",
  "status": "suspended"
}
```

(`reason`/`status` pairs: `cabinet_pending`/`pending`,
`cabinet_suspended`/`suspended`, `license_expired`/`expired`,
`license_inactive`/`inactive`, `awaiting_approval`/`awaiting_approval`.)

#### POST /auth/logout — any token

No body. Revokes the token used on the request.

```json
{ "message": "Déconnexion réussie." }
```

#### POST /auth/password/forgot — throttle `mobile-password-forgot`

Body `{ "identifier": "0550123456" | "name@mail.dz" }` (phone matched as typed
and in its canonical `0XXXXXXXXX` form). When it matches an account **that has
an e-mail**, a six-digit code valid 15 minutes is e-mailed (FR + AR); a second
request within 60 s keeps the code already sent. There is no SMS gateway: an
account without an e-mail gets nothing, and the app tells the person to ask
their clinic. The answer is always the same, so it never reveals whether an
account exists:

```json
{ "message": "Si un compte correspond, …", "channel": "email", "expires_in_minutes": 15 }
```

#### POST /auth/password/reset — throttle `mobile-password-reset`

Body `{ "identifier", "code": "123456", "password": "min 8" }`. On success the
password changes, the code is spent and **every token of the account is
revoked**; sign in again with `POST /auth/login`.

```json
{ "message": "Votre mot de passe a été modifié. Vous pouvez vous connecter." }
```

`422` with `reason` `reset_code_invalid` (wrong code; counts as a try) or
`reset_code_expired` (expired, already used, never requested, or the 5th wrong
try), plus `errors.code`. A too-short password is a plain `422` on `password`.

### 8.3 Devices & notifications (any authenticated role)

#### POST /devices

Registers a push token for the current account. Re-posting an existing token
**claims it** for the current account (account switch on the same phone) and
refreshes `last_seen_at`.

Request: `{ "token": "ExponentPushToken[qF8rT2xL0aH3nB5cD7eF9g]", "platform": "android" }`
(`token` ≤ 255 chars required, `platform` optional `ios|android`).

Response: `201 {"message": "Appareil enregistré."}` on first registration,
`200` with the same body afterwards.

#### DELETE /devices

Body: `{ "token": "ExponentPushToken[qF8rT2xL0aH3nB5cD7eF9g]" }` — deletes the
token only if it belongs to the current account. Always
`200 {"message": "Appareil supprimé."}`.

#### GET /notifications

The in-app inbox (database notifications), newest first, fixed 20 per page,
standard pagination envelope.

```json
{
  "data": [
    {
      "id": "e01d1681-7d62-4939-8505-c18c7553c07e",
      "type": "FamilyLinkResponded",
      "data": {
        "family_member_id": 2,
        "responder_name": "Yasmine Benali",
        "relation": "wife",
        "status": "approved"
      },
      "read_at": null,
      "created_at": "2026-09-01T10:00:00+01:00"
    },
    {
      "id": "7f7a5093-e021-418d-89d1-d12b542e3d5b",
      "type": "AppointmentStatusChanged",
      "data": {
        "appointment_public_id": "01a05a9c-2563-7042-9e48-9a29a3796e9c",
        "status": "cancelled",
        "starts_at": "2026-09-15T09:00:00+01:00",
        "doctor_name": "Dr Karim Boudjema",
        "clinic_name": "Cabinet El Amel",
        "changed_by_role": "doctor"
      },
      "read_at": null,
      "created_at": "2026-09-01T10:00:00+01:00"
    }
  ],
  "links": { "…": "…" },
  "meta": { "per_page": 20, "total": 3, "…": "…" }
}
```

Notification `type` values and their `data` payloads:

| type | data |
|---|---|
| `AppointmentStatusChanged` | `{appointment_public_id, status, starts_at, doctor_name, clinic_name, changed_by_role}` — sent to the booking patient when the clinic declines/reschedules/updates, and to the cabinet owner when the patient acts. A reschedule keeps `status: "scheduled"` but carries the **new** `starts_at`. |
| `FamilyLinkRequested` | `{family_member_id, owner_name, relation, status: "pending"}` — sent to the account someone wants to link. |
| `FamilyLinkResponded` | `{family_member_id, responder_name, relation, status: "approved"|"declined"}` — sent to the circle owner. |

#### POST /notifications/read

Body: `{ "ids": ["7f7a5093-e021-418d-89d1-d12b542e3d5b"] }` (1..100 UUIDs)
**or** `{ "all": true }`. Only the caller's unread notifications are touched.

```json
{ "message": "Notifications marquées comme lues.", "updated": 3 }
```

### 8.4 Patient endpoints (`auth:sanctum` + Patient role)

Any non-patient token → `403 {"message": "Cette action est réservée aux
comptes patients.", "reason": "patient_role_required"}`.

#### GET /my/profile

```json
{
  "data": {
    "id": 2,
    "phone": "0550123456",
    "email": "amine.benali@example.dz",
    "role": "patient",
    "first_name": "Amine",
    "last_name": "Benali",
    "gender": "male",
    "date_of_birth": "1992-04-17",
    "place_of_birth": null,
    "wilaya": { "code": 16, "name_fr": "Alger", "name_ar": "الجزائر" },
    "baladiya": { "id": 1, "name_fr": "Hydra", "name_ar": "حيدرة" }
  }
}
```

#### PATCH /my/profile

Partial update; send only the changed fields. Accepted: `first_name`,
`last_name`, `gender`, `date_of_birth`, `place_of_birth`, `wilaya_code`,
`baladiya_id`, `email` (same rules as register; `baladiya_id` must match the
submitted or stored wilaya). **`phone` is not updatable in Phase 1** — it is
the account's identity anchor. Response: the updated profile, same shape as
`GET /my/profile`.

Request example: `{ "place_of_birth": "Alger" }`

#### GET /my/appointments

Appointments **booked by this account** (for self or family), across all
clinics. Query: `scope=upcoming|past` (optional), `page`, `per_page` (1..50,
default 15). `upcoming` = future & still blocking (`scheduled`, `confirmed`,
`checked_in`, `in_progress`), ordered soonest first; `past` = everything else,
newest first; no scope = all, newest first. Standard pagination envelope.

```json
{
  "data": [
    {
      "public_id": "01a05a9c-2563-7042-9e48-9a29a3796e9c",
      "status": "scheduled",
      "appointment_date": "2026-09-15",
      "starts_at": "2026-09-15T09:00:00+01:00",
      "ends_at": "2026-09-15T09:30:00+01:00",
      "reason": "Douleurs thoraciques à l’effort",
      "cancellation_reason": null,
      "booked_for": {
        "type": "self",
        "family_member_id": null,
        "name": "Amine Benali"
      },
      "doctor": {
        "id": 1,
        "name": "Dr Karim Boudjema",
        "specialty": { "code": "cardiology", "label_fr": "Cardiologie", "label_ar": "أمراض القلب" }
      },
      "clinic": {
        "id": 1,
        "name": "Cabinet El Amel",
        "address": "12 Rue Didouche Mourad, Alger-Centre",
        "phones": ["0550203040"]
      },
      "created_at": "2026-09-01T10:00:00+01:00"
    }
  ],
  "links": { "…": "…" },
  "meta": { "per_page": 15, "total": 3, "…": "…" }
}
```

This patient-facing shape **never** contains `reception_notes`, internal ids
or sync fields. `booked_for.type` is `"self"` or `"family"`;
`doctor` can be `null` if the clinic later deactivates its doctor profile.
The appointment identifier for patients is always the **`public_id`** (UUID).

#### POST /my/appointments

Book a slot. Request:

```json
{
  "doctor_id": 1,
  "starts_at": "2026-09-15T09:00:00+01:00",
  "family_member_id": null,
  "reason": "Douleurs thoraciques à l’effort"
}
```

- `doctor_id`: from the directory (`doctors[].id`). Required.
- `starts_at`: the exact `starts_at` of an available slot from the day view.
  Required, must be in the future.
- `family_member_id`: optional — book for a family member instead of self.
- `reason`: optional, ≤ 500 chars.

Response `201` — same object shape as the list item above (`booked_for.type`
is `"family"` with the member id and name when booking for a member).
The initial status is always `scheduled` (display as *pending*).

Failures:

- Doctor unknown / unlisted / cabinet inactive → `404
  {"message": "Ce médecin n'est pas ouvert à la réservation en ligne."}`.
- Slot taken / month closed / outside working hours / in time off →
  `409 {"reason": "slot_unavailable"}` (example in §6).
- Family member not owned by the caller, pending, or declined →
  `403 {"message": "Ce membre de la famille ne peut pas être utilisé pour
  cette réservation.", "reason": "family_member_not_usable"}`.

Booking side effects: the clinic gets (or reuses) a patient dossier for the
person booked, and the cabinet owner receives an `AppointmentStatusChanged`
notification when the patient later cancels.

#### GET /my/appointments/{publicId}

Single appointment by `public_id`, only if this account booked it — any other
id (including another patient's) → 404. Response: `{"data": {…}}` with the
same shape as the list item.

#### PATCH /my/appointments/{publicId}/cancel

Body (optional): `{ "cancellation_reason": "Empêchement professionnel" }`
(≤ 500 chars).

Rules: the appointment must be `scheduled` or `confirmed` (else `422` with
`errors.status` = "Ce rendez-vous ne peut plus être annulé."), and must start
**more than `patient_cancel_cutoff_hours` (default 2 h)** from now, else:

```json
// 422
{
  "message": "Le délai d'annulation est dépassé. Veuillez contacter le cabinet directement.",
  "reason": "cancel_cutoff_passed"
}
```

Success `200` — the updated appointment:

```json
{
  "data": {
    "public_id": "01a05a9c-257b-718d-a036-893cebde9f09",
    "status": "cancelled",
    "appointment_date": "2026-09-15",
    "starts_at": "2026-09-15T10:30:00+01:00",
    "ends_at": "2026-09-15T11:00:00+01:00",
    "reason": null,
    "cancellation_reason": "Empêchement professionnel",
    "booked_for": { "type": "self", "family_member_id": null, "name": "Amine Benali" },
    "doctor": { "id": 1, "name": "Dr Karim Boudjema", "specialty": { "code": "cardiology", "label_fr": "Cardiologie", "label_ar": "أمراض القلب" } },
    "clinic": { "id": 1, "name": "Cabinet El Amel", "address": "12 Rue Didouche Mourad, Alger-Centre", "phones": ["0550203040"] },
    "created_at": "2026-09-01T10:00:00+01:00"
  }
}
```

#### GET /my/prescriptions

Prescriptions written for this account's dossiers (self + owned family
members), newest first. `per_page` 1..50, default 15. Standard envelope.

```json
{
  "data": [
    {
      "id": 1,
      "prescribed_at": "2026-09-15T10:45:00+01:00",
      "items": [
        {
          "medication": "Amlodipine 5 mg",
          "dosage": "1 comprimé par jour",
          "duration": "30 jours",
          "instructions": "À prendre le matin"
        }
      ],
      "notes": "Contrôle de la tension dans un mois.",
      "clinic": { "name": "Cabinet El Amel" },
      "patient_display_name": "Amine Benali"
    }
  ],
  "links": { "…": "…" },
  "meta": { "per_page": 15, "total": 1, "…": "…" }
}
```

`items` is a list of `{medication, dosage, duration, instructions}` objects
(`dosage`/`duration`/`instructions` nullable). `items` may be `[]` and
`notes`/`prescribed_at` may be `null`.

#### GET /family-members

The caller's family circle **plus any link request addressed to the caller and
still pending**, newest first, standard envelope (`per_page` 1..50, default 15).

Every row carries a `direction`:
- `outgoing` — a member of my own circle (a dependent I created, or a link I
  requested). This is the only kind I can book appointments for.
- `incoming` — someone else asked to link MY account as their relative and is
  waiting on my answer. `can_respond` is true and `requested_by` names the
  asker. An incoming row is **never bookable by me** — booking still requires
  that I own the row (`403 family_member_not_usable` otherwise). Once I approve
  or decline, the row leaves my list and belongs to its owner's circle.

Without the incoming rows the invited account could never find the request that
only it is allowed to answer, so the consent flow would be un-completable.

```json
{
  "data": [
    {
      "id": 3,
      "direction": "incoming",
      "can_respond": true,
      "requested_by": "Amine Benali",
      "relation": "wife",
      "relation_label": "Épouse",
      "status": "pending",
      "status_label": "En attente",
      "is_linked": true,
      "first_name": null,
      "last_name": null,
      "gender": null,
      "date_of_birth": null,
      "place_of_birth": null,
      "wilaya_code": null,
      "baladiya_id": null,
      "age": null,
      "created_at": "2026-09-01T11:00:00+01:00"
    },
    {
      "id": 2,
      "direction": "outgoing",
      "can_respond": false,
      "requested_by": null,
      "relation": "wife",
      "relation_label": "Épouse",
      "status": "approved",
      "status_label": "Approuvé",
      "is_linked": true,
      "first_name": "Yasmine",
      "last_name": "Benali",
      "gender": "female",
      "date_of_birth": "1994-08-23",
      "place_of_birth": "Oran",
      "wilaya_code": 16,
      "baladiya_id": 1,
      "age": 32,
      "created_at": "2026-09-01T10:00:00+01:00"
    },
    {
      "id": 1,
      "direction": "outgoing",
      "can_respond": false,
      "requested_by": null,
      "relation": "son",
      "relation_label": "Fils",
      "status": "active",
      "status_label": "Actif",
      "is_linked": false,
      "first_name": "Rayan",
      "last_name": "Benali",
      "gender": "male",
      "date_of_birth": "2015-03-12",
      "place_of_birth": null,
      "wilaya_code": 16,
      "baladiya_id": 1,
      "age": 11,
      "created_at": "2026-09-01T10:00:00+01:00"
    }
  ],
  "links": { "…": "…" },
  "meta": { "per_page": 15, "total": 2, "…": "…" }
}
```

Two kinds of members:

- **Dependents** (`is_linked: false`): a profile without its own account
  (child, elderly parent). Status is `active` immediately; demographics are
  the ones stored at creation. Usable for booking right away.
- **Linked accounts** (`is_linked: true`): another patient account, linked by
  consent. Status flow `pending → approved | declined`. While `pending` or
  `declined`, the demographic fields are **`null`** (the other account's
  identity is only exposed once approved) and the member is **not usable for
  booking**.

`relation` values: `father`, `mother`, `husband`, `wife`, `son`, `daughter`,
`other`. `status` values: `active`, `pending`, `approved`, `declined`.

#### POST /family-members — create a dependent

```json
{
  "relation": "son",
  "first_name": "Rayan",
  "last_name": "Benali",
  "gender": "male",
  "date_of_birth": "2015-03-12",
  "place_of_birth": null,
  "wilaya_code": 16,
  "baladiya_id": 1
}
```

`relation`, `first_name`, `last_name`, `gender`, `date_of_birth` required;
the rest optional. Response `201 {"data": {…}}` (member shape above, status
`active`).

#### POST /family-members/link — request a link to another patient account

```json
{ "phone": "0661234567", "relation": "wife" }
```

The phone must belong to an existing **patient** account, not the caller's
own. Creates a `pending` member and sends `FamilyLinkRequested` to the target
account. Response `201` — member shape with `status: "pending"` and null
demographics.

Failures: unknown phone / not a patient account / own phone → `422` with
`errors.phone`; pair already exists →
`422 {"message": "Ce compte fait déjà partie de votre famille.", "reason": "already_linked"}`.

#### POST /family-members/{id}/respond — answer a link request

Called by the **linked (target) account**, not the requester. Body:
`{ "action": "approve" }` or `{ "action": "decline" }`.

- Caller is not the target → `403 {"reason": "not_owner"}`.
- Request already answered → `409 {"message": "Cette demande de lien familial
  a déjà reçu une réponse.", "reason": "link_not_pending"}`.

Success `200` — the member as seen by the target, now `approved`/`declined`;
the owner receives `FamilyLinkResponded`.

#### DELETE /family-members/{id}

Owner only (`403 {"reason": "not_owner"}` otherwise). A **dependent** with
upcoming blocking appointments cannot be deleted:

```json
// 422
{
  "message": "Ce membre a des rendez-vous à venir. Annulez-les avant de le supprimer.",
  "reason": "member_has_appointments"
}
```

A **linked** member is always deletable (only severs the link). Success:
`200 {"message": "Membre de la famille supprimé."}`.

### 8.5 Staff mobile endpoints (`auth:sanctum` + active cabinet + cabinet member)

Patients get `403 patient_token_forbidden`; platform admins and cabinet-less
accounts get `403 cabinet_membership_required`. All ids here are the
**integer appointment ids** of the caller's own cabinet — anything from
another cabinet 404s. These endpoints reuse the **staff** appointment shape
(which *does* include `reception_notes` and sync fields).

Staff `AppointmentResource` shape (used by #27–30 and the legacy CRUD):

```json
{
  "id": 2,
  "public_id": "01a05a9c-2576-72b0-88ef-1a6c979d7f7f",
  "sync_version": 2,
  "patient_id": 2,
  "patient": {
    "id": 2,
    "patient_number": "PAT-20260901-LNP0XO",
    "first_name": "Rayan",
    "last_name": "Benali",
    "full_name": "Rayan Benali",
    "date_of_birth": "2015-03-12",
    "gender": "male",
    "blood_group": null,
    "phone": "0550123456",
    "secondary_phone": null,
    "email": null,
    "address": null,
    "city": null,
    "created_at": "2026-09-01T10:00:00+01:00",
    "updated_at": "2026-09-01T10:00:00+01:00"
  },
  "appointment_date": "2026-09-15",
  "starts_at": "2026-09-15T11:00:00+01:00",
  "ends_at": "2026-09-15T11:30:00+01:00",
  "status": "scheduled",
  "reason": "Fièvre depuis deux jours",
  "prestation": null,
  "reception_notes": null,
  "cancellation_reason": null,
  "can_confirm": true,
  "can_check_in": true,
  "can_cancel": true,
  "confirmed_at": null,
  "checked_in_at": null,
  "created_at": "2026-09-01T10:00:00+01:00",
  "updated_at": "2026-09-01T10:00:00+01:00",
  "deleted_at": null
}
```

(`can_confirm` = status is `scheduled`; `can_check_in` = `scheduled` or
`confirmed`; `can_cancel` = not `completed`/`cancelled`/`no_show`. A mobile
booking's dossier is auto-created: `patient.phone` for a dependent is the
booking owner's phone.)

#### GET /mobile/appointments/today

Query: `date` (`Y-m-d`, defaults to today), `per_page` (1..50, default
**50**). Ordered by `starts_at`, standard envelope of staff appointment
objects.

#### PATCH /mobile/appointments/{id}/decline

Body: `{ "reason": "Le médecin est appelé en urgence à l’hôpital." }` —
**required**, ≤ 255 chars; it is stored as `cancellation_reason` and relayed
to the booking patient's inbox. Allowed from `scheduled`/`confirmed` only
(else `422` with `errors.status`). Response `200 {"data": {…}}` — staff
shape, `status: "cancelled"`.

#### PATCH /mobile/appointments/{id}/reschedule

Body: `{ "starts_at": "2026-09-15T11:00:00+01:00" }` — required, future.
Allowed from `scheduled`/`confirmed`. The target slot must be free (the
appointment's own block is ignored, so nudging it within its current window
works). The status is **kept** as-is; the booking patient is notified with the
new time. Occupied target → `409 {"reason": "slot_unavailable"}`. Response
`200 {"data": {…}}` with the new `appointment_date`/`starts_at`/`ends_at`
(duration recomputed from the schedule).

#### PATCH /mobile/appointments/{id}/no-show

No body. Allowed from `scheduled`/`confirmed`/`checked_in` (else 422).
Response `200 {"data": {…}}` with `status: "no_show"`.

#### POST /mobile/patients — walk-in registration (permission `patients.create`)

The phone is the dedup key inside the cabinet: if a dossier already carries
this number it is returned unchanged with `existing: true` (HTTP **200**),
otherwise a new dossier is created (HTTP **201**, `existing: false`).

Request:

```json
{
  "first_name": "Mohamed",
  "last_name": "Saidi",
  "phone": "0770987654",
  "gender": "male",
  "date_of_birth": "1988-11-02",
  "wilaya_code": 16,
  "address": "Cité 5 Juillet, Bab Ezzouar",
  "city": "Alger"
}
```

(`first_name`, `last_name`, `phone` required — phone matches
`^0[567][0-9]{8}$`; `gender`, `date_of_birth`, `wilaya_code`, `baladiya_id`,
`address`, `city` optional.)

Response `201`:

```json
{
  "data": {
    "id": 3,
    "patient_number": "PAT-20260901-IFZIWP",
    "first_name": "Mohamed",
    "last_name": "Saidi",
    "full_name": "Mohamed Saidi",
    "date_of_birth": "1988-11-02",
    "gender": "male",
    "blood_group": null,
    "phone": "0770987654",
    "secondary_phone": null,
    "email": null,
    "address": "Cité 5 Juillet, Bab Ezzouar",
    "city": "Alger",
    "created_at": "2026-09-01T10:00:00+01:00",
    "updated_at": "2026-09-01T10:00:00+01:00"
  },
  "existing": false
}
```

#### PUT /mobile/schedule — replace the weekly hours (permission `appointments.configure`)

Replaces the doctor's **entire** weekly schedule. Days absent from the
payload become closed. A day holds up to **3 non-overlapping** ranges
(morning + evening sessions).

Request:

```json
{
  "days": [
    {
      "day_of_week": 2,
      "ranges": [
        { "starts_at": "09:00", "ends_at": "12:00", "slot_duration": 30 },
        { "starts_at": "14:00", "ends_at": "17:00", "slot_duration": 30 }
      ]
    },
    {
      "day_of_week": 4,
      "ranges": [
        { "starts_at": "09:00", "ends_at": "12:30", "slot_duration": 20 }
      ]
    }
  ]
}
```

Rules: `days` 1..7 entries, `day_of_week` 1..7 (ISO, Monday=1) distinct;
`ranges` 0..3 per day (an empty array closes the day); times `HH:MM` with
`ends_at` after `starts_at`; ranges of a day must not overlap;
`slot_duration` optional 5..120 minutes (falls back to the doctor's
consultation duration, then the clinic default of 30).

Response `200` — the persisted week in the same `working_hours` shape as the
clinic detail (all 7 days, `period` derived):

```json
{
  "data": {
    "working_hours": [
      { "weekday": 1, "is_closed": true, "ranges": [] },
      {
        "weekday": 2,
        "is_closed": false,
        "ranges": [
          { "starts_at": "09:00", "ends_at": "12:00", "period": "morning", "slot_duration": 30 },
          { "starts_at": "14:00", "ends_at": "17:00", "period": "evening", "slot_duration": 30 }
        ]
      },
      { "weekday": 3, "is_closed": true, "ranges": [] },
      {
        "weekday": 4,
        "is_closed": false,
        "ranges": [
          { "starts_at": "09:00", "ends_at": "12:30", "period": "morning", "slot_duration": 20 }
        ]
      },
      { "weekday": 5, "is_closed": true, "ranges": [] },
      { "weekday": 6, "is_closed": true, "ranges": [] },
      { "weekday": 7, "is_closed": true, "ranges": [] }
    ]
  }
}
```

If the cabinet has no active doctor profile, the request fails `422` with
`errors.doctor`.

#### POST /mobile/schedule/time-off (permission `appointments.configure`)

Request: `{ "starts_at": "2026-09-22", "ends_at": "2026-09-23",
"is_all_day": true, "reason": "Congé annuel" }` — `starts_at`/`ends_at`
required dates (`ends_at` ≥ `starts_at`); `is_all_day` defaults to `true`;
`reason` optional ≤ 150 chars. For a partial-day closure send full date-times
and `is_all_day: false` (end must then be strictly after start).

Response `201` — note the **exclusive end boundary** stored for all-day
closures (midnight after the last day off, exactly like the web editor):

```json
{
  "data": {
    "id": 1,
    "starts_at": "2026-09-22T00:00:00+01:00",
    "ends_at": "2026-09-24T00:00:00+01:00",
    "is_all_day": true,
    "reason": "Congé annuel"
  }
}
```

#### DELETE /mobile/schedule/time-off/{id}

`200 {"message": "Absence supprimée."}` — 404 when the row belongs to another
cabinet or another doctor.

#### GET /mobile/clinic-profile · PUT /mobile/clinic-profile

The cabinet's public directory listing. Reading is open to any cabinet
member; writing needs permission `configuration.branding.manage`. A cabinet
that has never been listed still gets a well-formed (mostly-null, `is_listed:
false`) payload.

PUT request (all fields optional/partial):

```json
{
  "is_listed": true,
  "about": "Cabinet de cardiologie au centre d'Alger. Consultations sur rendez-vous du samedi au jeudi.",
  "address": "12 Rue Didouche Mourad, Alger-Centre",
  "baladiya_id": 1,
  "phones": ["0550203040"],
  "latitude": 36.7525,
  "longitude": 3.042,
  "photos": []
}
```

Rules: `about` ≤ 2000; `address` ≤ 255; `phones` ≤ 3 entries, each matching
`^0[567][0-9]{8}$`; `latitude` −90..90, `longitude` −180..180; `photos` ≤ 6
and may only **keep, reorder or drop** photos the listing already has (send
back the links the API returned); a new link is refused with `422` on
`photos.N` — photos are uploaded with the endpoints below. Flipping
`is_listed` immediately shows/hides the clinic in public discovery.

Every `photos` entry in a response (here and in `GET /clinics/{id}`) is a
full link the app can load.

#### POST /mobile/clinic-profile/photos · DELETE …/photos/{index} · POST …/photos/{index}/cover

Same permission as the PUT. Each call takes effect at once and answers with
the whole profile (shape below).

- `POST /mobile/clinic-profile/photos` — `multipart/form-data`: `photo`
  (JPEG/PNG/WebP image, ≤ 10 MB), optional `replace` (index to swap). Without
  `replace` the photo is appended; a 7th photo is a `422` on `photo`. The
  server turns it upright, shrinks it to ≤ 1600 px, re-encodes it as JPEG
  (dropping EXIF/GPS) and stores it on the public disk. An unreadable image is
  a `422` on `photo`. A replaced photo's file is deleted.
- `DELETE /mobile/clinic-profile/photos/{index}` — removes it (and its file);
  `404` for an index that does not exist.
- `POST /mobile/clinic-profile/photos/{index}/cover` — moves it to the front;
  the first photo is the listing's cover.

Response (`GET` and `PUT` identical shape):

```json
{
  "data": {
    "clinic": { "id": 1, "name": "Cabinet El Amel" },
    "is_listed": true,
    "about": "Cabinet de cardiologie au centre d'Alger. Consultations sur rendez-vous du samedi au jeudi.",
    "address": "12 Rue Didouche Mourad, Alger-Centre",
    "wilaya": { "code": 16, "name_fr": "Alger", "name_ar": "الجزائر" },
    "baladiya": { "id": 1, "name_fr": "Hydra", "name_ar": "حيدرة" },
    "phones": ["0550203040"],
    "latitude": 36.7525,
    "longitude": 3.042,
    "photos": [],
    "working_hours": [
      { "weekday": 1, "is_closed": true, "ranges": [] },
      {
        "weekday": 2,
        "is_closed": false,
        "ranges": [
          { "starts_at": "09:00", "ends_at": "12:00", "period": "morning", "slot_duration": 30 },
          { "starts_at": "14:00", "ends_at": "17:00", "period": "evening", "slot_duration": 30 }
        ]
      },
      { "weekday": 3, "is_closed": true, "ranges": [] },
      { "weekday": 4, "is_closed": false, "ranges": [ { "starts_at": "09:00", "ends_at": "12:30", "period": "morning", "slot_duration": 20 } ] },
      { "weekday": 5, "is_closed": true, "ranges": [] },
      { "weekday": 6, "is_closed": true, "ranges": [] },
      { "weekday": 7, "is_closed": true, "ranges": [] }
    ]
  }
}
```

### 8.6 Pre-existing staff endpoints the mobile app also uses

These predate the mobile API. They sit behind the same active-cabinet gate
(patients always get `403 patient_token_forbidden`) but **not** behind the
cabinet-member gate — prefer the `/mobile/*` endpoints where one exists.

#### POST /auth/token — throttle `login`

Email-only staff login (the desktop flow). Body:

```json
{ "email": "k.boudjema@cabinet-elamel.dz", "password": "password", "device_name": "PC de la réception" }
```

All three fields required. Wrong credentials → 422 on `email`; blocked
cabinet → the same 403 shape as `/auth/login`. Response `200` (note: **no
`role` field**, no expiry on this token):

```json
{
  "token": "4|pUFBKcJuwo4HVafA86StYtM0Xt2k4lQMzwEVrGDYd85cb163",
  "user": { "…same staff UserResource as /auth/login…" }
}
```

The mobile app should use `POST /auth/login` instead (90-day expiry + `role`).

#### GET /me

The authenticated staff account: `{"data": {…}}` wrapping the same
`UserResource` as the login payloads (id, name, email, is_platform_admin,
approved, cabinet{…license}, roles[], permissions[]). Works for any valid
token; for patient UIs use `GET /my/profile` instead (this one has no
patient demographics).

#### GET /appointments

Cabinet appointment list. Query: `from`/`to` (dates, on `appointment_date`),
`patient_id`, `status`, `per_page` (1..100, default 15). Ordered by
`starts_at`; standard envelope of **staff** appointment objects (§8.5 shape).

#### POST /appointments

Staff booking for an existing dossier. Body:

```json
{
  "patient_id": 3,
  "starts_at": "2026-09-15T11:30:00+01:00",
  "reason": "Contrôle de tension",
  "reception_notes": "Patient déjà venu en 2025.",
  "prestation": "Consultation cardiologie"
}
```

(`patient_id` + `starts_at` required; optional `status` limited to
`scheduled|confirmed`; optional idempotency via `Idempotency-Key` header or
`client_request_id` body field, 8..200 chars — a replay returns the original
appointment with header `Idempotency-Replayed: true`, a reuse with a
different payload → `409 {"reason": "idempotency_key_reused"}`.) The slot
must be free per the same availability rules, else `422` on `starts_at`.
Response `201 {"data": {…}}` — staff shape.

#### GET /appointments/{id}

`{"data": {…}}` — staff shape. 404 outside the caller's cabinet.

#### PATCH /appointments/{id}

Partial update and/or status transition. Body fields (all optional):
`reason`, `reception_notes`, `prestation`, `status`
(`confirmed|checked_in|cancelled` only — see §4), `cancellation_reason`
(required when `status=cancelled`, 3..1000 chars), `expected_version`.
Optimistic concurrency: send `expected_version` (or an `If-Match: "N"`
header) with the last seen `sync_version`; on mismatch:

```json
// 409
{
  "message": "Le rendez-vous a ete modifie sur un autre appareil. Rechargez-le avant de reessayer.",
  "reason": "sync_version_conflict",
  "public_id": "01a05a9c-25cc-7133-9246-ad096c6f3c3e",
  "current_version": 2
}
```

Response `200 {"data": {…}}` — staff shape (each write increments
`sync_version`).

#### DELETE /appointments/{id}

Soft-deletes the appointment (policy `cancel`; honours
`If-Match`/`expected_version` like PATCH).
`200 {"message": "Rendez-vous supprimé."}`.

#### GET /schedule

The cabinet's raw scheduling configuration (flat object, **not** wrapped in
`data`):

```json
{
  "doctor": {
    "id": 1,
    "doctor_name": "Dr Karim Boudjema",
    "specialty": "Cardiology",
    "consultation_duration": 30
  },
  "schedules": [
    { "id": 2, "day_of_week": 2, "starts_at": "09:00", "ends_at": "12:00", "slot_duration": 30, "is_active": true },
    { "id": 3, "day_of_week": 2, "starts_at": "14:00", "ends_at": "17:00", "slot_duration": 30, "is_active": true },
    { "id": 4, "day_of_week": 4, "starts_at": "09:00", "ends_at": "12:30", "slot_duration": 20, "is_active": true }
  ],
  "time_off": [
    { "id": 1, "starts_at": "2026-09-22T00:00:00+01:00", "ends_at": "2026-09-24T00:00:00+01:00", "is_all_day": true, "reason": "Congé annuel" }
  ],
  "open_months": [
    { "id": 1, "year": 2026, "month": 9, "is_open": true, "note": null }
  ]
}
```

With no active doctor profile every list is `[]` and `doctor` is `null`.

#### GET /patients · GET /patients/{id}

Cabinet dossier search/read. Query for the list: `q` (matches name/phone/…,
≤ 120 chars), `per_page` (1..100, default 15). Standard envelope of the
`PatientResource` shape shown in §8.5 (`POST /mobile/patients`); the show
endpoint wraps a single one in `{"data": {…}}` and 404s outside the cabinet.

---

### 8.7 Admin (platform superadmin)

The platform back office. Group middleware: `auth:sanctum` + `mobile.admin` +
`throttle:mobile-admin` (60/min keyed on the admin account + IP). The token is
the ordinary mobile token — abilities `["mobile"]` — that `POST /auth/login`
returns for an account whose login response carried `role: "admin"`. There is
no separate admin login.

> **Superadmins are provisioned only by the console command
> `php artisan platform:provision-superadmin`.** No endpoint in this section —
> or anywhere else in the API — can create one, promote an existing account, or
> even read the `is_platform_admin` flag back. An admin has `cabinet_id = null`
> and therefore still gets `cabinet_membership_required` on `/mobile/*` and
> `patient_role_required` on `/my/*`.

Everything here is deliberately **cross-tenant**: the counters and the clinic
list span the whole platform, and `{cabinet}` is any clinic id (a plain
integer — the routes are constrained to digits). An id that does not exist →
`404 {"message": "Cabinet introuvable."}`.

#### Shared refusals

Any non-superadmin token — patient, doctor **or** reception — on **any** route
in this section gets the same 403. Never a 404 that hides whether the clinic
exists, never a partial 200:

```json
{
  "message": "Cet espace est réservé aux administrateurs de la plateforme.",
  "reason": "platform_admin_required",
  "status": "forbidden"
}
```

No token, or an expired one → `401 {"message": "Unauthenticated."}`.

**Prohibited fields — every admin request.** `is_platform_admin`, `role`,
`roles`, `cabinet_id` and `approved_at` may never appear in an admin request
body **or query string**, not even as `false` or `[]`. Sending one fails the
whole request with 422 and nothing at all is created:

```json
{
  "message": "Ce champ ne peut pas être fourni : il est déterminé par la plateforme.",
  "errors": {
    "is_platform_admin": ["Ce champ ne peut pas être fourni : il est déterminé par la plateforme."]
  }
}
```

Ordinary validation failures use the same 422 envelope with the offending field
name. Conflicts use `409 {"message": <fr>, "reason": <code>}` with one of
`already_active`, `already_suspended`, `cabinet_not_active`,
`seat_limit_reached`.

#### Temporary passwords — read before building the creation screens

`POST /admin/cabinets` and `POST /admin/cabinets/{cabinet}/staff` both take an
**optional** `password`:

| `password` in the request | `temporary_password` in the 201 |
|---|---|
| omitted | a **20-character** generated password (letters + digits + symbols) |
| supplied (min 12 chars) | `null` — the admin already knows it, so it is never echoed back |

`temporary_password` is a sibling of `data`, not a field inside it. It is
returned **exactly once**, at creation: no read endpoint ever returns it, no
later response repeats it, and it is never written to the logs (the audit entry
records only `credential_source: "generated"|"supplied"` — a metadata key
containing "password" would be redacted wholesale by `AuditLog`). Show it once in a dismissible
card, tell the admin to hand it over and have the account holder change it at
first login, and never persist it on the device.

#### GET /admin/overview

No parameters. Platform-wide dashboard counters.

```json
{
  "data": {
    "cabinets": { "total": 1, "active": 1, "pending": 0, "suspended": 0 },
    "doctors": 1,
    "staff": 1,
    "patients": 0,
    "appointments": { "today": 0, "upcoming": 0 },
    "listed_clinics": 1,
    "recent_cabinets": [
      {
        "id": 1,
        "name": "عيادة النور",
        "status": "active",
        "status_label": "Actif",
        "specialization": "Cardiologie",
        "wilaya": { "code": 16, "name_fr": "Alger", "name_ar": "الجزائر" },
        "is_listed": true,
        "owner_name": "Dr Yacine Haddad",
        "created_at": "2026-09-01T22:12:43+01:00",
        "activated_at": "2026-09-01T22:12:43+01:00"
      }
    ]
  }
}
```

Semantics:

- `cabinets.total` is every clinic whatever its status; the three other keys
  are the `pending` / `active` / `suspended` buckets.
- `doctors` = accounts holding the `Doctor` role **and** belonging to a
  cabinet; `staff` = accounts holding the `Assistant` role **and** belonging to
  a cabinet. Platform admins and mobile patients (`cabinet_id = null`) are in
  neither figure.
- `patients` = patient dossiers across every clinic.
- `appointments.today` / `.upcoming` count non-`cancelled` appointments dated
  today / strictly after today, platform-wide.
- `listed_clinics` = clinics whose public profile has `is_listed = true` (what
  the public `GET /doctors` directory can show).
- `recent_cabinets` = the **5 newest** clinics (id desc), each one exactly the
  row shape returned by `GET /admin/cabinets`.

#### GET /admin/cabinets

Every clinic on the platform, newest first (id desc), standard pagination
envelope (§3).

Query params (all optional): `status` (`pending` | `active` | `suspended`),
`wilaya_code` (1..58), `q` (≤ 120 chars — matches the clinic name, the owner's
name or the owner's e-mail), `per_page` (1..50, default **15**), `page`.
Filters are preserved in `links`.

`GET /admin/cabinets?status=active&wilaya_code=16` →

```json
{
  "data": [
    {
      "id": 1,
      "name": "عيادة النور",
      "status": "active",
      "status_label": "Actif",
      "specialization": "Cardiologie",
      "wilaya": { "code": 16, "name_fr": "Alger", "name_ar": "الجزائر" },
      "is_listed": true,
      "owner_name": "Dr Yacine Haddad",
      "created_at": "2026-09-01T22:12:43+01:00",
      "activated_at": "2026-09-01T22:12:43+01:00"
    }
  ],
  "links": {
    "first": "https://<host>/api/v1/admin/cabinets?status=active&wilaya_code=16&page=1",
    "last": "https://<host>/api/v1/admin/cabinets?status=active&wilaya_code=16&page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "links": [
      { "url": null, "label": "« Précédent", "page": null, "active": false },
      { "url": "https://<host>/api/v1/admin/cabinets?status=active&wilaya_code=16&page=1", "label": "1", "page": 1, "active": true },
      { "url": null, "label": "Suivant »", "page": null, "active": false }
    ],
    "path": "https://<host>/api/v1/admin/cabinets",
    "per_page": 15,
    "to": 1,
    "total": 1
  }
}
```

`status` is one of exactly `pending` / `active` / `suspended`; `status_label`
is its French label (`En attente` / `Actif` / `Suspendu`) — display the label,
branch on the value. `wilaya`, `owner_name` and `activated_at` are each
independently `null` (a clinic with no wilaya recorded, an owner account since
deleted, a clinic never activated).

#### POST /admin/cabinets — create a clinic **and** its doctor owner

The headline operation: one call materialises the clinic, the owner account and
everything a doctor needs to start working. Request:

```json
{
  "cabinet_name": "عيادة النور",
  "specialization": "Cardiologie",
  "wilaya_code": 16,
  "doctor_name": "Dr Yacine Haddad",
  "email": "y.haddad@clinic.dz",
  "phone": "0550112233",
  "activate": true,
  "is_listed": true
}
```

| Field | Rule |
|---|---|
| `cabinet_name` | required, string, 2..255 |
| `specialization` | required, string, 2..150 — send the **French** catalogue label from `GET /specialties` (`label_fr`, e.g. `"Cardiologie"`); the server normalises it through the specialty catalogue and derives the `code` |
| `wilaya_code` | required, integer, 1..58, must exist in the wilaya table |
| `doctor_name` | required, string, 2..255 |
| `email` | required, valid e-mail, ≤ 190, **unique** across all accounts |
| `phone` | required, Algerian mobile: `/^0[567][0-9]{8}$/` (message: *Saisissez un numéro de téléphone algérien valide (0X XX XX XX XX).*) |
| `password` | **nullable**, string, 12..255, and it must clear the platform password policy (`Password::default()` — in production 12+ chars with mixed case, letters, digits and symbols, exactly as web self-registration) — omit it and the server generates one (see above) |
| `activate` | optional boolean, default `false` |
| `is_listed` | optional boolean, default `false` |

Response **201** — the full clinic detail resource plus the one-shot
`temporary_password` beside it (here the admin omitted `password`):

```json
{
  "data": {
    "id": 1,
    "name": "عيادة النور",
    "status": "active",
    "status_label": "Actif",
    "specialization": "Cardiologie",
    "wilaya": { "code": 16, "name_fr": "Alger", "name_ar": "الجزائر" },
    "created_at": "2026-09-01T22:12:43+01:00",
    "activated_at": "2026-09-01T22:12:43+01:00",
    "is_listed": true,
    "owner": {
      "id": 2,
      "name": "Dr Yacine Haddad",
      "email": "y.haddad@clinic.dz",
      "phone": "0550112233"
    },
    "doctor": {
      "id": 1,
      "name": "Dr Yacine Haddad",
      "specialty": { "code": "cardiology", "label_fr": "Cardiologie", "label_ar": "أمراض القلب" }
    },
    "counts": { "staff": 1, "patients": 0, "appointments": 0 },
    "seats": { "limit": 2, "used": 1, "price": null },
    "license": { "plan": "lifetime", "plan_label": "À vie", "status": "active", "expires_at": null }
  },
  "temporary_password": ":|9{L?4RVY$2EJvJ>.b-"
}
```

What the call actually creates (one transaction, the **same** code path as web
self-registration, so an admin-created clinic is indistinguishable from a
self-registered one):

1. the `Cabinet` in the **pending** state,
2. the owner `User` — `Doctor` role, `approved_at` and `email_verified_at` set,
3. the `DoctorProfile` (specialty code + the phone above) and the per-cabinet
   settings row,
4. a default **Mon–Fri 09:00–17:00** weekly schedule,
5. `activate: true` → a **lifetime** licence is minted and the clinic comes
   back `active` (`license.status: "active"`, `expires_at: null`),
6. `is_listed: true` → the public profile row is created with `is_listed`, so
   the clinic becomes visible in the public `GET /doctors` directory (which
   also requires the clinic to be active),
7. an audit entry `admin.cabinet_provisioned` naming the acting admin.

> **A pending owner cannot sign in.** Leave `activate` at `false` and the new
> doctor's `POST /auth/login` is refused with
> `403 {reason: "cabinet_pending", status: "pending"}` (§6). Send
> `activate: true` whenever the doctor is meant to work immediately.

Errors: duplicate `email`, malformed `phone`, unknown `wilaya_code`, a
`password` under 12 chars or below the platform policy → 422 with the field in
`errors`; any prohibited field → 422 and **nothing** is created. A prohibited
field is refused on **presence**, not on value: `is_platform_admin: null`,
`role: ""` and `roles: []` are rejected exactly like `is_platform_admin: true`.

#### GET /admin/cabinets/{cabinet}

Full detail of one clinic — exactly the `data` object of the previous endpoint,
with **no** `temporary_password` key at all (it exists only in a creation
response):

```json
{
  "data": {
    "id": 1,
    "name": "عيادة النور",
    "status": "active",
    "status_label": "Actif",
    "specialization": "Cardiologie",
    "wilaya": { "code": 16, "name_fr": "Alger", "name_ar": "الجزائر" },
    "created_at": "2026-09-01T22:12:43+01:00",
    "activated_at": "2026-09-01T22:12:43+01:00",
    "is_listed": true,
    "owner": {
      "id": 2,
      "name": "Dr Yacine Haddad",
      "email": "y.haddad@clinic.dz",
      "phone": "0550112233"
    },
    "doctor": {
      "id": 1,
      "name": "Dr Yacine Haddad",
      "specialty": { "code": "cardiology", "label_fr": "Cardiologie", "label_ar": "أمراض القلب" }
    },
    "counts": { "staff": 1, "patients": 0, "appointments": 0 },
    "seats": { "limit": 2, "used": 1, "price": null },
    "license": { "plan": "lifetime", "plan_label": "À vie", "status": "active", "expires_at": null }
  }
}
```

- `wilaya`, `owner`, `doctor`, `doctor.specialty` and `license` are each
  independently nullable — render every one defensively.
- `license` is `null` for a clinic that was never activated; otherwise `status`
  is the *effective* status (`active` / `expired` / `suspended` / `revoked` /
  `inactive`, computed against the clock) and `plan` is `lifetime` or `trial`
  with `plan_label` its French label (`À vie` / `Essai de 7 jours`).
  `expires_at` is `null` for a lifetime licence.
- `counts` are live and scoped to **this** clinic only: `staff` counts every
  account attached to it (the owner included, so a fresh clinic reads `1`),
  `patients` its dossiers, `appointments` all of its appointments.
- `seats` is the clinic's own allowance, set per clinic in the web admin panel:
  `limit` accounts it may hold (owner included; a new clinic gets `2`), `used`
  the same number as `counts.staff`, and `price` the agreed price per seat in
  dinars (`null` when none was recorded).
- Nothing secret is ever serialised here: no password hash, no API token, no
  licence code, no signed certificate, no PIN digest.

#### POST /admin/cabinets/{cabinet}/activate

No body. `pending` → `active` (first activation: mints the lifetime licence) or
`suspended` → `active` (restore: the clinic's existing licence is put back to
`active`/`expired`, and `activated_at` is preserved). Response **200** with the
clinic detail resource above, `data.status: "active"`.

Already active → **409**:

```json
{ "message": "Ce cabinet est déjà actif.", "reason": "already_active" }
```

Audit entry: `admin.cabinet_activated`.

#### POST /admin/cabinets/{cabinet}/suspend

No body. `active` → `suspended`: outstanding licence codes are revoked, the
hosted licence goes to `suspended`, and the clinic **immediately leaves public
discovery** — it disappears from `GET /doctors` and `GET /clinics/{id}` 404s.
Its staff are then refused at login with
`403 {reason: "cabinet_suspended", status: "suspended"}`. Response **200** with
the detail resource, `data.status: "suspended"`.

Conflicts (**409**):

```json
{ "message": "Ce cabinet est déjà suspendu.", "reason": "already_suspended" }
```

```json
{
  "message": "Seul un cabinet actif peut être suspendu : celui-ci est encore en attente d'activation.",
  "reason": "cabinet_not_active"
}
```

Audit entry: `admin.cabinet_suspended`.

#### POST /admin/cabinets/{cabinet}/staff — add a reception account

Creates an **Assistant** (mobile role `reception`) inside that clinic, already
approved. The role is not a parameter — this endpoint can only ever mint a
receptionist, which is why `role` and `roles` sit in the prohibited list.

```json
{
  "name": "Nadia Cherif",
  "email": "n.cherif@clinic.dz",
  "phone": "0551234567"
}
```

| Field | Rule |
|---|---|
| `name` | required, string, 2..120 |
| `email` | required, valid e-mail, ≤ 190, **unique** across all accounts |
| `phone` | optional, nullable, Algerian mobile `/^0[567][0-9]{8}$/`, **unique** across all accounts (mobile patients register with this column, so a receptionist who already has a patient account gets a 422 on `phone`) |
| `password` | **nullable**, string, 12..255, and it must clear the platform password policy (`Password::default()`) — omit it and the server generates one |

Response **201**:

```json
{
  "data": {
    "id": 3,
    "name": "Nadia Cherif",
    "email": "n.cherif@clinic.dz",
    "phone": "0551234567",
    "role": "reception",
    "cabinet_id": 1,
    "approved": true,
    "created_at": "2026-09-01T22:12:44+01:00"
  },
  "temporary_password": "8f]Nq2}Uv#7LbA*3Rz1@"
}
```

The account can sign in through `POST /auth/login` straight away (returning
`role: "reception"`) **provided the clinic is active** — a pending or suspended
clinic refuses its staff at login exactly as it does its owner.

Seats are capped per clinic at `seats.limit` accounts (owner included; `2` for a
new clinic) and allocated under a row lock, so two admins adding a receptionist
at the same instant can never overshoot. A full clinic → **409**:

```json
{
  "message": "Ce cabinet a atteint sa limite de 2 utilisateurs.",
  "reason": "seat_limit_reached"
}
```

Audit entry: `admin.staff_provisioned`.

#### PATCH /admin/cabinets/{cabinet}/listing

Show or hide the clinic in the public mobile directory. `is_listed` is the only
writable field here — the clinic's own staff still own the rest of their public
profile through `PUT /mobile/clinic-profile`, and both screens write the same
row.

```json
{ "is_listed": false }
```

`is_listed` is **required** and boolean. Response **200** with the clinic
detail resource, `data.is_listed` reflecting the new value. Unlisting removes
the clinic from `GET /doctors` at once; the clinic keeps working normally, only
discovery changes. Listing a clinic that has no public profile row yet creates
one.

Audit entry: `admin.cabinet_listing_updated`.

---

## 9. Caveats (Phase 1)

1. **Web vs mobile schedule editor.** The existing web schedule editor stores
   a single range per weekday; the mobile editor (`PUT /mobile/schedule`) is
   multi-range (up to 3 per day). If staff later save a day from the **web**
   editor, a multi-range day is collapsed back to one range. Tell staff to
   manage multi-range days from the mobile editor once it ships, and treat
   `working_hours` as the single source of truth after every write.

2. **Commune (baladiya) dataset is best-effort.** All 58 wilayas are official
   and complete; the commune list aims for the full official set (~1541) but
   has not been verified against an official registry — coverage prioritises
   the major communes of each wilaya. Do not hard-code commune ids; always
   resolve them through `GET /wilayas/{code}/baladiyas`, and expect the
   dataset to be corrected (rows added/renamed) in a later release.

3. **No push delivery yet.** Phase 1 stores Expo device tokens
   (`POST /devices`) and writes **database** notifications only — nothing is
   pushed to the device. Poll `GET /notifications` (e.g. on app focus) for
   the inbox and badge count. Expo push delivery ships in a later phase and
   will reuse the tokens already registered, so wire up `POST /devices` /
   `DELETE /devices` now.
