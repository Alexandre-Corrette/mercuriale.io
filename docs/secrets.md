# Symfony Secrets — Production Deployment

This project uses [Symfony Secrets](https://symfony.com/doc/current/configuration/secrets.html) to manage sensitive environment variables in production.

## Managed secrets

| Secret           | Description                        |
|------------------|------------------------------------|
| `JWT_PASSPHRASE` | Passphrase for JWT private key     |
| `APP_SECRET`     | Symfony application secret         |

## Initial setup (first deploy)

Generate the prod encryption keys:

```bash
php bin/console secrets:generate-keys --env=prod
```

This creates two files in `config/secrets/prod/`:
- `prod.encrypt.public.php` — committed to the repo, used to **add** secrets
- `prod.decrypt.private.php` — **NEVER committed**, must be deployed separately

Set each secret:

```bash
php bin/console secrets:set JWT_PASSPHRASE --env=prod
php bin/console secrets:set APP_SECRET --env=prod
```

You will be prompted to enter the value interactively.

## Verify secrets

```bash
php bin/console secrets:list --reveal --env=prod
```

## Deployment checklist

1. Ensure `config/secrets/prod/prod.decrypt.private.php` is present on the server (manually copied or via CI secret).
2. Do **not** set `JWT_PASSPHRASE` or `APP_SECRET` in `.env`, `.env.local`, or `.env.prod` on the production server — Symfony Secrets takes precedence.
3. The decrypt key must **never** be committed to Git (already in `.gitignore`).

## Local development

For local dev, set values in `.env.local` (not committed):

```
JWT_PASSPHRASE=your-local-passphrase
APP_SECRET=your-local-secret
```

## Rotating a secret

```bash
php bin/console secrets:set JWT_PASSPHRASE --env=prod
# Enter the new value, then redeploy.
```
