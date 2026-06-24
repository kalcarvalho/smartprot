# Policy Model

## Purpose
Policies describe what the client phone should allow or block. The backend owns policy creation and versioning; the client owns local enforcement.

## Rule Types

### App Rule
Targets an Android package name.

```json
{
  "type": "app",
  "target": "com.instagram.android",
  "network": "blocked"
}
```

### Domain Rule
Targets a domain or subdomain.

```json
{
  "type": "domain",
  "target": "tiktok.com",
  "network": "blocked"
}
```

### IP Rule
Targets an IP address or CIDR range.

```json
{
  "type": "ip",
  "target": "203.0.113.10",
  "network": "blocked"
}
```

## Actions

- `blocked`: deny matching traffic.
- `allowed`: allow matching traffic, even if a broader rule blocks it.

## Time-Limited Rules
Rules may include `from`, `until`, or schedule metadata. The client should ignore expired rules and report policy parsing errors to the API.

## Versioning
Every policy response must include a numeric `version`. The client sends its current version in heartbeat requests so the backend can identify stale clients.
