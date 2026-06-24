# SmartProt Architecture

## Overview
SmartProt has three working areas:

- `web/`: Laravel backend with the parent control panel and the API consumed by the client phone.
- `app-cli/`: local tools, ADB scripts, API simulators, and prototypes.
- `specs/`: product and technical contracts used before implementation.

The first implementation should use a Laravel monolith for both the web panel and API. The mobile client must only depend on stable API endpoints, not on web views or internal Laravel details.

## Main Components

### Parent Panel
The panel lets guardians register devices, create policies, grant temporary access, review events, and see device status.

### API
The API exposes device registration, policy synchronization, heartbeat, and event ingestion endpoints. All client-facing responses should be versioned under `/api/v1`.

### Client Device
The client phone periodically requests its current policy and enforces it locally. Android enforcement is expected to use VPNService first, because SmartProt blocks selected apps, IPs, domains, or URLs rather than cutting all internet access.

## Data Flow

1. A guardian creates or updates a policy in the panel.
2. Laravel stores the policy and increments its version.
3. The client phone sends heartbeat and policy sync requests.
4. The API returns the active policy for that device.
5. The client applies the policy locally and reports events.

## Initial Technical Choices

- Backend: Laravel in `web/`.
- Database: SQLite for local development, MySQL or PostgreSQL for production.
- Authentication: web session auth for guardians; token auth for devices.
- Policy format: JSON payload with versioned rules.
