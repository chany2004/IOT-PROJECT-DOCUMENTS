import secrets
from datetime import datetime, timezone
from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, Query, status
from sqlalchemy import func, or_, select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.auth.security import administrator, current_user, create_token, hash_device_key, hash_password, verify_password
from app.database import get_db
from app.models import CameraVerification, Device, DeviceAssignment, FallEvent, NotificationDelivery, Patient, Room, SensorReading, User, utcnow
from app.schemas import *

router = APIRouter()


def page(items, page, page_size, total):
    return {"items": items, "total": total, "page": page, "page_size": page_size}


def serialized(model, schema):
    """Serialize ORM objects through an explicit public schema (never raw __dict__)."""
    return schema.model_validate(model)


def visible_patient_filter(query, user):
    return query.where(Patient.caregiver_id == user.id) if user.role == "caregiver" else query


def visible_patient(patient_id: UUID, user: User, db: Session) -> Patient:
    item = db.get(Patient, patient_id)
    if item is None or (user.role == "caregiver" and item.caregiver_id != user.id):
        raise HTTPException(404, "Patient not found")
    return item


def visible_event(event_id: UUID, user: User, db: Session) -> FallEvent:
    item = db.get(FallEvent, event_id)
    if item is None:
        raise HTTPException(404, "Event not found")
    visible_patient(item.patient_id, user, db)
    return item


def assigned_device(patient_id: UUID, device_id: UUID, device_type: str, db: Session) -> Device:
    device = db.get(Device, device_id)
    if device is None:
        raise HTTPException(404, "Device not found")
    if device.device_type != device_type:
        raise HTTPException(400, f"Device must be an {device_type} device")
    assignment = db.scalar(select(DeviceAssignment).where(
        DeviceAssignment.patient_id == patient_id,
        DeviceAssignment.device_id == device_id,
        DeviceAssignment.ended_at.is_(None),
    ))
    if assignment is None:
        raise HTTPException(400, "Device is not assigned to this patient")
    return device


@router.post("/auth/login", response_model=LoginOut)
def login(body: LoginIn, db: Session = Depends(get_db)):
    user = db.scalar(select(User).where(User.email == body.email.lower()))
    if user is None or not user.is_active or not verify_password(body.password, user.password_hash):
        raise HTTPException(401, "Invalid email or password")
    return LoginOut(access_token=create_token(user), user=user)


@router.get("/auth/me", response_model=UserOut)
def me(user: User = Depends(current_user)): return user


@router.get("/caregivers", response_model=list[UserOut])
def caregivers(_: User = Depends(administrator), db: Session = Depends(get_db)):
    rows = db.scalars(select(User).where(User.role == "caregiver", User.is_active.is_(True)).order_by(User.full_name)).all()
    return rows


@router.get("/patients")
def patients(page_no: int = Query(1, alias="page", ge=1), page_size: int = Query(25, ge=1, le=100), user: User = Depends(current_user), db: Session = Depends(get_db)):
    q = visible_patient_filter(select(Patient), user)
    total = db.scalar(select(func.count()).select_from(q.subquery())) or 0
    rows = db.scalars(q.order_by(Patient.created_at.desc()).offset((page_no-1)*page_size).limit(page_size)).all()
    return page([PatientOut.model_validate(x) for x in rows], page_no, page_size, total)


@router.post("/patients", response_model=PatientOut, status_code=201)
def create_patient(body: PatientIn, _: User = Depends(administrator), db: Session = Depends(get_db)):
    if body.room_id and db.get(Room, body.room_id) is None: raise HTTPException(404, "Room not found")
    if body.caregiver_id and (db.get(User, body.caregiver_id) is None or db.get(User, body.caregiver_id).role != "caregiver"): raise HTTPException(400, "caregiver_id must refer to a caregiver")
    item = Patient(**body.model_dump(), is_synthetic=False); db.add(item)
    try: db.commit(); db.refresh(item)
    except IntegrityError: db.rollback(); raise HTTPException(409, "Room already has a patient")
    return item


@router.get("/patients/{patient_id}", response_model=PatientOut)
def patient(patient_id: UUID, user: User = Depends(current_user), db: Session = Depends(get_db)):
    item = db.get(Patient, patient_id)
    if item is None or (user.role == "caregiver" and item.caregiver_id != user.id): raise HTTPException(404, "Patient not found")
    return item


@router.patch("/patients/{patient_id}", response_model=PatientOut)
def update_patient(patient_id: UUID, body: PatientPatch, _: User = Depends(administrator), db: Session = Depends(get_db)):
    item = db.get(Patient, patient_id)
    if item is None: raise HTTPException(404, "Patient not found")
    changes = body.model_dump(exclude_unset=True)
    if "room_id" in changes and changes["room_id"] and db.get(Room, changes["room_id"]) is None:
        raise HTTPException(404, "Room not found")
    if "caregiver_id" in changes and changes["caregiver_id"]:
        caregiver = db.get(User, changes["caregiver_id"])
        if caregiver is None or caregiver.role != "caregiver":
            raise HTTPException(400, "caregiver_id must refer to a caregiver")
    for k,v in changes.items(): setattr(item,k,v)
    try: db.commit(); db.refresh(item)
    except IntegrityError: db.rollback(); raise HTTPException(409, "Room already has a patient")
    return item


@router.get("/rooms")
def rooms(_: User = Depends(current_user), db: Session = Depends(get_db)):
    return {"items": [RoomOut.model_validate(x) for x in db.scalars(select(Room).order_by(Room.name)).all()]}


@router.post("/rooms", response_model=RoomOut, status_code=201)
def create_room(body: RoomIn, _: User = Depends(administrator), db: Session = Depends(get_db)):
    item=Room(**body.model_dump()); db.add(item)
    try: db.commit(); db.refresh(item)
    except IntegrityError: db.rollback(); raise HTTPException(409,"Room name already exists")
    return item


@router.patch("/rooms/{room_id}", response_model=RoomOut)
def update_room(room_id: UUID, body: RoomIn, _: User = Depends(administrator), db: Session = Depends(get_db)):
    item=db.get(Room,room_id)
    if item is None: raise HTTPException(404,"Room not found")
    for k,v in body.model_dump().items(): setattr(item,k,v)
    db.commit(); db.refresh(item); return item


@router.get("/devices")
def devices(user: User = Depends(current_user), db: Session = Depends(get_db)):
    q = select(Device).order_by(Device.created_at.desc())
    if user.role == "caregiver":
        q = q.join(DeviceAssignment).join(Patient, Patient.id == DeviceAssignment.patient_id).where(
            Patient.caregiver_id == user.id, DeviceAssignment.ended_at.is_(None))
    return {"items": [DeviceOut.model_validate(x) for x in db.scalars(q).unique().all()]}


@router.post("/devices", response_model=DeviceCreated, status_code=201)
def create_device(body: DeviceIn, _: User = Depends(administrator), db: Session = Depends(get_db)):
    raw_key = "dev_" + secrets.token_urlsafe(32)
    item=Device(**body.model_dump(), device_key_hash=hash_device_key(raw_key)); db.add(item)
    try: db.commit(); db.refresh(item)
    except IntegrityError: db.rollback(); raise HTTPException(409,"Serial number already exists")
    return {"device": item, "device_key": raw_key, "warning": "Store this key securely; it is shown once. Physical ingestion is not enabled."}


@router.get("/devices/{device_id}")
def device(device_id: UUID, user: User = Depends(current_user), db: Session = Depends(get_db)):
    item=db.get(Device,device_id)
    if item is None: raise HTTPException(404,"Device not found")
    if user.role == "caregiver" and db.scalar(select(DeviceAssignment).join(Patient).where(
        DeviceAssignment.device_id == device_id, DeviceAssignment.ended_at.is_(None), Patient.caregiver_id == user.id)) is None:
        raise HTTPException(404, "Device not found")
    return DeviceOut.model_validate(item)


@router.get("/device-assignments")
def assignments(user: User = Depends(current_user), db: Session = Depends(get_db)):
    q = select(DeviceAssignment).where(DeviceAssignment.ended_at.is_(None)).order_by(DeviceAssignment.created_at.desc())
    if user.role == "caregiver":
        q = q.join(Patient, Patient.id == DeviceAssignment.patient_id).where(Patient.caregiver_id == user.id)
    return {"items": [AssignmentOut.model_validate(x) for x in db.scalars(q).unique().all()]}


@router.post("/devices/{device_id}/assign")
def assign(device_id: UUID, body: AssignmentIn, _: User = Depends(administrator), db: Session = Depends(get_db)):
    dev, patient = db.get(Device,device_id), db.get(Patient,body.patient_id)
    if dev is None or patient is None: raise HTTPException(404,"Device or patient not found")
    active = db.scalar(select(DeviceAssignment).where(DeviceAssignment.device_id==device_id, DeviceAssignment.ended_at.is_(None)))
    if active: raise HTTPException(409,"Device is already assigned")
    current_type = db.scalar(select(DeviceAssignment).join(Device).where(DeviceAssignment.patient_id==patient.id, Device.device_type==dev.device_type, DeviceAssignment.ended_at.is_(None)))
    if current_type: raise HTTPException(409,"Patient already has this device type assigned")
    item=DeviceAssignment(device_id=device_id,patient_id=patient.id); db.add(item); db.commit(); db.refresh(item); return item


def get_patient_device(body, db, user: User):
    patient_q = visible_patient_filter(select(Patient.id), user).order_by(Patient.created_at)
    patient_id = body.patient_id or db.scalar(patient_q)
    if not patient_id: raise HTTPException(400,"No patient is available")
    visible_patient(patient_id, user, db)
    device_id = body.device_id or db.scalar(select(Device.id).join(DeviceAssignment).where(DeviceAssignment.patient_id==patient_id, DeviceAssignment.ended_at.is_(None), Device.device_type=="esp32_devkit_v1").order_by(Device.created_at))
    if not device_id: raise HTTPException(400,"No assigned ESP32 DevKit device is available")
    assigned_device(patient_id, device_id, "esp32_devkit_v1", db)
    return patient_id, device_id


@router.get("/sensor-readings")
def sensor_readings(
    patient_id: UUID | None = None,
    device_id: UUID | None = None,
    reading_id: UUID | None = Query(None, alias="id"),
    page_no: int = Query(1, alias="page", ge=1),
    page_size: int = Query(25, ge=1, le=100),
    user: User = Depends(current_user), db: Session = Depends(get_db),
):
    q = select(SensorReading)
    if user.role == "caregiver":
        q = q.join(Patient, Patient.id == SensorReading.patient_id).where(Patient.caregiver_id == user.id)
    if patient_id: q = q.where(SensorReading.patient_id == patient_id)
    if device_id: q = q.where(SensorReading.device_id == device_id)
    if reading_id: q = q.where(SensorReading.id == reading_id)
    total = db.scalar(select(func.count()).select_from(q.subquery())) or 0
    rows = db.scalars(q.order_by(SensorReading.recorded_at.desc()).offset((page_no - 1) * page_size).limit(page_size)).unique().all()
    return page([ReadingOut.model_validate(x) for x in rows], page_no, page_size, total)


@router.post("/events", response_model=EventOut, status_code=201)
def create_event(body: EventIn, user: User = Depends(current_user), db: Session = Depends(get_db)):
    patient = db.get(Patient, body.patient_id)
    if patient is None or (user.role=="caregiver" and patient.caregiver_id != user.id): raise HTTPException(404,"Patient not found")
    assigned_device(body.patient_id, body.device_id, "esp32_devkit_v1", db)
    key = body.idempotency_key or f"manual:{secrets.token_urlsafe(18)}"
    existing=db.scalar(select(FallEvent).where(FallEvent.idempotency_key==key))
    if existing: return existing
    reading=None
    if body.sensor_reading:
        r=body.sensor_reading; reading=SensorReading(patient_id=body.patient_id,device_id=body.device_id,**r.model_dump(exclude_none=True),is_simulated=body.is_simulated); db.add(reading); db.flush()
    if body.event_type=="possible_fall" and reading is None: raise HTTPException(422,"possible_fall requires sensor_reading")
    item=FallEvent(patient_id=body.patient_id,device_id=body.device_id,sensor_reading_id=reading.id if reading else None,event_type=body.event_type,occurred_at=body.occurred_at or utcnow(),confidence=body.confidence,notes=body.notes,is_simulated=body.is_simulated,idempotency_key=key)
    db.add(item); db.flush(); db.add(NotificationDelivery(event_id=item.id,message=f"{body.event_type} alert",is_simulated=True,status="simulated")); db.commit(); db.refresh(item); return item


@router.get("/events")
def list_events(patient_id: UUID|None=None, event_type: EventType|None=None, event_status: EventStatus|None=Query(None, alias="status"), from_date: datetime|None=Query(None), to_date: datetime|None=Query(None), page_no: int=Query(1,alias="page",ge=1), page_size: int=Query(25,ge=1,le=100), user: User=Depends(current_user), db: Session=Depends(get_db)):
    q=select(FallEvent)
    if user.role=="caregiver": q=q.join(Patient,Patient.id==FallEvent.patient_id).where(Patient.caregiver_id==user.id)
    if patient_id: q=q.where(FallEvent.patient_id==patient_id)
    if event_type: q=q.where(FallEvent.event_type==event_type)
    if event_status: q=q.where(FallEvent.status==event_status)
    if from_date: q=q.where(FallEvent.occurred_at>=from_date)
    if to_date: q=q.where(FallEvent.occurred_at<=to_date)
    all_rows=db.scalars(q.order_by(FallEvent.occurred_at.desc())).all(); rows=all_rows[(page_no-1)*page_size:page_no*page_size]
    return page([EventOut.model_validate(x) for x in rows],page_no,page_size,len(all_rows))


@router.get("/events/{event_id}", response_model=EventOut)
def event(event_id: UUID, user: User=Depends(current_user), db: Session=Depends(get_db)):
    return visible_event(event_id, user, db)


@router.patch("/events/{event_id}/status", response_model=EventOut)
def event_status(event_id: UUID, body: EventStatusIn, user: User=Depends(current_user), db: Session=Depends(get_db)):
    item = visible_event(event_id, user, db)
    item.status = body.status
    if body.notes is not None:
        item.notes = body.notes
    if body.status=="acknowledged": item.acknowledged_by=user.id; item.acknowledged_at=utcnow()
    db.commit(); db.refresh(item); return item


@router.get("/events/{event_id}/camera-verifications")
def camera_list(event_id: UUID,user:User=Depends(current_user),db:Session=Depends(get_db)):
    visible_event(event_id, user, db)
    rows = db.scalars(select(CameraVerification).where(CameraVerification.event_id == event_id).order_by(CameraVerification.created_at.desc())).all()
    return {"items": [CameraOut.model_validate(row) for row in rows]}


@router.post("/events/{event_id}/camera-verifications",response_model=CameraOut,status_code=201)
def camera_create(event_id:UUID,body:CameraIn,user:User=Depends(current_user),db:Session=Depends(get_db)):
    event = visible_event(event_id, user, db)
    if body.device_id is not None:
        assigned_device(event.patient_id, body.device_id, "esp32_cam", db)
    item=CameraVerification(event_id=event_id,device_id=body.device_id,status=body.status,result=body.result,is_simulated=True,image_path=None,confidence=None)
    db.add(item);db.commit();db.refresh(item);return item


def simulation_event(body:SimulationIn,event_type:str,db:Session,user:User):
    patient_id,device_id=get_patient_device(body,db,user)
    if event_type=="possible_fall":
        reading=ReadingIn(acceleration_x=0.15,acceleration_y=0.22,acceleration_z=0.08,angular_velocity_x=2.4,angular_velocity_y=1.8,angular_velocity_z=3.0)
    else: reading=None
    event=create_event(EventIn(patient_id=patient_id,device_id=device_id,event_type=event_type,idempotency_key=body.idempotency_key,sensor_reading=reading,is_simulated=True),user,db)
    return event


@router.post("/simulation/normal-motion")
def normal_motion(body:SimulationIn=SimulationIn(),user:User=Depends(current_user),db:Session=Depends(get_db)):
    patient_id,device_id=get_patient_device(body,db,user); reading=SensorReading(patient_id=patient_id,device_id=device_id,acceleration_x=.01,acceleration_y=.02,acceleration_z=9.81,angular_velocity_x=.01,angular_velocity_y=.01,angular_velocity_z=.02,is_simulated=True);db.add(reading);db.commit();db.refresh(reading);return ReadingOut.model_validate(reading)


@router.post("/simulation/possible-fall",response_model=EventOut)
def possible_fall(body:SimulationIn=SimulationIn(),user:User=Depends(current_user),db:Session=Depends(get_db)):
    event=simulation_event(body,"possible_fall",db,user);db.add(CameraVerification(event_id=event.id,status="completed",result="No real image captured; simulated camera response",is_simulated=True));db.commit();return event


@router.post("/simulation/manual-emergency",response_model=EventOut)
def manual_emergency(body:SimulationIn=SimulationIn(),user:User=Depends(current_user),db:Session=Depends(get_db)): return simulation_event(body,"manual_emergency",db,user)


def camera_sim(body:SimulationIn, status_:str,user:User,db:Session):
    event=simulation_event(body,"possible_fall",db,user) if status_=="timeout" else simulation_event(body,"possible_fall",db,user)
    db.add(CameraVerification(event_id=event.id,status=status_,result="Simulated camera timeout" if status_=="timeout" else "No real image captured; simulated camera response",is_simulated=True));db.commit();return event


@router.post("/simulation/camera-response",response_model=EventOut)
def camera_response(body:SimulationIn=SimulationIn(),user:User=Depends(current_user),db:Session=Depends(get_db)): return camera_sim(body,"completed",user,db)


@router.post("/simulation/camera-timeout",response_model=EventOut)
def camera_timeout(body:SimulationIn=SimulationIn(),user:User=Depends(current_user),db:Session=Depends(get_db)): return camera_sim(body,"timeout",user,db)
