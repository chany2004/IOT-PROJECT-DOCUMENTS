"""Private local bring-up check; configuration comes via stdin, never logged.

Takes two authenticated still images, without creating an incident, uploading
to a backend, or changing Wi-Fi. A sample is saved only when --sample is given.
"""
import argparse
import ast
import http.client
import io
import json
from pathlib import Path
import re
import sys
import time

from PIL import Image
from test_camera_serial import frame_metrics

EXPECTED_FIRMWARE = "safe-vga8-q14-fresh-20261008"
MAX_IMAGE_BYTES = 500000


def get_health(address):
    connection = http.client.HTTPConnection(address, timeout=5)
    try:
        connection.request("GET", "/health", headers={"Connection": "close"})
        response = connection.getresponse()
        if response.status != 200:
            raise RuntimeError("Health status rejected")
        return json.loads(response.read(4096))
    finally:
        connection.close()


def capture(address, key):
    connection = http.client.HTTPConnection(address, timeout=12)
    started = time.monotonic()
    try:
        connection.request("GET", "/capture", headers={"X-Camera-Key": key, "Connection": "close"})
        response = connection.getresponse()
        length = int(response.getheader("Content-Length", "0"))
        if response.status != 200 or response.getheader("Content-Type", "").split(";")[0] != "image/jpeg":
            raise RuntimeError("Capture status/type rejected")
        if not 4 <= length <= MAX_IMAGE_BYTES:
            raise RuntimeError("Capture length rejected")
        request_us = int(response.getheader("X-Camera-Request-Us", "-1"))
        frame_us = int(response.getheader("X-Camera-Frame-Us", "-1"))
        frame_age_ms = int(response.getheader("X-Camera-Frame-Age-Ms", "-1"))
        discarded = int(response.getheader("X-Camera-Discarded-Frames", "-1"))
        if not (0 < request_us <= frame_us and 0 <= frame_age_ms < 2000 and 0 <= discarded < 3):
            raise RuntimeError("Capture freshness rejected")
        payload = response.read(MAX_IMAGE_BYTES + 1)
        if len(payload) != length:
            raise RuntimeError("Capture incomplete")
        if not payload.startswith(b"\xff\xd8") or not payload.endswith(b"\xff\xd9"):
            raise RuntimeError("Capture framing rejected")
        with Image.open(io.BytesIO(payload)) as source:
            source.verify()
        with Image.open(io.BytesIO(payload)) as source:
            source.load()
            dimensions = source.size
        # Reuse the project's existing decoder + known gray-bottom detector.
        checksum = 2166136261
        for value in payload:
            checksum = ((checksum ^ value) * 16777619) & 0xFFFFFFFF
        metrics = frame_metrics(payload, checksum)
        result = {"http_status": 200, "declared_bytes": length,
                  "received_bytes": len(payload), "dimensions": list(dimensions),
                  "decoded": metrics["decoded"], "usable": metrics["usable"],
                  "gray_tail_rows": metrics.get("gray_tail_rows"),
                  "request_us": request_us, "frame_us": frame_us,
                  "frame_age_ms": frame_age_ms, "discarded_frames": discarded,
                  "elapsed_ms": round((time.monotonic() - started) * 1000)}
        return payload, result
    finally:
        connection.close()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--address", required=True)
    parser.add_argument("--sample")
    args = parser.parse_args()
    if not re.fullmatch(r"192\.168\.137\.\d{1,3}", args.address):
        raise RuntimeError("Unexpected camera address")
    match = re.search(r'^\s*#define\s+CAMERA_NODE_KEY\s+("[^\r\n]+")\s*$', sys.stdin.read(), re.MULTILINE)
    if not match:
        raise RuntimeError("Camera key missing")
    key = ast.literal_eval(match[1])
    if not isinstance(key, str) or not 32 <= len(key) <= 256 or "\r" in key or "\n" in key:
        raise RuntimeError("Camera key invalid")
    before = get_health(args.address)
    if before.get("firmware") != EXPECTED_FIRMWARE or not before.get("initialized") or \
            before.get("flash_enabled") or before.get("power_guard") or not before.get("camera_lock_ready"):
        raise RuntimeError("Camera safety state rejected")
    expected_dimensions = [640, 480] if before.get("profile") == "vga" else [320, 240]
    all_valid = True
    previous_frame_us = 0
    for number in (1, 2):
        payload, result = capture(args.address, key)
        result["frame"] = number
        result["passed"] = result["decoded"] and result["usable"] and result["dimensions"] == expected_dimensions \
            and result["frame_us"] > previous_frame_us
        previous_frame_us = result["frame_us"]
        print("SNAPSHOT_RESULT " + json.dumps(result), flush=True)
        all_valid = all_valid and result["passed"]
        if number == 1 and args.sample and result["passed"]:
            destination = Path(args.sample).resolve()
            private_root = (Path(__file__).resolve().parents[1] / "patient-monitor" / ".runtime" /
                            "esp32cam-bringup-20261008-8c8c29ee3514").resolve()
            if destination.parent != private_root or destination.name != "first-snapshot.jpg":
                raise RuntimeError("Sample destination rejected")
            # Never replace an existing image; inherit the secured directory ACL.
            with destination.open("xb") as target:
                target.write(payload)
            print("PRIVATE_SAMPLE_SAVED=true", flush=True)
        if number == 1:
            time.sleep(2)
    after = get_health(args.address)
    state = {key: after.get(key) for key in ("last_capture_ok", "ov2640_ready", "uptime_ms",
                                            "flash_enabled", "power_guard", "recoveries")}
    print("POST_CAPTURE_HEALTH " + json.dumps(state), flush=True)
    stable = after.get("uptime_ms", 0) > before.get("uptime_ms", 0) and after.get("last_capture_ok") and \
        after.get("ov2640_ready") and not after.get("power_guard") and not after.get("flash_enabled")
    print("CAMERA_SNAPSHOT_TEST_PASSED=" + str(bool(all_valid and stable)), flush=True)
    return 0 if all_valid and stable else 1


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as error:
        # Raw exceptions/headers/configuration may contain credentials: type only.
        print("SNAPSHOT_CHECK_FAILED_TYPE=" + type(error).__name__, flush=True)
        sys.exit(1)
