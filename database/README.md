# PostgreSQL development database

PostgreSQL is the only supported database for this project. `docker compose up db` starts PostgreSQL 16 on host port `5433` with database `patient_monitoring` and user `app_user`. The SQLAlchemy metadata and Alembic migrations in `backend/` are the source of truth for the schema.

Apply migrations from the backend environment with:

```powershell
cd backend
alembic upgrade head
```

`seed_data.sql` is a repeatable, clearly synthetic reference seed for a database that has already been migrated. The API's development seed routine is preferred because it hashes passwords using the same password library as login. Never use the sample passwords or generated records in production.
