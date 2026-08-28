import copy
import importlib.util
import json
from http.server import ThreadingHTTPServer
from pathlib import Path
import threading
import unittest
from urllib.error import HTTPError
from urllib.request import Request, urlopen


DASHBOARD_DIR = Path(__file__).resolve().parents[1]
PROJECT_DIR = DASHBOARD_DIR.parent
SERVER_PATH = DASHBOARD_DIR / "server.py"

SPEC = importlib.util.spec_from_file_location("fallguard_live_server", SERVER_PATH)
bridge = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(bridge)
INITIAL_STATE = copy.deepcopy(bridge.STATE)


def reset_bridge_state():
    with bridge.LOCK:
        bridge.STATE.clear()
        bridge.STATE.update(copy.deepcopy(INITIAL_STATE))
        bridge.SEEN.clear()


class QuietHandler(bridge.Handler):
    def log_message(self, _format, *_args):
        pass


class LiveBridgeHttpTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server = ThreadingHTTPServer(("127.0.0.1", 0), QuietHandler)
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()
        cls.base_url = f"http://127.0.0.1:{cls.server.server_port}"

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        cls.thread.join(timeout=2)

    def setUp(self):
        reset_bridge_state()

    def request(self, path, *, method="GET", body=b""):
        request = Request(
            self.base_url + path,
            data=body if method == "POST" else None,
            method=method,
            headers={"Content-Type": "text/plain"},
        )
        return urlopen(request, timeout=2)

    def live(self):
        with self.request("/api/live") as response:
            self.assertEqual(response.status, 200)
            self.assertEqual(response.headers["Access-Control-Allow-Origin"], "*")
            self.assertEqual(response.headers["Cache-Control"], "no-store")
            state = json.load(response)
            self.assertEqual(
                state["server_id"], "fallguard-esp32-tinkered-workspace-1"
            )
            return state

    def test_serves_the_current_dashboard_directory(self):
        expected = (DASHBOARD_DIR / "index.html").read_bytes()
        with self.request("/") as response:
            self.assertEqual(response.status, 200)
            self.assertIn("text/html", response.headers["Content-Type"])
            self.assertEqual(response.read(), expected)

    def test_structured_ingest_is_live_and_latches_a_fall_until_clear(self):
        frame = {
            "seq": 7,
            "uptime_ms": 2500,
            "mpu": True,
            "ax": 0.1,
            "ay": 0.2,
            "az": 2.7,
            "gx": 10.0,
            "gy": 20.0,
            "gz": 310.0,
            "accel": 2.71,
            "gyro": 310.8,
            "fall": True,
            "fall_count": 1,
            "fall_state": "CONFIRMED",
            "alarm": "Fall",
            "evidence": "IMPACT_AND_ROTATION",
        }
        payload = f"@@FG1:{json.dumps(frame, separators=(',', ':'))}:FG@@".encode()

        with self.request("/ingest", method="POST", body=payload) as response:
            self.assertEqual(response.status, 204)

        state = self.live()
        self.assertTrue(state["connected"])
        self.assertTrue(state["bridge_connected"])
        self.assertTrue(state["fall_detected"])
        self.assertTrue(state["fall_latch_active"])
        self.assertEqual(state["fall_count"], 1)
        self.assertEqual(state["fall_source"], "FIRMWARE")
        self.assertAlmostEqual(state["accel"], 2.71)
        self.assertAlmostEqual(state["gyro"], 310.8)
        self.assertEqual(state["events"][0]["type"], "FALL_DETECTED")

        with self.request("/api/clear", method="POST") as response:
            self.assertEqual(response.status, 204)

        cleared = self.live()
        self.assertFalse(cleared["fall_detected"])
        self.assertFalse(cleared["fall_latch_active"])
        self.assertEqual(cleared["fall_count"], 0)
        self.assertEqual(cleared["events"], [])

        # Firmware may keep its fall flag asserted for several packets. The
        # acknowledged incident must stay cleared until a new edge or count.
        with self.request("/ingest", method="POST", body=payload) as response:
            self.assertEqual(response.status, 204)
        still_cleared = self.live()
        self.assertFalse(still_cleared["fall_detected"])
        self.assertFalse(still_cleared["incident_active"])
        self.assertEqual(still_cleared["incident_type"], "NONE")

    def test_valid_tinkered_ui_packet_updates_real_sensor_status(self):
        payload = b"[TINKERED_UI] valid=1 ax=0.05 ay=-0.10 az=1.02"
        with self.request("/ingest", method="POST", body=payload) as response:
            self.assertEqual(response.status, 204)

        state = self.live()
        self.assertTrue(state["connected"])
        self.assertTrue(state["bridge_connected"])
        self.assertEqual(state["bridge_protocol"], "V1.2")
        self.assertTrue(state["mpu"])
        self.assertEqual(state["decision_mode"], "TINKERED_ACCEL")
        self.assertAlmostEqual(state["ax"], 0.05)
        self.assertAlmostEqual(state["ay"], -0.10)
        self.assertAlmostEqual(state["az"], 1.02)

    def test_strong_tinkered_accel_impact_latches_fall_before_baseline(self):
        payload = b"[TINKERED_UI] valid=1 ax=-1.85 ay=-1.92 az=-1.98"
        with self.request("/ingest", method="POST", body=payload) as response:
            self.assertEqual(response.status, 204)

        state = self.live()
        self.assertTrue(state["connected"])
        self.assertTrue(state["bridge_connected"])
        self.assertEqual(state["decision_mode"], "TINKERED_ACCEL")
        self.assertGreater(state["accel"], 3.3)
        self.assertGreater(state["secondary_value"], 35.0)
        self.assertTrue(state["impact_met"])
        self.assertTrue(state["secondary_met"])
        self.assertTrue(state["fall_detected"])
        self.assertTrue(state["incident_active"])
        self.assertEqual(state["incident_type"], "FALL_DETECTED")
        self.assertEqual(state["fall_count"], 1)
        self.assertEqual(state["events"][0]["source"], "TINKERED_ACCEL")

    def test_source_code_text_cannot_create_a_false_runtime_fall(self):
        source_text = b'Serial.println("LOCAL_ALARM_START: FALL_DETECTED");'
        with self.request("/ingest", method="POST", body=source_text) as response:
            self.assertEqual(response.status, 204)

        state = self.live()
        self.assertFalse(state["fall_detected"])
        self.assertEqual(state["fall_count"], 0)

    def test_current_firmware_fall_and_button_lines_are_forward_compatible(self):
        lines = (
            b"[FALL] Fall signature detected: impact and rotation threshold met.\n"
            b"[BUTTON] Emergency button pressed."
        )
        with self.request("/ingest", method="POST", body=lines) as response:
            self.assertEqual(response.status, 204)

        state = self.live()
        self.assertTrue(state["connected"])
        self.assertTrue(state["fall_detected"])
        self.assertEqual(state["fall_count"], 1)
        self.assertEqual(
            [event["type"] for event in state["events"]],
            ["EMERGENCY_BUTTON", "FALL_DETECTED"],
        )

    def test_emergency_is_latched_as_an_incident_without_becoming_a_fall(self):
        line = b"[BUTTON] Emergency button pressed."
        with self.request("/ingest", method="POST", body=line) as response:
            self.assertEqual(response.status, 204)

        state = self.live()
        self.assertTrue(state["connected"])
        self.assertTrue(state["bridge_connected"])
        self.assertTrue(state["emergency_active"])
        self.assertTrue(state["incident_active"])
        self.assertEqual(state["incident_type"], "EMERGENCY_BUTTON")
        self.assertIn("Emergency button pressed", state["incident_reason"])
        self.assertGreater(state["incident_time_ms"], 0)
        self.assertEqual(state["alarm"], "Emergency")
        self.assertFalse(state["fall_detected"])
        self.assertEqual(state["fall_count"], 0)
        self.assertEqual(state["events"][0]["type"], "EMERGENCY_BUTTON")
        self.assertEqual(state["events"][0]["source"], "FIRMWARE")

        with self.request("/api/clear", method="POST") as response:
            self.assertEqual(response.status, 204)

        cleared = self.live()
        self.assertFalse(cleared["emergency_active"])
        self.assertFalse(cleared["incident_active"])
        self.assertEqual(cleared["incident_type"], "NONE")
        self.assertEqual(cleared["incident_reason"], "")
        self.assertEqual(cleared["incident_time_ms"], 0)
        self.assertFalse(cleared["fall_detected"])
        self.assertEqual(cleared["events"], [])

    def test_generic_incident_metadata_tracks_the_latest_fall_or_emergency(self):
        frame = {
            "seq": 1,
            "uptime_ms": 1000,
            "mpu": True,
            "ax": 0.0,
            "ay": 0.0,
            "az": 2.5,
            "gx": 0.0,
            "gy": 0.0,
            "gz": 240.0,
            "accel": 2.5,
            "gyro": 240.0,
            "fall": True,
            "fall_count": 1,
            "fall_state": "CONFIRMED",
            "alarm": "Fall",
            "evidence": "IMPACT_AND_ROTATION",
        }
        payload = f"@@FG1:{json.dumps(frame, separators=(',', ':'))}:FG@@".encode()
        with self.request("/ingest", method="POST", body=payload) as response:
            self.assertEqual(response.status, 204)

        fall_state = self.live()
        self.assertTrue(fall_state["fall_detected"])
        self.assertFalse(fall_state["emergency_active"])
        self.assertTrue(fall_state["incident_active"])
        self.assertEqual(fall_state["incident_type"], "FALL_DETECTED")
        self.assertEqual(fall_state["incident_time_ms"], fall_state["last_fall_ms"])
        self.assertIn("Firmware confirmed fall", fall_state["incident_reason"])

        with self.request(
            "/ingest", method="POST", body=b"[BUTTON] Emergency button pressed."
        ) as response:
            self.assertEqual(response.status, 204)

        combined = self.live()
        self.assertTrue(combined["fall_detected"])
        self.assertTrue(combined["emergency_active"])
        self.assertTrue(combined["incident_active"])
        self.assertEqual(combined["incident_type"], "EMERGENCY_BUTTON")
        self.assertIn("Emergency button pressed", combined["incident_reason"])
        self.assertEqual(combined["alarm"], "Emergency")

    def test_acknowledge_unlatches_incidents_but_preserves_session_history(self):
        lines = (
            b"[FALL] Fall signature detected: impact and rotation threshold met.\n"
            b"[BUTTON] Emergency button pressed."
        )
        with self.request("/ingest", method="POST", body=lines) as response:
            self.assertEqual(response.status, 204)

        active = self.live()
        self.assertTrue(active["fall_detected"])
        self.assertTrue(active["emergency_active"])
        self.assertEqual(active["fall_count"], 1)
        self.assertEqual(len(active["events"]), 2)

        with self.request("/api/acknowledge", method="POST") as response:
            self.assertEqual(response.status, 204)

        acknowledged = self.live()
        self.assertFalse(acknowledged["fall_detected"])
        self.assertFalse(acknowledged["fall_latch_active"])
        self.assertFalse(acknowledged["emergency_active"])
        self.assertFalse(acknowledged["incident_active"])
        self.assertEqual(acknowledged["incident_type"], "NONE")
        self.assertEqual(acknowledged["incident_reason"], "")
        self.assertEqual(acknowledged["incident_time_ms"], 0)
        self.assertEqual(acknowledged["alarm"], "Idle")
        self.assertEqual(acknowledged["fall_count"], 1)
        self.assertEqual(
            [event["type"] for event in acknowledged["events"]],
            ["EMERGENCY_BUTTON", "FALL_DETECTED"],
        )

    def test_event_history_clear_requires_acknowledgement_and_keeps_live_data(self):
        imu = (
            b"[IMU] a[g]=0.100 0.200 2.500 | g[dps]=1.0 2.0 240.0 "
            b"| magA=2.510 (f=2.500) magG=240.0 (f=240.0)\n"
            b"[FALL] Fall signature detected: impact and rotation threshold met."
        )
        with self.request("/ingest", method="POST", body=imu) as response:
            self.assertEqual(response.status, 204)

        with self.assertRaises(HTTPError) as active_clear:
            self.request("/api/events/clear", method="POST")
        self.assertEqual(active_clear.exception.code, 409)

        unchanged = self.live()
        self.assertTrue(unchanged["incident_active"])
        self.assertEqual(unchanged["fall_count"], 1)
        self.assertEqual(len(unchanged["events"]), 1)

        with self.request("/api/acknowledge", method="POST") as response:
            self.assertEqual(response.status, 204)
        with self.request("/api/events/clear", method="POST") as response:
            self.assertEqual(response.status, 204)

        cleared = self.live()
        self.assertFalse(cleared["incident_active"])
        self.assertEqual(cleared["fall_count"], 0)
        self.assertEqual(cleared["events"], [])
        self.assertAlmostEqual(cleared["accel"], 2.510)
        self.assertAlmostEqual(cleared["gyro"], 240.0)
        self.assertAlmostEqual(cleared["ax"], 0.100)

    def test_rejects_oversized_and_unknown_posts(self):
        with self.assertRaises(HTTPError) as oversized:
            self.request("/ingest", method="POST", body=b"x" * 16385)
        self.assertEqual(oversized.exception.code, 413)

        with self.assertRaises(HTTPError) as unknown:
            self.request("/not-a-route", method="POST", body=b"x")
        self.assertEqual(unknown.exception.code, 404)


class ExtensionContractTests(unittest.TestCase):
    def test_manifest_connects_tinkered_to_the_local_side_panel(self):
        manifest = json.loads(
            (DASHBOARD_DIR / "extension" / "manifest.json").read_text(encoding="utf-8")
        )
        self.assertEqual(manifest["manifest_version"], 3)
        self.assertEqual(manifest["side_panel"]["default_path"], "panel.html")
        self.assertIn("https://tinkered.ai/*", manifest["host_permissions"])
        self.assertIn("http://127.0.0.1/*", manifest["host_permissions"])
        scripts = manifest["content_scripts"]
        self.assertTrue(any("content.js" in item["js"] for item in scripts))
        self.assertTrue(any("dashboard-clock.js" in item["js"] for item in scripts))

    def test_extension_and_launcher_use_the_live_bridge_contract(self):
        background = (DASHBOARD_DIR / "extension" / "background.js").read_text(
            encoding="utf-8"
        )
        content = (DASHBOARD_DIR / "extension" / "content.js").read_text(
            encoding="utf-8"
        )
        panel = (DASHBOARD_DIR / "extension" / "panel.html").read_text(
            encoding="utf-8"
        )
        launcher = (PROJECT_DIR / "start-dashboard.cmd").read_text(encoding="utf-8")

        self.assertIn("http://127.0.0.1:8765/ingest", background)
        self.assertIn("fallguard-telemetry", background)
        self.assertIn("Fall signature detected", background)
        self.assertIn("Emergency button pressed", background)
        self.assertIn("[TINKERED_UI] valid=1", content)
        self.assertIn("Fall signature detected", content)
        self.assertIn("Emergency button pressed", content)
        self.assertIn("http://127.0.0.1:8765/?view=sidepanel", panel)
        self.assertIn("dashboard\\server.py", launcher)
        self.assertIn("http://127.0.0.1:8765/api/live", launcher)
        self.assertIn("fallguard-esp32-tinkered-workspace-1", launcher)
        self.assertIn("different FallGuard dashboard", launcher)


if __name__ == "__main__":
    unittest.main()
