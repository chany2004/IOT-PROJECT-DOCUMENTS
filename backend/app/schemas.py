from datetime import datetime
from typing import Literal
from uuid import UUID

from pydantic import BaseModel, ConfigDict, EmailStr, Field, FiniteFloat, field_validator

EventType = Literal["possible_fall", "manual_emergency"]
EventStatus = Literal["pending", "acknowledged", "resolved", "false_alarm"]
DeviceType = Literal["esp32_devkit_v1", "esp32_cam"]


class ORM(BaseModel):
    model_config = ConfigDict(from_attributes=True)


class LoginIn(BaseModel):
    email: EmailStr
    password: str = Field(min_length=8, max_length=256)


class UserOut(ORM):
    id: UUID; email: EmailStr; full_name: str; role: str; is_active: bool; is_synthetic: bool


class LoginOut(BaseModel):
    access_token: str; token_type: str = "bearer"; user: UserOut


class RoomIn(BaseModel):
    name: str = Field(min_length=1, max_length=80); floor: str | None = Field(None, max_length=80); description: str | None = None


class RoomOut(RoomIn, ORM):
    id: UUID; is_synthetic: bool; created_at: datetime


class PatientIn(BaseModel):
    full_name: str = Field(min_length=1, max_length=120); age: int | None = Field(None, ge=0, le=130)
    room_id: UUID | None = None; caregiver_id: UUID | None = None; notes: str | None = None; emergency_contact: str | None = Field(None, max_length=200)


class PatientPatch(BaseModel):
    full_name: str | None = Field(None, min_length=1, max_length=120); age: int | None = Field(None, ge=0, le=130)
    room_id: UUID | None = None; caregiver_id: UUID | None = None; notes: str | None = None; emergency_contact: str | None = Field(None, max_length=200)

    @field_validator("full_name")
    @classmethod
    def name_cannot_be_null(cls, value):
        if value is None:
            raise ValueError("full_name cannot be null")
        return value


class PatientOut(PatientIn, ORM):
    id: UUID; is_synthetic: bool; created_at: datetime


class DeviceIn(BaseModel):
    name: str = Field(min_length=1, max_length=120); serial_number: str = Field(min_length=1, max_length=100)
    device_type: DeviceType; status: Literal["simulated", "offline", "active"] = "simulated"


class DeviceOut(ORM):
    id: UUID; name: str; serial_number: str; device_type: str; status: str; last_seen_at: datetime | None; is_synthetic: bool; created_at: datetime


class DeviceCreated(BaseModel):
    device: DeviceOut; device_key: str; warning: str


class AssignmentIn(BaseModel):
    patient_id: UUID


class AssignmentOut(ORM):
    id: UUID; device_id: UUID; patient_id: UUID; assigned_at: datetime; ended_at: datetime | None; created_at: datetime


class ReadingIn(BaseModel):
    recorded_at: datetime | None = None
    acceleration_x: FiniteFloat; acceleration_y: FiniteFloat; acceleration_z: FiniteFloat
    angular_velocity_x: FiniteFloat; angular_velocity_y: FiniteFloat; angular_velocity_z: FiniteFloat


class ReadingOut(ReadingIn, ORM):
    id: UUID; patient_id: UUID; device_id: UUID; recorded_at: datetime; created_at: datetime; is_simulated: bool


class EventIn(BaseModel):
    patient_id: UUID; device_id: UUID; event_type: EventType; occurred_at: datetime | None = None
    confidence: float | None = Field(None, ge=0, le=1); notes: str | None = None; idempotency_key: str | None = Field(None, min_length=1, max_length=120)
    sensor_reading: ReadingIn | None = None; is_simulated: Literal[True] = True


class EventStatusIn(BaseModel):
    status: EventStatus; notes: str | None = None


class EventOut(ORM):
    id: UUID; patient_id: UUID; device_id: UUID; sensor_reading_id: UUID | None; event_type: str; status: str; occurred_at: datetime
    confidence: float | None; notes: str | None; is_simulated: bool; idempotency_key: str; acknowledged_by: UUID | None; acknowledged_at: datetime | None; created_at: datetime; updated_at: datetime


class CameraIn(BaseModel):
    status: Literal["pending", "completed", "timeout"] = "completed"; result: str = Field(min_length=1, max_length=500)
    device_id: UUID | None = None; is_simulated: Literal[True] = True


class CameraOut(ORM):
    id: UUID; event_id: UUID; device_id: UUID | None; status: str; result: str; is_simulated: bool; image_path: str | None; confidence: float | None; verified_at: datetime; created_at: datetime


class SimulationIn(BaseModel):
    patient_id: UUID | None = None; device_id: UUID | None = None; idempotency_key: str | None = Field(None, min_length=1, max_length=120)
