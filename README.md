# seedboxapi

A small Laravel + React app that keeps your **MyAnonamouse dynamic seedbox IP** up to date. It is a drop-in alternative to the [`myanonamouse/seedboxapi`](https://hub.docker.com/r/myanonamouse/seedboxapi) container, with a web UI for setting your `mam_id` and seeing what happened.

It runs inside [gluetun](https://github.com/qdm12/gluetun)'s network (`network_mode: "service:gluetun"`), so every request to MAM leaves through your VPN, and MAM registers the VPN's IP for your session.

- **Automatic updates:** every `INTERVAL` seconds it calls MAM's `dynamicSeedbox.php` with your session cookie. MAM answers *Completed* when the IP changed and *No change* otherwise, so frequent checks are harmless.
- **Cookie rotation:** MAM may send back a new `mam_id`; it is saved (encrypted) and used from then on.
- **Web UI:** paste or replace the `mam_id`, change the update interval, run an update now, and see the current IP, ASN, last and next check.
- **History:** an *Activity* tab with every request to MAM and its result, and a *Logs* tab with the app's log.

Stack: Laravel 13, Inertia 3 + React 19, Tailwind CSS 4, SQLite. One container runs nginx, PHP-FPM and the Laravel scheduler under supervisord.

---

## Quick start (production)

### 1. Get a `mam_id`

On MyAnonamouse, open [Preferences → Security](https://www.myanonamouse.net/preferences/index.php?view=security) and create a session:

| Field | Value |
| --- | --- |
| IP | The VPN's IP: the IP MAM sees from inside gluetun, **not** your browser's IP. The UI shows it after the first check, even without a `mam_id`. |
| IP vs ASN locked session | **ASN**, so the IP can change within your VPN provider. |
| Allow Session to set Dynamic Seedbox | **Yes** |
| Session Label/note | e.g. `seedboxapi` |

Submit, then copy the cookie value MAM shows. Use a separate session for this app rather than sharing one with Prowlarr or other clients.

### 2. Add the service to your gluetun stack

```yaml
services:
  gluetun:
    image: qmcgaw/gluetun:v3
    # … your existing gluetun settings …
    ports:
      - "8095:8095"          # seedboxapi web UI (published on gluetun)

  seedboxapi:
    image: ghcr.io/gjhuerte/seedboxapi:latest   # or a version, e.g. :1.0.0
    container_name: seedboxapi
    network_mode: "service:gluetun"
    volumes:
      - ./seedboxapi-volume/config:/config
      - ./seedboxapi-volume/tmp:/tmp
    environment:
      - PUID=1000
      - PGID=1000
      - DEBUG=0
      - mam_id=              # optional, you can paste it in the UI instead
      - INTERVAL=600
    restart: unless-stopped
```

Or build the image yourself from this repository:

```bash
docker build -f docker-prod/Dockerfile -t seedboxapi .
```

Or use the complete example stack in `docker-prod/` (gluetun + seedboxapi):

```bash
cp docker-prod/.env.example docker-prod/.env    # VPN credentials, PUID/PGID, ports
docker compose -f docker-prod/docker-compose.yml up -d --build
```

### 3. Open the UI

Go to **http://localhost:8095**, paste your `mam_id` and click **Save & update**. The first update runs right away; after that, every `INTERVAL` seconds.

> Because the container shares gluetun's network, its web port must be published on the **gluetun** service, and `PORT` must not clash with other apps in the same network (qBittorrent often uses 8080; gluetun uses 8000 and 8888).

---

## Configuration

| Variable | Default | |
| --- | --- | --- |
| `mam_id` (or `MAM_ID`) | *(empty)* | Initial session cookie. Applied on start and whenever the value changes, so it never overwrites a newer cookie saved in the UI or rotated by MAM. |
| `INTERVAL` | `600` | Default seconds between updates (60–86400). It can be changed in the UI (*Check every … min*); a changed `INTERVAL` replaces the UI value on the next start. The scheduler ticks every minute, so it is rounded up to whole minutes. |
| `PUID` / `PGID` | `1000` | Owner of the files in `/config`. |
| `DEBUG` | `0` | `1` logs every check (including *No change*) to `docker logs` and the Logs tab. |
| `PORT` | `8095` | Port the web UI listens on inside gluetun's network. |
| `UI_USERNAME` / `UI_PASSWORD` | *(empty)* | Set both to protect the UI with HTTP Basic auth. Recommended if the port is reachable from your LAN; the page can replace your `mam_id`. |
| `TRUSTED_PROXIES` | private ranges | Reverse proxies allowed to set `X-Forwarded-*` (comma-separated IPs/CIDRs, or `*`). The default (`127.0.0.1`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `fc00::/7`) covers a proxy on your LAN or Docker network. |
| `MAM_AUTO_SYNC` | `true` | `false` stops automatic updates (the UI's *Update now* still works). |
| `MAM_TIMEOUT` | `15` | Seconds to wait for MAM. |
| `MAM_HISTORY_DAYS` | `14` | How long the Activity history is kept. |
| `APP_KEY` | *(generated)* | Generated on first start and stored in `/config/app.key`. It encrypts the stored `mam_id`, so keep it with the rest of `/config`. |

### Volumes

| Path | Contents |
| --- | --- |
| `/config` | `seedboxapi.sqlite` (encrypted `mam_id`, history), `app.key`, `logs/seedboxapi-YYYY-MM-DD.log` (14 days). Back this up. |
| `/tmp` | Runtime sockets and temp files (optional, as in the official image). |

### Behind an HTTPS reverse proxy

For a name like `https://sba.site.com` served by nginx proxy manager (or Traefik, Caddy …), point the proxy host at gluetun's published port (`http://<docker-host>:8095`). The proxy sends `X-Forwarded-Proto: https`; because it connects from a private address it is trusted by default, so asset and redirect URLs come out as `https://` and the browser reports no mixed content.

If the proxy connects from a public address, list it in `TRUSTED_PROXIES`. No `APP_URL` change or forced scheme is needed.

---

## How it works

```
scheduler (every minute)
  └─ php artisan mam:sync --if-due        (waits until INTERVAL has passed)
       ├─ no mam_id yet → GET https://t.myanonamouse.net/json/jsonIp.php       (shows the IP MAM sees)
       └─ mam_id set    → GET https://t.myanonamouse.net/json/dynamicSeedbox.php
                            Cookie: mam_id=<stored value>
                            ← {"Success":true,"msg":"Completed","ip":"…","ASN":…,"AS":"…"}
                            ← Set-Cookie: mam_id=<rotated value>   (saved, encrypted)
```

| MAM answer | Shown as | Meaning |
| --- | --- | --- |
| 200 *Completed* | **Updated** | MAM now has the new IP. |
| 200 *No change* | **No change** | MAM already had this IP. |
| 429 *Last Change too recent* | **Too recent** | MAM limits how often the IP can change; the next check retries. |
| 403 (e.g. *IP mismatch*, *ASN mismatch*, *No Session Cookie*) | **Rejected** | The session cannot be updated from this connection. Create a new dynamic-seedbox session for the VPN (or lock it to the VPN's ASN) and paste the new `mam_id`. |
| timeout / no JSON | **Unreachable** | Usually the VPN is down; check gluetun. |

A cache lock makes sure the scheduler, *Update now* and saving a new `mam_id` never run at the same time, so a rotated cookie is never lost.

### Useful commands

```bash
docker exec seedboxapi php artisan mam:sync           # update now and print the result
docker exec seedboxapi supervisorctl status           # nginx, php-fpm, scheduler
docker logs -f seedboxapi                             # everything, including MAM results
```

---

## Development (`docker-dev/`)

The development container bind-mounts the project, runs as your user, and starts Vite with hot reload.

```bash
cp docker-dev/.env.example docker-dev/.env           # UID/GID, ports
docker compose -f docker-dev/docker-compose.yml up -d --build

docker exec -it seedboxapi composer install
docker exec -it seedboxapi cp -n .env.example .env
docker exec -it seedboxapi php artisan key:generate
docker exec -it seedboxapi touch database/database.sqlite
docker exec -it seedboxapi php artisan migrate
docker exec -it seedboxapi npm install
docker exec -it seedboxapi supervisorctl start vite
```

| Service | URL |
| --- | --- |
| App | http://localhost:8009 |
| Vite (HMR) | http://localhost:5173 |

The development container is **not** behind the VPN, so MAM would see your home IP. Automatic updates are therefore off (`MAM_AUTO_SYNC=false` in `docker-dev/.env`). Test with *Update now* and a throwaway MAM session, or join the dev container to gluetun.

### Tests

```bash
docker exec -it seedboxapi php artisan test
```

All requests to MAM are faked in the tests (`Http::fake()`), so nothing leaves your machine.

### Code map

| | |
| --- | --- |
| `app/Services/Mam/MamClient.php` | Calls `dynamicSeedbox.php` / `jsonIp.php`, reads the rotated cookie. |
| `app/Services/Mam/MamCookie.php` | Stores the `mam_id` encrypted; applies `mam_id` from the environment. |
| `app/Actions/SyncSeedboxIp.php` | One update: lock, call MAM, save the cookie, record the result. |
| `app/Console/Commands/MamSync.php` | `php artisan mam:sync [--if-due]`, scheduled in `routes/console.php`. |
| `app/Services/LogReader.php` | Tail of the log file for the Logs tab. |
| `app/Http/Controllers/DashboardController.php` | The page, save/remove `mam_id`, update now. |
| `resources/js/Pages/Dashboard.jsx` | The UI. |
| `docker-prod/`, `docker-dev/` | Images, nginx, PHP-FPM, supervisord and compose files. |

---

## Releases

Images are published to the GitHub Container Registry as [`ghcr.io/gjhuerte/seedboxapi`](https://github.com/gjhuerte/seedboxapi/pkgs/container/seedboxapi) for `linux/amd64` and `linux/arm64`.

| Tag | Built from |
| --- | --- |
| `1.2.0`, `1.2`, `1`, `latest` | A release (`.github/workflows/release.yml`). Pre-releases such as `1.3.0-rc.1` only get their own tag. |
| `edge`, `sha-abc1234` | Every push to `main` (`.github/workflows/docker-publish.yml`, amd64 only). |

To publish a release, push a version tag:

```bash
git tag v1.0.0
git push origin v1.0.0
```

or open **Actions → release → Run workflow** and enter `1.0.0` (the tag is created for you). The workflow runs the tests and the Vite build, builds and pushes the multi-arch image, and creates the GitHub Release with generated notes and a ready-to-paste compose snippet.

Everything uses the built-in `GITHUB_TOKEN`; no secrets to set up. On a public repository the Actions minutes and package storage are free, and the package inherits the repository's public visibility. If an older package was created private, make it public once under **Package settings → Danger Zone → Change visibility**.

---

## Credits

Behaviour modelled on the official [`myanonamouse/seedboxapi`](https://hub.docker.com/r/myanonamouse/seedboxapi) container and [Mousehole](https://github.com/t-mart/mousehole).
