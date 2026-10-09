# Patient Watch — physical-device source

Source snapshot published on 2026-10-09. This is the working Laravel/ESP32 project, not the legacy FastAPI/React simulator at the repository root.

## Components

| Directory / file | Purpose |
|---|---|
| `patient-monitor/` | Laravel 12 / PHP 8.2+, Blade dashboard, PostgreSQL migrations, queue jobs and tests |
| `sketch.ino`, `detector.h`, `src/` | Main ESP32 + MPU6050, emergency button, buzzer, device authentication and Wi-Fi provisioning |
| `velxio_document/camera/` | Separate AI-Thinker ESP32-CAM / OV2640 JPEG snapshot server |
| `backend/` | Optional FastAPI / YOLO real-image pose verification |
| `platformio.ini` | Separate main-sensor and camera build targets |
| `Velxio_Fall_Detection_Package/`, `velxio_fall_detection/`, `chips/` | Older simulator source and optional power-component models; not the active deployment |

The current web frontend uses Laravel Blade and JavaScript/CSS, not React. Camera evidence supports caregiver assessment; it does not automatically resolve an incident.

## Private configuration

No real `.env`, device secret header, administrator-login file, database, camera image, compiled firmware or full-flash backup is included. Dependency directories and generated caches are also excluded.

For a new installation, create private configuration from these templates:

1. `.env.example` → `.env` for the optional camera verifier.
2. `patient-monitor/.env.example` → `patient-monitor/.env` for Laravel, or use the local setup script below.
3. `camera_secrets.example.h` → `camera_secrets.h` for the main-controller API settings.
4. `velxio_document/camera/camera_node_secrets.example.h` → `camera_node_secrets.h` in the same folder for the camera settings.

Generate your own strong keys. Register each device in the administrator interface and use its own one-time device key. The camera capture key is separate from its heartbeat device key. Both devices must be assigned to the same patient for incident evidence capture.

For local operation, configure boards with the laptop's reachable LAN IPv4 address, **not** `127.0.0.1`. Replace the templates' example cloud URLs with the local API URLs when using the local web. Never publish your filled-in configuration.

## Local web setup

Install PHP 8.2+ with PostgreSQL, cURL and GD extensions, Composer, Node.js/npm and PostgreSQL. From `patient-monitor/`:

```powershell
composer install
npm ci
npm run build
```

For a fresh Windows installation with PostgreSQL binaries installed:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\setup-local.ps1
powershell -ExecutionPolicy Bypass -File .\scripts\start-local.ps1 -Lan
```

The setup script uses an isolated local PostgreSQL cluster on port **55432**, generates private credentials and preserves existing users. Pass `-PgBin` to the setup script if PostgreSQL is installed elsewhere. Enable PHP GD in the CLI for JPEG validation. Initial local credentials are generated privately in `patient-monitor/.runtime/local-access.txt`; change them after first sign-in.

Open `http://127.0.0.1:8085`. Keep the camera queue worker running; event receipt does not wait for the image. The optional pose-verifier service uses `backend/app.py` and `backend/requirements.txt`; model weights are downloaded separately and are not included.

For additional implementation details, see [LARAVEL_MONITOR_SETUP.md](LARAVEL_MONITOR_SETUP.md). Its private-machine paths are illustrative; do not reuse credentials from any screenshot or external document.

## Firmware builds

From this source directory, after creating private device configuration:

```powershell
pio run -e esp32dev
pio run -e esp32cam
```

Determine the attached board's COM port and identity before uploading. The main sensor and camera use different firmware targets. The COM ports in `platformio.ini` are local examples, not universal settings.

The camera uses a conservative VGA/8 MHz, single-buffer profile with flash disabled and brownout protection enabled. Fresh-frame capture returns old queued buffers and accepts only a frame timestamped after the request. Do not disable power protection to conceal an unstable supply. A new snapshot belongs to a new incident; never replace historical incident evidence with a current photograph.

## Tests and safety boundaries

`test_detector.cpp`, `test_camera_safety.cpp` and `test_wifi_provisioning.cpp` are portable policy tests. Laravel tests are in `patient-monitor/tests/`. Run database tests **only** with a separate `patient_monitor_test` database and a private testing environment; never run them against patient records.

Source files were checked against locally known private credentials and scanned with Gitleaks before publication. This does not revoke previously exposed credentials. Any credential shared in a chat, screenshot or document must still be rotated before production use.

This project is not a validated medical alarm. Perform supervised hardware and reliability testing. Remote delivery can fail during power or network outages. Local HTTP is only for a trusted private LAN. Public deployment requires HTTPS, private database access, backups, restricted operations and monitored workers.
