"""Create nine PostgreSQL tables for the patient monitoring prototype."""
from alembic import op
from app.database import Base
import app.models  # noqa: F401

revision = "0001_initial"
down_revision = None
branch_labels = None
depends_on = None

def upgrade():
    # Metadata contains explicit PostgreSQL constraints, indexes, timezone-aware timestamps,
    # and all nine required tables. Keeping this migration metadata-driven prevents drift.
    bind = op.get_bind()
    Base.metadata.create_all(bind=bind)

def downgrade():
    bind = op.get_bind()
    Base.metadata.drop_all(bind=bind)
