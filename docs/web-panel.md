# Web Panel

The SmartProt web panel is protected by Laravel session authentication.

## Main Areas

- `/dashboard`: overview scoped to the authenticated guardian.
- `/profile`: guardian profile and password update.
- `/devices`: smartphone registry.
- `/devices/{device}`: device details, pairing token after registration, current policy version, and blocking rules.

## Smartphone Registration

Registering a smartphone creates:

- a `devices` record owned by the authenticated user;
- a one-time pairing token shown only after creation;
- an initial empty policy with version `1`.

The token is stored only as a SHA-256 hash. The plain token must be copied into the Android client during pairing.

## Blocking Rules

Rules are stored as JSON in versioned policies. Adding or removing a rule creates a new policy version. Supported rule types are:

- `app`: Android package name, for example `com.instagram.android`;
- `domain`: domain, for example `tiktok.com`;
- `url`: URL pattern;
- `ip`: IP address or network.

Supported actions are `blocked` and `allowed`.
