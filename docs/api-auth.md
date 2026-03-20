# API Authentication — JWT + Refresh Tokens

## Endpoints

### 1. Login — `POST /api/login_check`

Request:
```json
{
  "username": "user@example.com",
  "password": "secret"
}
```

Response (200):
```json
{
  "token": "<access_token>",
  "refresh_token": "<refresh_token>"
}
```

The `token` (access token) expires after **1 hour** (3600s).
The `refresh_token` expires after **30 days** (2592000s).

### 2. Refresh — `POST /api/token/refresh`

Use this endpoint to obtain a new access token without re-authenticating.

Request:
```json
{
  "refresh_token": "<refresh_token>"
}
```

Response (200):
```json
{
  "token": "<new_access_token>",
  "refresh_token": "<new_refresh_token>"
}
```

**Important:** The refresh token is **single-use**. Each call returns a new refresh token and invalidates the previous one (token rotation). This mitigates replay attacks.

### 3. Logout — `POST /api/logout`

To properly invalidate a session, the client should discard both tokens.
Server-side, refresh tokens are automatically invalidated on expiry or rotation.

For explicit server-side invalidation, send a request with the refresh token:
```json
{
  "refresh_token": "<refresh_token>"
}
```

## Using the access token

Include the JWT in the `Authorization` header for all API requests:

```
Authorization: Bearer <access_token>
```

## Token lifecycle

1. Client authenticates via `/api/login_check` → receives `token` + `refresh_token`
2. Client uses `token` for API calls (valid 1h)
3. When `token` expires (401 response), client calls `/api/token/refresh`
4. New `token` + `refresh_token` pair is returned
5. Old `refresh_token` is invalidated (single-use)
6. After 30 days without refresh, user must log in again

## Deployment note

The `refresh_tokens` table must exist in the database. Ensure the migration is run during deployment:

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```
