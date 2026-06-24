# API Contract

## Base Path
All client endpoints should live under:

```http
/api/v1
```

## Device Registration

```http
POST /api/v1/devices/register
```

Registers a client phone and returns a device token.

Example request:

```json
{
  "name": "Child phone",
  "platform": "android",
  "device_fingerprint": "local-generated-id"
}
```

## Heartbeat

```http
POST /api/v1/devices/{deviceId}/heartbeat
```

Reports that the client is online and sends basic state.

Example request:

```json
{
  "policy_version": 12,
  "battery_percent": 74,
  "vpn_active": true
}
```

## Policy Sync

```http
GET /api/v1/devices/{deviceId}/policy
```

Returns the active policy for one device.

Example response:

```json
{
  "device_id": "dev_123",
  "version": 12,
  "rules": [
    {
      "type": "app",
      "target": "com.instagram.android",
      "network": "blocked"
    },
    {
      "type": "domain",
      "target": "youtube.com",
      "network": "allowed",
      "until": "2026-06-24T18:30:00Z"
    }
  ]
}
```

## Event Reporting

```http
POST /api/v1/devices/{deviceId}/events
```

Reports blocked access, policy application results, and client errors.
