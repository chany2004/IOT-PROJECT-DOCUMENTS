"""Read USB camera diagnostics; report integrity metrics, never save images."""
import argparse
import io
import json
import time

import serial
from PIL import Image


def read_exact(port, size):
    data = bytearray()
    deadline = time.monotonic() + 35
    while len(data) < size and time.monotonic() < deadline:
        data.extend(port.read(size - len(data)))
    if len(data) != size:
        raise RuntimeError("Incomplete USB frame")
    return bytes(data)


def frame_metrics(data, expected_hash, frame_format="jpeg", width=0, height=0):
    checksum = 2166136261
    for value in data:
        checksum = ((checksum ^ value) * 16777619) & 0xFFFFFFFF
    metrics = {"usb_integrity": checksum == expected_hash, "decoded": False, "usable": False}
    if frame_format == "rgb565":
        metrics.update(raw_size_ok=len(data) == width * height * 2, raw_distinct_bytes=len(set(data)))
        return metrics
    if not metrics["usb_integrity"] or not data.startswith(b"\xff\xd8") or not data.endswith(b"\xff\xd9"):
        return metrics
    try:
        with Image.open(io.BytesIO(data)) as source:
            image = source.convert("RGB")
            width, height = image.size
            pixels = image.load()
            columns = [round((width - 1) * i / 31) for i in range(32)]
            gray_rows = 0
            for y in range(height - 1, -1, -1):
                if not all(pixels[x, y] == (128, 128, 128) for x in columns):
                    break
                gray_rows += 1
            upper_detail = any(pixels[x, int(height * 0.3)] != (128, 128, 128) for x in columns)
            gray_tail = gray_rows >= max(12, (height + 19) // 20) and upper_detail
            metrics.update(decoded=True, dimensions=f"{width}x{height}", gray_tail_rows=gray_rows, usable=not gray_tail)
    except (OSError, ValueError):
        pass
    return metrics


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--port", default="COM5")
    parser.add_argument("--raw", action="store_true")
    args = parser.parse_args()
    port = serial.Serial(port=None, baudrate=115200, timeout=0.5)
    port.dtr = False
    port.rts = False
    port.port = args.port
    with port:
        time.sleep(1)
        port.write(b"RAW\n" if args.raw else b"START\n")
        deadline = time.monotonic() + 180
        while time.monotonic() < deadline:
            line = port.readline().decode("utf-8", "replace").strip()
            if line.startswith("DIAG_FRAME "):
                result = json.loads(line[len("DIAG_FRAME "):])
                size = result["bytes"]
                if size > 500000:
                    raise RuntimeError("Unexpected frame length")
                if size:
                    result.update(frame_metrics(read_exact(port, size), result.pop("hash"),
                                                result.get("format", "jpeg"), result.get("width", 0), result.get("height", 0)))
                else:
                    result.pop("hash")
                    result.update(decoded=False, usable=False)
                print("DIAG_RESULT " + json.dumps(result), flush=True)
            elif line == "DIAG_DONE":
                print(line, flush=True)
                return
            elif line.startswith(("DIAG_BEGIN ", "DIAG_INIT ", "DIAG_SIGNALS ", "DIAG_COLORBAR ")):
                print(line, flush=True)
        raise RuntimeError("Diagnostic did not finish")


if __name__ == "__main__":
    main()
