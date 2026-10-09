from __future__ import annotations

import io
import os
import secrets
from functools import lru_cache

import numpy as np
from fastapi import FastAPI, Header, HTTPException, Request
from PIL import Image, UnidentifiedImageError
from ultralytics import YOLO

app = FastAPI(title='Patient Fall Camera Verifier', version='1.0')

MODEL_PATH = os.getenv('POSE_MODEL', 'yolo11n-pose.pt')
MIN_PERSON_CONFIDENCE = float(os.getenv('MIN_PERSON_CONFIDENCE', '0.45'))
FALL_CONFIDENCE = float(os.getenv('FALL_CONFIDENCE', '0.60'))
MAX_IMAGE_BYTES = int(os.getenv('MAX_IMAGE_BYTES', '500000'))
API_KEY = os.getenv('FALL_API_KEY', '')


@lru_cache(maxsize=1)
def model() -> YOLO:
    return YOLO(MODEL_PATH)


@app.on_event('startup')
def warm_up_model() -> None:
    blank = np.zeros((320, 320, 3), dtype=np.uint8)
    model().predict(blank, verbose=False)


def classify_pose(image: Image.Image) -> tuple[str, float, str]:
    predictions = model().predict(np.asarray(image.convert('RGB')), verbose=False)
    if not predictions or predictions[0].boxes is None:
        return 'uncertain', 0.0, 'no_person_detected'
    result = predictions[0]
    if len(result.boxes) == 0:
        return 'uncertain', 0.0, 'no_person_detected'
    confidences = result.boxes.conf.cpu().numpy()
    best = int(np.argmax(confidences))
    person_confidence = float(confidences[best])
    if person_confidence < MIN_PERSON_CONFIDENCE:
        return 'uncertain', person_confidence, 'low_person_confidence'
    x1, y1, x2, y2 = result.boxes.xyxy[best].cpu().numpy()
    width = max(float(x2 - x1), 1.0)
    height = max(float(y2 - y1), 1.0)
    aspect = width / height
    keypoints = result.keypoints
    if keypoints is None or keypoints.xy is None:
        return 'uncertain', person_confidence, 'pose_keypoints_missing'
    xy = keypoints.xy[best].cpu().numpy()
    conf = keypoints.conf[best].cpu().numpy() if keypoints.conf is not None else None
    required = (5, 6, 11, 12)
    if conf is None or any(float(conf[index]) < 0.35 for index in required):
        return 'uncertain', person_confidence, 'torso_keypoints_uncertain'
    shoulder = (xy[5] + xy[6]) / 2.0
    hip = (xy[11] + xy[12]) / 2.0
    torso_dx = abs(float(hip[0] - shoulder[0]))
    torso_dy = abs(float(hip[1] - shoulder[1]))
    horizontal = torso_dx / max(torso_dx + torso_dy, 1.0)
    aspect_score = min(max((aspect - 0.75) / 0.75, 0.0), 1.0)
    confidence = person_confidence * (0.6 * horizontal + 0.4 * aspect_score)
    if aspect >= 1.05 and horizontal >= 0.55 and confidence >= FALL_CONFIDENCE:
        return 'fall', round(confidence, 3), 'horizontal_lying_pose'
    no_fall_confidence = max(person_confidence - confidence * 0.35, 0.0)
    return 'no_fall', round(no_fall_confidence, 3), 'upright_or_non_fall_pose'


@app.get('/health')
def health() -> dict[str, str]:
    return {
        'status': 'ok',
        'authentication': 'configured' if API_KEY else 'missing',
    }


@app.post('/verify-fall')
async def verify_fall(
    request: Request,
    x_device_id: str | None = Header(default=None),
    x_api_key: str | None = Header(default=None),
) -> dict[str, object]:
    if not API_KEY:
        raise HTTPException(503, 'Server API key is not configured')
    if not secrets.compare_digest(x_api_key or '', API_KEY):
        raise HTTPException(401, 'Invalid API key')
    content_type = request.headers.get('content-type', '').split(';', 1)[0]
    if content_type != 'image/jpeg':
        raise HTTPException(415, 'Send a raw JPEG body')
    body = await request.body()
    if not body or len(body) > MAX_IMAGE_BYTES:
        raise HTTPException(413, 'Image is empty or too large')
    try:
        image = Image.open(io.BytesIO(body))
        image.verify()
        image = Image.open(io.BytesIO(body))
    except (UnidentifiedImageError, OSError) as exc:
        raise HTTPException(400, 'Invalid JPEG') from exc
    verdict, confidence, reason = classify_pose(image)
    return {
        'verdict': verdict,
        'confidence': confidence,
        'reason': reason,
        'device_id': x_device_id,
    }
