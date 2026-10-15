# Dokploy Docker deployment

This repository is prepared for Dokploy's **Docker Compose** deployment mode.
The Compose stack builds the Laravel PWA from `web_system/`, runs MariaDB, and
keeps the app's uploaded evidence and database in named Docker volumes. Nginx
serves Laravel and proxies Reverb WebSockets through the same HTTPS domain.

## Before deploying

1. Push this repository to the Git provider connected to Dokploy.
2. In Dokploy, create a project and a **Docker Compose** service. Set the Compose
   file to `compose.dokploy.yaml` at the repository root. Do not use Docker
   Stack/Swarm mode because this file builds the web image from source.
3. Add the variables from [`dokploy.env.example`](dokploy.env.example) in the
   service's Environment tab. Compose explicitly passes the needed values into
   the containers. Replace every example value; keep secrets only in Dokploy.
4. Generate a Laravel key locally from `web_system/` with
   `php artisan key:generate --show` and store the output as `APP_KEY`. Set
   `APP_URL` to the exact HTTPS origin you will publish, and `REVERB_HOST` to
   that hostname without a scheme or path.
5. Deploy. The web container waits for MariaDB, applies Laravel migrations, and
   then starts Nginx, PHP-FPM, and Reverb. First boot can take longer while the
   image is built and migrations run.
6. In the Compose service's **Domains** tab, attach the public hostname to
   service `web`, container port `10000`, and enable HTTPS/Let's Encrypt. Keep
   MariaDB private; it has no published port. Dokploy's domain routing should
   target the internal container port, not expose it directly.
7. After deployment, confirm `/system/version`, `/`, `/login`, and `/register`
   return successful responses. Check the `web` and `db` health states and
   verify a report upload survives a container redeploy.

## Runtime configuration

- The shared Dockerfile installs GD with JPEG/FreeType support before Composer
  runs. Dependency installation and PHP-FPM use the same PHP 8.3 base and
  extensions, and the build checks the locked packages' platform requirements.
- The production container uses `APP_DEBUG=false`, secure HTTPS cookies, MySQL
  sessions, stderr logging, and database migrations at startup.
- MariaDB data persists in `mariadb-data`; uploaded files persist in
  `app-storage`. Configure Dokploy volume backups for both volumes and test a
  restore before using the service for real emergency operations.
- Laravel Reverb listens privately on `127.0.0.1:8080`; Nginx proxies its
  `/app` and `/apps` WebSocket endpoints. The browser uses the public host,
  `wss`, and port 443 from the runtime environment.
- Image and text AI inference remain disabled (`AI_SEVERITY_ENABLED=false`,
  `AI_TEXT_MODE=MANUAL_ONLY`). The deployment runs without the FastAPI/PyTorch
  service and presents new reports for responder review.
- Email defaults to Laravel's `log` mailer. Configure real SMTP variables in
  Dokploy before depending on password reset or account verification emails.
- The public OSRM routing service is the default route provider. For sustained
  production traffic, review its usage terms and configure a suitable provider.
- Run a single `web` replica with this local-filesystem upload setup. If you
  scale horizontally, move uploads to shared S3-compatible storage and add a
  shared cache/queue backend first.

## Operations

- Battery/link telemetry adds nullable columns to `lora_node_states`; the web
  startup migration applies them on redeploy. Deploy the backend before
  uploading the matching NODE_1 and NODE_2–NODE_10 firmware. Verify the authenticated
  `/api/v1/lora/probe` route echoes a packet without creating an incident, and
  check `/monitoring/lora-mesh` for real voltage/round-trip samples. Battery
  sensing stays disabled until divider wiring is installed on the hardware.
- Admin access starts at `/admin`, which sends guests to `/admin/login` and
  signed-in accounts to the protected `/admin/dashboard`. Only admin accounts
  can access that dashboard. After pulling an update that changes routes or
  middleware, redeploy the `web` service from the latest `main` commit; verify
  `/admin` redirects to `/admin/login` while signed out and that admin login
  opens `/admin/dashboard`. A GitHub push alone does not confirm deployment.
- Keep a copy of the `APP_KEY`; changing it invalidates encrypted cookies and
  other values encrypted by Laravel.
- Back up both named volumes. Database migrations run automatically on startup;
  take a database backup before deploying schema changes.
- Set unique strong database passwords, Reverb credentials, and
  `LORA_INGEST_TOKEN`. Do not use the examples in production.
- Use Dokploy's container logs to inspect Laravel output. Nginx and PHP startup
  failures also appear in the `web` service logs.

## Local Compose check

After Docker Desktop is installed, create an untracked `.env` from
`dokploy.env.example`, replace its example values, then run from the repository
root:

```sh
docker compose --env-file .env -f compose.dokploy.yaml config
docker compose --env-file .env -f compose.dokploy.yaml up --build
```

Do not use the example values on a public deployment.
