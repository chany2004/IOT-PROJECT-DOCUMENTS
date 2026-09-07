"""Idempotent synthetic seed for local development: python -m app.seed"""
from sqlalchemy import select
from app.database import Base, SessionLocal, engine
from app.models import *
from app.auth.security import hash_password, hash_device_key
from app.config import get_settings

def seed():
    Base.metadata.create_all(engine)
    settings = get_settings()
    with SessionLocal() as db:
        admin=db.scalar(select(User).where(User.email=="admin@example.com"))
        if not admin:
            admin=User(email="admin@example.com",full_name="Synthetic Administrator",role="administrator",password_hash=hash_password(settings.seed_admin_password),is_synthetic=True); db.add(admin)
        elif admin.is_synthetic:
            admin.password_hash = hash_password(settings.seed_admin_password)
        caregiver=db.scalar(select(User).where(User.email=="caregiver@example.com"))
        if not caregiver:
            caregiver=User(email="caregiver@example.com",full_name="Synthetic Caregiver",role="caregiver",password_hash=hash_password(settings.seed_caregiver_password),is_synthetic=True); db.add(caregiver)
        elif caregiver.is_synthetic:
            caregiver.password_hash = hash_password(settings.seed_caregiver_password)
        db.flush()
        room=db.scalar(select(Room).where(Room.name=="Synthetic Room 101"))
        if not room: room=Room(name="Synthetic Room 101",floor="1",description="Synthetic seed room",is_synthetic=True);db.add(room);db.flush()
        patient=db.scalar(select(Patient).where(Patient.full_name=="Synthetic Patient"))
        if not patient: patient=Patient(full_name="Synthetic Patient",age=72,room_id=room.id,caregiver_id=caregiver.id,notes="All records are synthetic.",is_synthetic=True);db.add(patient);db.flush()
        dev=db.scalar(select(Device).where(Device.serial_number=="SYNTH-DEVKIT-001"))
        if not dev: dev=Device(name="Synthetic motion sensor",serial_number="SYNTH-DEVKIT-001",device_type="esp32_devkit_v1",status="simulated",device_key_hash=hash_device_key("synthetic-devkit-key"),is_synthetic=True);db.add(dev);db.flush()
        cam=db.scalar(select(Device).where(Device.serial_number=="SYNTH-CAM-001"))
        if not cam: cam=Device(name="Synthetic camera",serial_number="SYNTH-CAM-001",device_type="esp32_cam",status="simulated",device_key_hash=hash_device_key("synthetic-cam-key"),is_synthetic=True);db.add(cam);db.flush()
        if not db.scalar(select(DeviceAssignment).where(DeviceAssignment.device_id==dev.id,DeviceAssignment.ended_at.is_(None))): db.add(DeviceAssignment(device_id=dev.id,patient_id=patient.id))
        if not db.scalar(select(DeviceAssignment).where(DeviceAssignment.device_id==cam.id,DeviceAssignment.ended_at.is_(None))): db.add(DeviceAssignment(device_id=cam.id,patient_id=patient.id))
        db.flush()
        if not db.scalar(select(SensorReading).where(SensorReading.patient_id==patient.id)):
            db.add(SensorReading(patient_id=patient.id,device_id=dev.id,acceleration_x=.02,acceleration_y=.01,acceleration_z=9.8,angular_velocity_x=.01,angular_velocity_y=.02,angular_velocity_z=.01,is_simulated=True))
        db.commit()
        print("Seeded synthetic accounts: admin@example.com and caregiver@example.com (passwords come from SEED_*_PASSWORD).")

if __name__ == "__main__": seed()
