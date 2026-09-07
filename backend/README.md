# FastAPI backend

This backend uses PostgreSQL only. Copy `.env.example` to `.env`, start the repository's Docker Compose PostgreSQL service, then run:

```powershell
python -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
python -m app.bootstrap
uvicorn app.main:app --reload --port 8000
```

OpenAPI is available at `http://localhost:8000/docs`. All seed and `/simulation/*` records are explicitly synthetic; no physical ESP32 or camera connection is enabled. Login with `admin@example.com` / `AdminDemo123!` or `caregiver@example.com` / `CareDemo123!` unless the corresponding `SEED_*_PASSWORD` setting was changed.
