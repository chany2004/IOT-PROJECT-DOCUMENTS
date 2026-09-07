import hashlib
from datetime import datetime, timedelta, timezone
from uuid import UUID

import jwt
from fastapi import Depends, Header, HTTPException
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from pwdlib import PasswordHash
from sqlalchemy.orm import Session

from app.config import get_settings
from app.database import get_db
from app.models import User

password_hasher = PasswordHash.recommended()
bearer = HTTPBearer(auto_error=False)


def hash_password(password: str) -> str:
    return password_hasher.hash(password)


def verify_password(password: str, hashed: str) -> bool:
    try:
        return password_hasher.verify(password, hashed)
    except Exception:
        return False


def hash_device_key(raw: str) -> str:
    # High-entropy generated device keys can be safely fingerprinted with SHA-256.
    return hashlib.sha256(raw.encode()).hexdigest()


def create_token(user: User) -> str:
    settings = get_settings()
    now = datetime.now(timezone.utc)
    return jwt.encode({"sub": str(user.id), "iat": now, "exp": now + timedelta(minutes=settings.access_token_minutes),
                       "iss": "patient-monitoring", "aud": "patient-dashboard"}, settings.jwt_secret, algorithm="HS256")


def current_user(credentials: HTTPAuthorizationCredentials | None = Depends(bearer),
                 device_key: str | None = Header(None, alias="X-Device-Key"), db: Session = Depends(get_db)) -> User:
    if device_key is not None:
        raise HTTPException(401, "X-Device-Key is reserved; physical-device ingestion is not enabled")
    if credentials is None:
        raise HTTPException(401, "Authentication required", headers={"WWW-Authenticate": "Bearer"})
    try:
        data = jwt.decode(credentials.credentials, get_settings().jwt_secret, algorithms=["HS256"],
                          issuer="patient-monitoring", audience="patient-dashboard", options={"require": ["sub", "exp", "iat"]})
        user = db.get(User, UUID(data["sub"]))
    except (jwt.PyJWTError, ValueError, TypeError, KeyError):
        raise HTTPException(401, "Invalid or expired access token")
    if user is None or not user.is_active:
        raise HTTPException(401, "Account is unavailable")
    return user


def administrator(user: User = Depends(current_user)) -> User:
    if user.role != "administrator":
        raise HTTPException(403, "Administrator permission required")
    return user
