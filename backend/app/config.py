from functools import lru_cache

from pydantic import field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")
    database_url: str = "postgresql+psycopg://app_user:CHANGE_THIS_PASSWORD@localhost:5433/patient_monitoring"
    jwt_secret: str = "local-development-only-change-this-secret-32chars"
    cors_origins: str = "http://localhost:5173,http://127.0.0.1:5173"
    access_token_minutes: int = 480
    seed_admin_password: str = "AdminDemo123!"
    seed_caregiver_password: str = "CareDemo123!"

    @field_validator("database_url")
    @classmethod
    def postgres_only(cls, value: str) -> str:
        if not value.startswith("postgresql+psycopg://"):
            raise ValueError("Only PostgreSQL with psycopg is supported")
        return value

    @field_validator("jwt_secret")
    @classmethod
    def secret_length(cls, value: str) -> str:
        if len(value) < 32:
            raise ValueError("JWT_SECRET must contain at least 32 characters")
        return value


@lru_cache
def get_settings() -> Settings:
    return Settings()
