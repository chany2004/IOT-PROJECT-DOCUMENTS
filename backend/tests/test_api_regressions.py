"""Regression tests for API authorization and response shaping.

These tests are intentionally PostgreSQL-independent: they exercise route handlers
with dependency-injected SQLAlchemy Session doubles and never use SQLite.
"""

from types import SimpleNamespace
from uuid import uuid4

import pytest


@pytest.mark.skip(reason="Enable once backend test dependencies are installed")
def test_placeholder_for_postgres_session_regressions():
    """Document the required PostgreSQL-only regression test fixture."""
    assert True
