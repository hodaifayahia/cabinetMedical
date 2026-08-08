# MediSmart — API v1

REST API used by the desktop / companion clients. All routes are prefixed with
`/api/v1` and return JSON. Authentication uses **Laravel Sanctum personal
access tokens** (plain-text bearer tokens), not first-party cookies.

- Base URL: `https://<host>/api/v1`
- Content type: `application/json` (send `Accept: application/json`)
- Auth header (protected routes): `Authorization: Bearer <token>`
- Language: user-facing messages are in French.

## Authentication model

1. A client obtains a token with `POST /auth/token` (email + password +
   device name).
2. The token is returned **once** in plain text — store it securely.
3. Send it as `Authorization: Bearer <token>` on every protected request.
4. `POST /auth/logout` revokes the token used for the current request.

Two middleware layers protect the routes:

| Layer | Middleware | Effect |
| --- | --- | --- |
| Authenticated | `auth:sanctum` | Missing/invalid token → `401 Unauthenticated`. |
| Active cabinet | `cabinet.active.api` (`EnsureApiCabinetIsActive`) | Blocked member → `403` with a `reason` code (see below). |

### Cabinet / member eligibility reasons

`POST /auth/token` and the `cabinet.active.api` gate share the same eligibility
rules (via `CabinetAccessService`). When access is denied they answer `403`
with a machine-readable `reason`:

| `reason` | Meaning | `message` (fr) |
| --- | --- | --- |
| `cabinet_pending` | Cabinet not yet activated by DrClickDz. | "Votre cabinet est en attente d'activation par l'équipe DrClickDz." |
| `cabinet_suspended` | Cabinet suspended. | "Votre cabinet est actuellement suspendu. Contactez le support DrClickDz." |
| `license_expired` | The cabinet's 7-day trial reached its exact expiry time. | "Votre essai de 7 jours est expiré. Contactez l'administration DrClickDz pour renouveler votre licence ou passer à une licence à vie." |
| `license_inactive` | The hosted cabinet licence is inactive or revoked. | "La licence de votre cabinet n'est pas active. Contactez l'administration DrClickDz." |
| `awaiting_approval` | Member account not yet approved by the cabinet owner. | "Votre compte est en attente d'approbation par le propriétaire du cabinet." |

Hosted cabinet activation is fulfilled once by a platform administrator, who
chooses either `trial` (expires exactly seven days after activation) or
`lifetime` (no expiry). An expired trial can be renewed for another seven days
or upgraded to lifetime; renewal keeps the original cabinet activation and
licence identifiers.

## Rate limiting

Public onboarding/auth routes are throttled (per email + IP unless noted):

| Route | Limiter | Limit |
| --- | --- | --- |
| `POST /auth/token` | `login` | 5 / minute (per username + IP) |
| `POST /cabinets/register` | `registration` | 5 / 10 minutes |
| `POST /cabinets/join` | `cabinet-join` | 8 / 10 minutes |

Exceeding a limit returns `429 Too Many Requests`.

## Common error shapes

| Status | When | Body |
| --- | --- | --- |
| `401` | No/invalid token on a protected route. | `{ "message": "Unauthenticated." }` |
| `403` | Ineligible cabinet/member. | `{ "message": "<fr>", "reason": "<code>", "status": "pending|suspended|expired|inactive|awaiting_approval" }` |
| `404` | Resource not found (or belongs to another cabinet — tenant scoped). | `{ "message": "..." }` |
| `422` | Validation failure. | `{ "message": "...", "errors": { "<field>": ["..."] } }` |
| `429` | Rate limit exceeded. | `{ "message": "Too Many Attempts." }` |

All resource routes are **cabinet-scoped**: a token only ever sees data of its
own cabinet. Requesting another cabinet's record returns `404`.

---

## Endpoints

### 1. Issue a token — `POST /api/v1/auth/token`

Authenticate and mint a personal access token.

- Auth: none (public, throttled `login`).

Body:

| Field | Rules |
| --- | --- |
| `email` | required, email |
| `password` | required, string |
| `device_name` | required, string, max 255 |

Example request:

```json
POST /api/v1/auth/token
{
  "email": "owner@example.com",
  "password": "secret-password",
  "device_name": "iPhone du Dr Benali"
}
```

Example response `200 OK`:

```json
{
  "token": "12|abcDEF...plainTextToken",
  "user": {
    "id": 1,
    "name": "Dr Benali",
    "email": "owner@example.com",
    "is_platform_admin": false,
    "approved": true,
    "cabinet": {
      "id": 3,
      "name": "Cabinet Benali",
      "status": "active",
      "specialization": "Médecine générale",
      "wilaya": { "code": 16, "name": "Alger" },
      "license": {
        "plan": "trial",
        "plan_label": "Essai de 7 jours",
        "status": "active",
        "status_label": "Active",
        "expires_at": "2026-08-15T10:00:00+01:00"
      }
    },
    "roles": ["administrator"],
    "permissions": ["patients.view", "appointments.manage"]
  }
}
```

Errors:

- `422` — bad credentials: `{ "message": "...", "errors": { "email": ["Ces identifiants ne correspondent à aucun compte."] } }`
- `403` — ineligible cabinet/member: `{ "message": "<fr>", "reason": "cabinet_pending|cabinet_suspended|license_expired|license_inactive|awaiting_approval", "status": "<state>" }` (no token is issued).

---

### 2. Register a new cabinet — `POST /api/v1/cabinets/register`

Provision a brand-new cabinet in the `pending` state together with its owner
(administrator) account. Mirrors the web registration flow
(`RegisterCabinetAction`). The cabinet must be activated by DrClickDz before the
owner can obtain a usable token.

- Auth: none (public, throttled `registration`).

Body:

| Field | Rules |
| --- | --- |
| `name` | required, string (owner's full name) |
| `phone` | required, valid phone number, maximum 40 characters |
| `email` | required, email, unique across users |
| `password` | required, string, default password policy, `confirmed` |
| `password_confirmation` | required, must match `password` |
| `cabinet_name` | required, string, 2–180 |
| `specialization` | required, string, 2–150 |
| `wilaya` | required, integer (Algerian wilaya code, 1–58) |

Example request:

```json
POST /api/v1/cabinets/register
{
  "name": "Dr Benali",
  "phone": "+213 555 12 34 56",
  "email": "owner@example.com",
  "password": "secret-password",
  "password_confirmation": "secret-password",
  "cabinet_name": "Cabinet Benali",
  "specialization": "Médecine générale",
  "wilaya": 16
}
```

Example response `201 Created`:

```json
{ "cabinet_id": 3, "status": "pending" }
```

Errors: `422` on validation failure, `429` when throttled.

---

### 3. Join an existing cabinet — `POST /api/v1/cabinets/join`

Request membership of an existing **active** cabinet. Creates the account in an
`awaiting_approval` state; no token is issued until the owner approves the
member and the cabinet is active.

- Auth: none (public, throttled `cabinet-join`).

Body:

| Field | Rules |
| --- | --- |
| `name` | required, string, max 120 |
| `email` | required, email, max 190, unique across users |
| `password` | required, string, default password policy, `confirmed` |
| `password_confirmation` | required, must match `password` |
| `owner_email` | required, email — identifies the target cabinet by its owner |

Example request:

```json
POST /api/v1/cabinets/join
{
  "name": "Assistante Amina",
  "email": "amina@example.com",
  "password": "secret-password",
  "password_confirmation": "secret-password",
  "owner_email": "owner@example.com"
}
```

Example response `201 Created`:

```json
{
  "message": "Votre demande a été envoyée. Vous pourrez vous connecter une fois approuvé par le propriétaire du cabinet.",
  "cabinet_id": 3,
  "status": "awaiting_approval"
}
```

Errors: `422` on validation failure (e.g. unknown owner, duplicate email).

---

### 4. Revoke the current token — `POST /api/v1/auth/logout`

- Auth: `auth:sanctum`.

Deletes the personal access token used to authenticate the request. The token
can no longer be used afterwards.

Example response `200 OK`:

```json
{ "message": "Déconnexion réussie." }
```

Errors: `401` if no valid token is supplied.

---

### 5. Current user — `GET /api/v1/me`

- Auth: `auth:sanctum`.

Returns the authenticated user with cabinet, roles and permissions. The
password hash is never exposed.

Example response `200 OK`:

```json
{
  "data": {
    "id": 1,
    "name": "Dr Benali",
    "email": "owner@example.com",
    "is_platform_admin": false,
    "approved": true,
    "cabinet": {
      "id": 3,
      "name": "Cabinet Benali",
      "status": "active",
      "specialization": "Médecine générale",
      "wilaya": { "code": 16, "name": "Alger" }
    },
    "roles": ["administrator"],
    "permissions": ["patients.view", "appointments.manage"]
  }
}
```

Errors: `401` without a valid token.

---

The following routes additionally require `cabinet.active.api` (active cabinet +
approved member). A blocked member receives `403 { message, reason }`.

### 6. List appointments — `GET /api/v1/appointments`

- Auth: `auth:sanctum` + `cabinet.active.api`. Ability: `viewAny` Appointment.

Query params (all optional):

| Param | Rules |
| --- | --- |
| `from` | date — filter `appointment_date >=` |
| `to` | date — filter `appointment_date <=` |
| `patient_id` | integer |
| `status` | string (`scheduled`, `confirmed`, `checked_in`, `in_progress`, `completed`, `cancelled`, `no_show`) |
| `per_page` | integer 1–100 (default 15) |

Response `200 OK`: paginated collection of appointment resources.

```json
{
  "data": [
    {
      "id": 42,
      "patient_id": 7,
      "patient": { "id": 7, "full_name": "Amina Kaci", "...": "..." },
      "appointment_date": "2026-08-12",
      "starts_at": "2026-08-12T09:00:00+01:00",
      "ends_at": "2026-08-12T09:15:00+01:00",
      "status": "scheduled",
      "reason": "Contrôle",
      "prestation": "Consultation",
      "reception_notes": null,
      "cancellation_reason": null,
      "can_confirm": true,
      "can_check_in": true,
      "can_cancel": true,
      "confirmed_at": null,
      "checked_in_at": null,
      "created_at": "2026-08-07T10:00:00+01:00",
      "updated_at": "2026-08-07T10:00:00+01:00"
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "per_page": 15, "total": 1, "...": "..." }
}
```

### 7. Show appointment — `GET /api/v1/appointments/{appointment}`

- Auth: `auth:sanctum` + `cabinet.active.api`. Ability: `view`.
- `200 OK` → `{ "data": { ...appointment } }`; `404` if not in the cabinet.

### 8. Book appointment — `POST /api/v1/appointments`

- Auth: `auth:sanctum` + `cabinet.active.api`. Ability: `create`.
- Reuses the web availability check + `CreateAppointmentAction`.

Body:

| Field | Rules |
| --- | --- |
| `patient_id` | required, integer, exists in `patients` |
| `starts_at` | required, date (must match an available slot) |
| `reason` | nullable, string, max 1000 |
| `reception_notes` | nullable, string, max 2000 |
| `prestation` | nullable, string, max 255 |
| `status` | nullable, one of `scheduled`, `confirmed` |

Response `201 Created` → `{ "data": { ...appointment } }`.

Errors:
- `422 { errors: { starts_at: ["Ce créneau n'est plus disponible..."] } }` — slot taken/unavailable.
- `422 { errors: { doctor: ["Aucun médecin actif n'est configuré pour ce cabinet."] } }` — no active doctor.

### 9. Update appointment — `PATCH /api/v1/appointments/{appointment}`

- Auth: `auth:sanctum` + `cabinet.active.api`. Ability: `update`.

Body (all `sometimes`):

| Field | Rules |
| --- | --- |
| `reason` | nullable, string, max 1000 |
| `reception_notes` | nullable, string, max 2000 |
| `prestation` | nullable, string, max 255 |
| `status` | string — transition target (`confirmed`, `checked_in`, `cancelled` supported) |
| `cancellation_reason` | required when `status=cancelled`, 3–1000 chars |

Status transitions enforce the same guard rails as the web app:
- `confirmed` only from `scheduled`.
- `checked_in` only from `scheduled` or `confirmed`.
- `cancelled` not allowed from `completed`, `cancelled` or `no_show`.

Response `200 OK` → `{ "data": { ...appointment } }`.
Errors: `422 { errors: { status: ["..."] } }` for illegal transitions.

### 10. Delete appointment — `DELETE /api/v1/appointments/{appointment}`

- Auth: `auth:sanctum` + `cabinet.active.api`. Ability: `cancel`.
- `200 OK` → `{ "message": "Rendez-vous supprimé." }`.

### 11. Schedule — `GET /api/v1/schedule`

- Auth: `auth:sanctum` + `cabinet.active.api`. Ability: `viewAny` Appointment.

Returns the current doctor's weekly schedules, time off and open months. When
no active doctor is configured, all lists are empty and `doctor` is `null`.

```json
{
  "doctor": {
    "id": 1,
    "doctor_name": "Dr Benali",
    "specialty": "Médecine générale",
    "consultation_duration": 15
  },
  "schedules": [
    { "id": 1, "day_of_week": 1, "starts_at": "09:00", "ends_at": "16:00", "slot_duration": 15, "is_active": true }
  ],
  "time_off": [
    { "id": 1, "starts_at": "2026-08-20T00:00:00+01:00", "ends_at": "2026-08-21T00:00:00+01:00", "is_all_day": true, "reason": "Congé" }
  ],
  "open_months": [
    { "id": 1, "year": 2026, "month": 8, "is_open": true, "note": null }
  ]
}
```

### 12. List patients — `GET /api/v1/patients`

- Auth: `auth:sanctum` + `cabinet.active.api`. Ability: `viewAny` Patient.

Query params:

| Param | Rules |
| --- | --- |
| `q` | nullable, string, max 120 — full-text search |
| `per_page` | integer 1–100 (default 15) |

Response `200 OK`: paginated collection of patient resources.

```json
{
  "data": [
    {
      "id": 7,
      "patient_number": "P-000007",
      "first_name": "Amina",
      "last_name": "Kaci",
      "full_name": "Amina Kaci",
      "date_of_birth": "1990-05-01",
      "gender": "female",
      "blood_group": "O+",
      "phone": "0550...",
      "secondary_phone": null,
      "email": "amina@example.com",
      "address": "...",
      "city": "Alger",
      "created_at": "2026-08-07T10:00:00+01:00",
      "updated_at": "2026-08-07T10:00:00+01:00"
    }
  ],
  "links": { "...": "..." },
  "meta": { "...": "..." }
}
```

### 13. Show patient — `GET /api/v1/patients/{patient}`

- Auth: `auth:sanctum` + `cabinet.active.api`. Ability: `view`.
- `200 OK` → `{ "data": { ...patient } }`; `404` if not in the cabinet.

---

## Resource schemas (summary)

**UserResource**: `id`, `name`, `email`, `is_platform_admin`, `approved`,
`cabinet` (CabinetResource, when loaded), `roles[]`, `permissions[]`.

**CabinetResource**: `id`, `name`, `status` (`pending|active|suspended`),
`specialization`, `wilaya { code, name }`, `license` (when loaded):
`{ plan: trial|lifetime, plan_label, status, status_label, expires_at }`.

**PatientResource**: `id`, `patient_number`, `first_name`, `last_name`,
`full_name`, `date_of_birth`, `gender`, `blood_group`, `phone`,
`secondary_phone`, `email`, `address`, `city`, `created_at`, `updated_at`.

**AppointmentResource**: `id`, `patient_id`, `patient` (when loaded),
`appointment_date`, `starts_at`, `ends_at`, `status`, `reason`, `prestation`,
`reception_notes`, `cancellation_reason`, `can_confirm`, `can_check_in`,
`can_cancel`, `confirmed_at`, `checked_in_at`, `created_at`, `updated_at`.
