-- Synthetic development fixtures only. Run after `alembic upgrade head`.
-- The API seed routine is preferred because it creates login-compatible Argon2
-- password hashes from SEED_ADMIN_PASSWORD and SEED_CAREGIVER_PASSWORD.
BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- These IDs make the fixture safe to re-run. Passwords are intentionally marked
-- unusable; use the API seed routine for accounts that must log in.
INSERT INTO users (id, email, full_name, password_hash, role, is_active, is_synthetic)
VALUES
 ('00000000-0000-0000-0000-000000000001', 'admin@example.com', 'Synthetic Administrator', 'SEE_API_SEED_ROUTINE', 'administrator', true, true),
 ('00000000-0000-0000-0000-000000000002', 'caregiver@example.com', 'Synthetic Caregiver', 'SEE_API_SEED_ROUTINE', 'caregiver', true, true)
ON CONFLICT (email) DO NOTHING;

INSERT INTO rooms (id, name, floor, description, is_synthetic)
VALUES ('00000000-0000-0000-0000-000000000010', 'Room 101', '1', 'Synthetic room for local testing', true)
ON CONFLICT (name) DO NOTHING;

INSERT INTO patients (id, full_name, age, room_id, caregiver_id, notes, emergency_contact, is_synthetic)
VALUES ('00000000-0000-0000-0000-000000000020', 'Synthetic Patient', 72,
        '00000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000002',
        'Generated fixture; not a real patient.', 'Synthetic contact', true)
ON CONFLICT (id) DO NOTHING;

INSERT INTO devices (id, name, serial_number, device_type, status, device_key_hash, is_synthetic)
VALUES
 ('00000000-0000-0000-0000-000000000030', 'Synthetic Motion Sensor', 'SIM-ESP32-001', 'esp32_devkit_v1', 'simulated', encode(digest('synthetic-device-key-1', 'sha256'), 'hex'), true),
 ('00000000-0000-0000-0000-000000000031', 'Synthetic Camera', 'SIM-CAM-001', 'esp32_cam', 'simulated', encode(digest('synthetic-device-key-2', 'sha256'), 'hex'), true)
ON CONFLICT (serial_number) DO NOTHING;

INSERT INTO device_assignments (id, device_id, patient_id, assigned_at)
VALUES
 ('00000000-0000-0000-0000-000000000040', '00000000-0000-0000-0000-000000000030', '00000000-0000-0000-0000-000000000020', now()),
 ('00000000-0000-0000-0000-000000000041', '00000000-0000-0000-0000-000000000031', '00000000-0000-0000-0000-000000000020', now())
ON CONFLICT (id) DO NOTHING;

INSERT INTO sensor_readings (id, patient_id, device_id, recorded_at, acceleration_x, acceleration_y, acceleration_z, angular_velocity_x, angular_velocity_y, angular_velocity_z, is_simulated)
VALUES ('00000000-0000-0000-0000-000000000050', '00000000-0000-0000-0000-000000000020', '00000000-0000-0000-0000-000000000030', now() - interval '5 minutes', 0.14, 0.05, 9.62, 1.2, 0.4, 0.1, true)
ON CONFLICT (id) DO NOTHING;

INSERT INTO fall_events (id, patient_id, device_id, sensor_reading_id, event_type, status, occurred_at, confidence, notes, is_simulated, idempotency_key, updated_at)
VALUES
 ('00000000-0000-0000-0000-000000000060', '00000000-0000-0000-0000-000000000020', '00000000-0000-0000-0000-000000000030', '00000000-0000-0000-0000-000000000050', 'possible_fall', 'pending', now() - interval '4 minutes', NULL, 'Synthetic possible-fall fixture.', true, 'seed-possible-fall-001', now()),
 ('00000000-0000-0000-0000-000000000061', '00000000-0000-0000-0000-000000000020', '00000000-0000-0000-0000-000000000030', NULL, 'manual_emergency', 'acknowledged', now() - interval '2 minutes', NULL, 'Synthetic manual emergency fixture.', true, 'seed-manual-emergency-001', now())
ON CONFLICT (id) DO NOTHING;

INSERT INTO camera_verifications (id, event_id, device_id, status, result, is_simulated, image_path, confidence, verified_at)
VALUES ('00000000-0000-0000-0000-000000000070', '00000000-0000-0000-0000-000000000060', '00000000-0000-0000-0000-000000000031', 'completed', 'SIMULATED CAMERA RESPONSE: no image captured in local development.', true, NULL, NULL, now())
ON CONFLICT (id) DO NOTHING;

INSERT INTO notification_deliveries (id, event_id, user_id, channel, status, is_simulated, message, delivered_at)
VALUES ('00000000-0000-0000-0000-000000000080', '00000000-0000-0000-0000-000000000060', '00000000-0000-0000-0000-000000000002', 'dashboard', 'simulated', true, 'Synthetic possible-fall alert', now())
ON CONFLICT (id) DO NOTHING;

COMMIT;
