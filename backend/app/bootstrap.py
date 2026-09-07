"""Apply the local schema migration and seed synthetic development data."""
from pathlib import Path

from alembic import command
from alembic.config import Config

from app.seed import seed


def bootstrap() -> None:
    config = Config(str(Path(__file__).resolve().parents[1] / "alembic.ini"))
    command.upgrade(config, "head")
    seed()


if __name__ == "__main__":
    bootstrap()
