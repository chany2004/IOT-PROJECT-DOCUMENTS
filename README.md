# Patient Watch — source code and project documents

The current physical-device implementation is in **[source-code/patient-watch/](source-code/patient-watch/)**:

- ESP32 motion sensor and emergency-button firmware, with Wi-Fi provisioning.
- Separate ESP32-CAM firmware with authenticated, fresh-frame JPEG snapshots.
- Laravel/PHP patient monitoring application, Blade dashboard, PostgreSQL migrations and tests.
- Optional FastAPI/YOLO pose-verification service.

See the [current source README](source-code/patient-watch/README.md) for setup and safety boundaries. Only source files and credential-free configuration examples are included. Generate your own credentials privately; no working passwords, API keys, device secrets, database records or patient images are provided.

The pre-existing project documents and prototype remain below and in their original directories. The legacy root-level FastAPI/React prototype is **not** the current Laravel hardware application. Its containers and simulation endpoints are separate from the physical-device source.

## Legacy prototype documentation (preserved)

This repository contains a local-development prototype for reviewing simulated patient fall and emergency events. It runs a FastAPI API, PostgreSQL database, and React/Vite dashboard. Records created by the built-in simulation routes are explicitly marked as simulated; no ESP32, MPU6050, or camera hardware is required or assumed to be connected.

## Start locally

1. Copy `.env.example` to `.env` and change the secrets for anything beyond a local demo.
2. Start PostgreSQL and the API:

   ```powershell
   docker compose up --build
   ```

3. In a second terminal, start the dashboard:

   ```powershell
   cd frontend
   npm install
   npm run dev
   ```

Open <http://localhost:5173>. The API documentation is at <http://localhost:8000/docs>.

The API container uses PostgreSQL at the internal `db:5432` address. From the host, PostgreSQL is exposed on port `5433` for migrations and inspection. Stop the stack with `docker compose down`; add `-v` only when you intentionally want to remove the local database volume.

## Demo accounts

The development seed values are controlled by `SEED_ADMIN_PASSWORD` and `SEED_CAREGIVER_PASSWORD`. The default accounts are `admin@example.com` and `caregiver@example.com`. Change these values before sharing a running instance.

## Project layout

`backend/` contains the FastAPI application, SQLAlchemy models, Alembic migrations, and tests. `frontend/` contains the Vite React dashboard. `database/` contains the optional SQL seed reference and PostgreSQL notes. The compose file starts only PostgreSQL and the API; the frontend remains a normal Vite development server for fast iteration.

## Hardware boundary

Simulation endpoints generate synthetic readings, events, and camera verification metadata. Camera responses intentionally do not contain a stock or fabricated image. Future device integrations should use the reserved `X-Device-Key` mechanism after real device authentication and evidence handling are implemented.
