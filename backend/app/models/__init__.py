import uuid
from datetime import datetime, timezone

from sqlalchemy import Boolean, CheckConstraint, DateTime, Float, ForeignKey, Index, Integer, String, Text, text
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column

from app.database import Base


def utcnow():
    return datetime.now(timezone.utc)


class IdentityMixin:
    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow, server_default=text("now()"))


class User(IdentityMixin, Base):
    __tablename__ = "users"
    __table_args__ = (CheckConstraint("role IN ('administrator','caregiver')", name="ck_users_role"),)
    email: Mapped[str] = mapped_column(String(254), unique=True)
    full_name: Mapped[str] = mapped_column(String(120))
    password_hash: Mapped[str] = mapped_column(Text)
    role: Mapped[str] = mapped_column(String(20))
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, server_default=text("true"))
    is_synthetic: Mapped[bool] = mapped_column(Boolean, default=False, server_default=text("false"))


class Room(IdentityMixin, Base):
    __tablename__ = "rooms"
    name: Mapped[str] = mapped_column(String(80), unique=True)
    floor: Mapped[str | None] = mapped_column(String(80))
    description: Mapped[str | None] = mapped_column(Text)
    is_synthetic: Mapped[bool] = mapped_column(Boolean, default=False, server_default=text("false"))


class Patient(IdentityMixin, Base):
    __tablename__ = "patients"
    full_name: Mapped[str] = mapped_column(String(120))
    age: Mapped[int | None] = mapped_column(Integer)
    room_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("rooms.id", ondelete="RESTRICT"), unique=True)
    caregiver_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("users.id", ondelete="RESTRICT"), index=True)
    notes: Mapped[str | None] = mapped_column(Text)
    emergency_contact: Mapped[str | None] = mapped_column(String(200))
    is_synthetic: Mapped[bool] = mapped_column(Boolean, default=False, server_default=text("false"))
    __table_args__ = (CheckConstraint("age IS NULL OR (age >= 0 AND age <= 130)", name="ck_patients_age"),)


class Device(IdentityMixin, Base):
    __tablename__ = "devices"
    name: Mapped[str] = mapped_column(String(120))
    serial_number: Mapped[str] = mapped_column(String(100), unique=True)
    device_type: Mapped[str] = mapped_column(String(30))
    status: Mapped[str] = mapped_column(String(20), default="simulated")
    device_key_hash: Mapped[str] = mapped_column(Text)
    last_seen_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    is_synthetic: Mapped[bool] = mapped_column(Boolean, default=False, server_default=text("false"))
    __table_args__ = (
        CheckConstraint("device_type IN ('esp32_devkit_v1','esp32_cam')", name="ck_devices_type"),
        CheckConstraint("status IN ('simulated','offline','active')", name="ck_devices_status"),
    )


class DeviceAssignment(IdentityMixin, Base):
    __tablename__ = "device_assignments"
    device_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("devices.id", ondelete="RESTRICT"), index=True)
    patient_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("patients.id", ondelete="RESTRICT"), index=True)
    assigned_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)
    ended_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    __table_args__ = (
        Index("uq_current_device_assignment", "device_id", unique=True, postgresql_where=text("ended_at IS NULL")),
        CheckConstraint("ended_at IS NULL OR ended_at >= assigned_at", name="ck_assignment_dates"),
    )


class SensorReading(IdentityMixin, Base):
    __tablename__ = "sensor_readings"
    patient_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("patients.id", ondelete="RESTRICT"), index=True)
    device_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("devices.id", ondelete="RESTRICT"), index=True)
    recorded_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow, index=True)
    acceleration_x: Mapped[float] = mapped_column(Float)
    acceleration_y: Mapped[float] = mapped_column(Float)
    acceleration_z: Mapped[float] = mapped_column(Float)
    angular_velocity_x: Mapped[float] = mapped_column(Float)
    angular_velocity_y: Mapped[float] = mapped_column(Float)
    angular_velocity_z: Mapped[float] = mapped_column(Float)
    is_simulated: Mapped[bool] = mapped_column(Boolean, default=True, server_default=text("true"))


class FallEvent(IdentityMixin, Base):
    __tablename__ = "fall_events"
    patient_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("patients.id", ondelete="RESTRICT"), index=True)
    device_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("devices.id", ondelete="RESTRICT"), index=True)
    sensor_reading_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("sensor_readings.id", ondelete="RESTRICT"))
    event_type: Mapped[str] = mapped_column(String(30))
    status: Mapped[str] = mapped_column(String(20), default="pending", index=True)
    occurred_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow, index=True)
    confidence: Mapped[float | None] = mapped_column(Float)
    notes: Mapped[str | None] = mapped_column(Text)
    is_simulated: Mapped[bool] = mapped_column(Boolean, default=True, server_default=text("true"))
    idempotency_key: Mapped[str] = mapped_column(String(120), unique=True)
    acknowledged_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("users.id", ondelete="RESTRICT"))
    acknowledged_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow, onupdate=utcnow)
    __table_args__ = (
        CheckConstraint("event_type IN ('possible_fall','manual_emergency')", name="ck_events_type"),
        CheckConstraint("status IN ('pending','acknowledged','resolved','false_alarm')", name="ck_events_status"),
        CheckConstraint("confidence IS NULL OR (confidence >= 0 AND confidence <= 1)", name="ck_events_confidence"),
        CheckConstraint("event_type != 'possible_fall' OR sensor_reading_id IS NOT NULL", name="ck_fall_requires_reading"),
    )


class CameraVerification(IdentityMixin, Base):
    __tablename__ = "camera_verifications"
    event_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("fall_events.id", ondelete="RESTRICT"), index=True)
    device_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("devices.id", ondelete="RESTRICT"), index=True)
    status: Mapped[str] = mapped_column(String(30))
    result: Mapped[str] = mapped_column(Text)
    is_simulated: Mapped[bool] = mapped_column(Boolean, default=True, server_default=text("true"))
    image_path: Mapped[str | None] = mapped_column(Text)
    confidence: Mapped[float | None] = mapped_column(Float)
    verified_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)
    __table_args__ = (
        CheckConstraint("status IN ('pending','completed','timeout')", name="ck_camera_status"),
        CheckConstraint("confidence IS NULL OR (confidence >= 0 AND confidence <= 1)", name="ck_camera_confidence"),
        CheckConstraint("NOT is_simulated OR (image_path IS NULL AND confidence IS NULL)", name="ck_simulated_camera_no_evidence"),
    )


class NotificationDelivery(IdentityMixin, Base):
    __tablename__ = "notification_deliveries"
    event_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("fall_events.id", ondelete="RESTRICT"), index=True)
    user_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("users.id", ondelete="RESTRICT"))
    channel: Mapped[str] = mapped_column(String(20), default="dashboard")
    status: Mapped[str] = mapped_column(String(20), default="simulated")
    is_simulated: Mapped[bool] = mapped_column(Boolean, default=True, server_default=text("true"))
    message: Mapped[str] = mapped_column(Text)
    delivered_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    __table_args__ = (
        CheckConstraint("channel IN ('dashboard','email','sms')", name="ck_notifications_channel"),
        CheckConstraint("status IN ('pending','delivered','failed','simulated')", name="ck_notifications_status"),
    )
