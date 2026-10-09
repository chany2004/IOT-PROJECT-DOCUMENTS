# Patient Watch: Laravel + PostgreSQL monitor

This is the actual-hardware web layer for the SEP 7 Wi-Fi Connected Patient-End Fall Detection System. Laravel owns the caregiver dashboard and PostgreSQL records. FastAPI on port 8000 is the optional camera-analysis service; Laravel runs on port 8085. There is no simulation lab, event generator, source selector, or setting that enables synthetic events.

## Start locally

From the patient-monitor folder in PowerShell:

    composer install
    powershell -ExecutionPolicy Bypass -File .\scripts\setup-local.ps1
    powershell -ExecutionPolicy Bypass -File .\scripts\start-local.ps1

Open http://127.0.0.1:8085. The setup command creates an isolated PostgreSQL cluster on 127.0.0.1:55432, database patient_monitor, and a private .runtime/local-access.txt file containing the first local administrator and caregiver logins. Fresh setups create no fictional patient or simulation devices. Existing history is preserved.

To stop only this project's services:

    powershell -ExecutionPolicy Bypass -File .\scripts\stop-local.ps1

The script does not stop the existing PostgreSQL Windows service or the FastAPI service on port 8000. To allow a physical board on the same LAN to submit events, start with .\scripts\start-local.ps1 -Lan; set the computer's LAN address in the firmware and allow PHP through the private-network firewall prompt if Windows shows one.

## Roles from the project document

The administrator manages user accounts, patients, rooms, devices, and assignments. The caregiver sees only assigned active patients, their sensor readings, camera metadata or private snapshots, alerts, filters, and response history. Both roles can acknowledge, resolve, or mark an alert false alarm; each change requires a note and is kept in event_updates.

## Registering physical hardware

1. Sign in as administrator and open Connected devices.
2. Register one ESP32 main controller. All registered devices are physical hardware.
3. Register one ESP32-CAM. For the camera node, enter its private LAN IPv4 address and port 80. Do not enter a public address.
4. Assign both devices to the same patient. The system prevents two current devices of the same type from being assigned to one patient.
5. Copy the one-time key displayed after registering the main controller. Put it in camera_secrets.h as FALL_DEVICE_KEY. The old key stops working after rotation.
6. Set FALL_EVENT_URL to http://YOUR_PC_LAN_IP:8085/api/v1/events. The separate main controller does not capture camera images; by default Laravel requests them from the assigned camera node.
7. Build/upload the esp32hardware PlatformIO target for the separate main controller. Its event request contains the current acceleration and gyro magnitudes. A manual emergency event is accepted immediately and does not require a sensor reading or camera.
8. Copy velxio_document/camera/camera_node_secrets.example.h to camera_node_secrets.h and set the camera Wi-Fi and camera key to the same value stored for the ESP32-CAM in Patient Watch. Build/upload the separate camera-node source. Its protected /capture endpoint returns a real OV2640 JPEG.
   Set CAMERA_DEVICE_KEY to the camera's one-time registration key (not the main ESP32 key) and CAMERA_HEARTBEAT_URL to http://YOUR_PC_LAN_IP:8085/api/v1/heartbeat. This is separate from CAMERA_NODE_KEY, which protects capture requests.
9. Use the separate main-controller and camera-node builds below for the document-aligned system. The optional consolidated esp32cam build is not the standalone snapshot-serving camera node.
10. For the optional FastAPI pose result, set CAMERA_VERIFIER_KEY in patient-monitor/.env to the same FALL_API_KEY used by FastAPI, then restart the Laravel queue worker. A missing verifier does not discard the real JPEG or the alert.

The Laravel endpoint expects:

    POST /api/v1/events
    Content-Type: application/json
    X-Device-Key: <YOUR_MAIN_DEVICE_KEY>

    {
      "event_id": "esp32-123456-1",
      "event_type": "possible_fall",
      "sensor": {
        "acceleration_g": 3.10,
        "gyro_rad_s": 0.20
      }
    }

Both physical firmware nodes send a heartbeat every 30 seconds on a separate task. The dashboard considers a device online when it was seen during the last 90 seconds; online does not itself guarantee that the sensor is healthy. The firmware contains no synthetic-motion commands or default virtual-network credentials. Test the emergency workflow using the actual push button. Serial r only resets the local alarm after attending to the patient.

For the separate camera firmware, build from the repository root:

    $env:PLATFORMIO_SRC_DIR='velxio_document/camera'
    $env:PLATFORMIO_BUILD_DIR='.pio/build/document-camera'
    & "$env:USERPROFILE\.platformio\penv\Scripts\platformio.exe" run -e esp32cam
    Remove-Item Env:PLATFORMIO_SRC_DIR
    Remove-Item Env:PLATFORMIO_BUILD_DIR

To build the main physical controller afterward:

    & "$env:USERPROFILE\.platformio\penv\Scripts\platformio.exe" run -e esp32hardware

Add -t upload only after connecting and selecting the correct board/serial port.

If the optional pose verifier is not already running, start it from the repository root:

    .\.venv\Scripts\python.exe -m uvicorn backend.app:app --env-file .env --host 127.0.0.1 --port 8000

Laravel on the same computer can reach this loopback-only verifier. Use a LAN bind only if a separately configured board needs direct access.

## Watcher Telegram notifications (default, no per-message SMS fee)

The selected watcher notification channel is **Telegram**, not SMS. The watcher needs the Telegram app, internet access and enabled Telegram notifications. The web server also needs internet and must remain running. No ESP32 firmware change, GSM module or SMS credits are needed. See the [official Telegram bot guide](https://core.telegram.org/bots) and [Bot API](https://core.telegram.org/bots/api).

### Create and configure the bot

1. In Telegram, open the official [@BotFather](https://t.me/BotFather), send `/newbot`, choose a display name and a username ending in `bot`.
2. Copy the token to the **private** `patient-monitor/.env` file. Do not send it in chat, commit it, put it in firmware or expose it in frontend JavaScript. Add:

       WATCHER_NOTIFICATION_CHANNEL=telegram
       TELEGRAM_ENABLED=true
       TELEGRAM_BOT_TOKEN=your_private_BotFather_token
       TELEGRAM_BOT_USERNAME=YourPatientWatchBot
       SMS_ENABLED=false

   Use the username without `@`. The token and username must belong to the same bot. The real token is intentionally not prefilled in this project.
3. From `patient-monitor`, run:

       php artisan config:clear
       php artisan telegram:check
       powershell -ExecutionPolicy Bypass -File .\scripts\stop-local.ps1
       powershell -ExecutionPolicy Bypass -File .\scripts\start-local.ps1 -Lan

   Restart after changing credentials: long-running workers do not reload `.env` automatically. `telegram:check` calls `getMe` only; it does not send an incident alert or print the token. Stop/start causes a brief local service interruption, so do this outside an active patient response.

The startup script runs a dedicated `telegram:poll` process only when local settings are present. This receives watcher registrations through `getUpdates`, so a public HTTPS domain or inbound port forwarding is **not** required for the local web server. Do not run a second poller, `getUpdates` script or webhook with the same bot. A separately configured webhook must be removed before polling; the application does not silently delete someone else's webhook. On a hosted server, supervise `php artisan telegram:poll` alongside the separate `notifications` queue worker. Check `.runtime/telegram-poller.log` and `.runtime/telegram-poller-error.log` if registration does not appear.

Registration uses five-second long-polls with a separate transport timeout margin. After a polling error, the listener attempts a non-blocking read before returning to normal polling, helping recover on mobile hotspots. Logs include safe exception categories and cURL error numbers, never raw token-bearing URLs. This recovery applies only to receiving registrations; uncertain incident message sends are still never automatically retried.

For local troubleshooting where long-polls repeatedly time out but immediate reads work, set `TELEGRAM_POLL_TIMEOUT=0` privately in `.env`, clear the configuration cache, and restart the project listener. This temporary recovery mode checks for registrations every two seconds plus request time. Return to `5` for normal long-polling once the network is stable. This setting does not change message sending or grant permission to contact an unconnected watcher.

### Connect each patient's watcher

1. As administrator, add/edit the patient. Enter the watcher name, optional emergency mobile number and relationship, and check **Notify this watcher through Telegram when a fall is confirmed** after obtaining consent. Save.
2. On the patient profile, the administrator or assigned caregiver selects **Connect watcher — create private link**.
3. Share that link privately with the intended watcher. It is a bearer invitation: whoever receives it first can connect. It expires after 15 minutes, works once, and a new link invalidates the previous link. Only a hash is stored in the database.
4. The watcher opens the link using their own Telegram account and presses **Start**. They receive a generic connection confirmation. The profile checks automatically and updates to **Watcher connected**. The private link remains available to its creator after a page refresh, but is hidden when expired, replaced or used. If pressing Start does not connect, expand **Pressed Start but still not connected?**, copy the private Start command, and send it as a message to the exact bot shown on the profile. Never post the link or command publicly. Usernames and phone numbers alone are not used as Telegram destinations; the server records the verified private chat from the bot update.
5. The watcher can send `/stop` to disconnect all patient alerts linked to that chat. Staff can use **Disconnect watcher**. Changing the watcher name/phone or turning Telegram alerts off also disconnects the current account and invalidates old invitations. A replacement must connect through a fresh link; staff cannot enter arbitrary chat IDs through the form.

### Change to a different Telegram account

1. On the patient profile, select **Change Telegram account**. Alternatively, when editing the patient, check **Replace with a DIFFERENT Telegram account**, save, then create a new private link on the profile. Changing the emergency phone number alone cannot choose which Telegram account receives alerts.
2. The previous connection is removed and its pending notifications are blocked. Switch to the **new** Telegram account before opening the fresh private link and pressing **Start**. The previous account cannot claim this replacement link; if it tries, the bot tells it to switch accounts without consuming the invitation. This protection remains when an expired replacement link is regenerated.
3. Verify **Watcher connected** on the patient profile. Linking does not resend historical alerts. For a blocked, genuinely confirmed incident, staff must review it and select **Retry Telegram notification** (or save its still-open confirmed response again).

Recipient changes and notification sending are serialized per patient without holding patient row locks across the Telegram request. Once account replacement completes, no queued message can start sending to the disconnected recipient. A request already submitted before replacement cannot be recalled. Sent and uncertain notification history is preserved and is not automatically sent to the replacement account. **Disconnect watcher** remains available for reconnecting the same account rather than replacing it with a different one.

### Confirmed-fall notification flow

A physical sensor or manual emergency-button alert appears for the care team immediately; snapshots and camera verification are unchanged. After checking the patient, the administrator or assigned caregiver selects **Fall confirmed by administrator / caregiver**, writes a response note, and saves. The application automatically queues one Telegram message for that incident on the independent `notifications` queue, so it does not wait behind camera capture jobs. The save confirmation shows whether the alert is queued or blocked by incomplete watcher setup. The message contains the patient's name, incident room/time and a request to contact the care team; it does not include medical notes, photos, credentials or public evidence URLs.

Detection, acknowledgement, camera verdicts, safe-patient outcomes and false alarms do not independently notify the watcher. A manual emergency does notify the watcher when staff save a **confirmed fall** outcome. Missing bot settings, missing connection or opt-out creates a truthful **Telegram not ready** record without blocking the staff response. Linking a watcher does not backfill old alerts automatically. After setup is ready, saving the still-open confirmed incident again automatically queues its blocked notification. An authorized user may also select **Retry Telegram notification**, including on a resolved confirmed fall. Already queued, sent or uncertain notifications are not automatically repeated.

The incident card records the confirmer, recipient snapshot, submission status and Telegram message reference. **Sent to Telegram** means the Bot API accepted the message in the intended private chat, not that the watcher saw a phone notification or read it. Repeated confirmations and duplicate jobs do not resend a successful alert. Explicit Telegram rejection can be retried after checking the connection. Timeouts, malformed replies and ambiguous submissions are marked **Submission uncertain** and cannot be automatically resent; check the watcher chat first to avoid duplicates. Watcher opt-out, a changed recipient or retracted fall confirmation is rechecked before sending.

There is **no automatic fallback to paid SMS**. Existing SMS records and settings are preserved, but pending SMS jobs are cancelled when Telegram is selected. Automated tests fake Telegram HTTP responses and never send real watcher messages. Use a consenting test watcher and a controlled physical incident to verify end-to-end delivery after credentials are configured; do not rely on an internet notification as the only emergency response channel.

## Optional legacy Semaphore SMS (not selected)

This integration is retained for existing records only. To deliberately choose paid SMS instead, set `WATCHER_NOTIFICATION_CHANNEL=semaphore` and restart the workers. The SMS form and incident card appear only when that channel is selected.

In **Patients > Add patient / Edit profile**, enter the watcher's name, Philippine mobile number, and optional relationship. Check **Notify this watcher by SMS when a fall is confirmed** after confirming the watcher agrees to receive incident alerts. Numbers such as `09171234567`, `639171234567`, and `+639171234567` are stored in `+63` format. Existing free-text emergency contact notes remain unchanged; enter the name and number in the new fields rather than relying on those notes.

A sensor event is recorded and shown to the care team immediately. After checking the patient, the administrator or assigned caregiver selects **Fall confirmed by administrator / caregiver** in the incident response and saves a note. This creates one watcher notification for that physical sensor-fall incident. Acknowledgement, camera verdicts, manual emergency-button alerts, safe-patient outcomes, and false alarms do not independently send SMS. An incomplete SMS setup never prevents the caregiver from recording the response.

The Semaphore integration is disabled by default. To enable actual sending:

1. Create a [Semaphore account](https://semaphore.co/), load SMS credits, and configure an active registered sender name. Follow the [official API documentation](https://semaphore.co/docs).
2. Set these values in `patient-monitor/.env` (keep the API key private):

       SMS_ENABLED=true
       WATCHER_NOTIFICATION_CHANNEL=semaphore
       SEMAPHORE_API_KEY=your_private_api_key
       SEMAPHORE_SENDER_NAME=your_registered_sender_name

   Leave `SEMAPHORE_SENDER_NAME` blank only if the account already has an active default sender name.
3. Run `php artisan config:clear`, then restart the project workers using the local startup scripts. The scripts run a separate `notifications` worker, so SMS does not wait behind camera capture jobs. For a deployed server, supervise both `php artisan queue:work --queue=default` and `php artisan queue:work --queue=notifications --sleep=1 --tries=1 --timeout=25`.
4. Review the **Watcher SMS notification** card on the incident. A previously blocked confirmed fall can be queued with **Retry watcher SMS** after contact details and service setup are ready. Existing historical confirmed falls are not automatically backfilled or texted.

The application stores the recipient and message snapshot, confirmer, provider message ID, and submission result. **Submitted to SMS provider** means Semaphore accepted the request; it does not prove the watcher received or read it. Mobile network delivery time varies. Explicit rejection can be retried from the incident by an authorized user. A timeout or ambiguous provider response is marked **Submission uncertain** and cannot be automatically resent, because Semaphore does not document a request idempotency key. Check the provider account first to avoid duplicate charged texts.

No GSM module or SIM card is required for this API integration. The web server needs internet access, an activated Semaphore account, and a running notification worker. Automated tests use fake HTTP responses and do not send billable SMS.

## Automated verification

The feature tests use the isolated patient_monitor_test database and roll back their test records. From patient-monitor, with the local PostgreSQL service running:

    php -d extension=pdo_pgsql artisan migrate --env=testing
    php -d extension=pdo_pgsql vendor/phpunit/phpunit/phpunit

The extension flag is needed with the currently selected XAMPP CLI. Run PHPUnit directly because artisan test starts a child PHP process that does not inherit this flag. The startup scripts already supply it. Do not point .env.testing at the actual patient database. Laravel PostgreSQL sessions use UTC; the UI displays Asia/Manila time.

## Existing records

The actual-only migration preserves old test events and their source flags, but excludes them from monitoring, patient history, direct event URLs, and device management. Patient/room records with exclusively test-device history and no physical records are archived. Actual patient records, passwords, and event history are not deleted or relabelled. The remaining database source flags are provenance safeguards, not a simulation feature.

## Camera evidence rule

The physical capture worker accepts only a JPEG from the assigned camera's private LAN address, stores it under private Laravel storage, and serves it only after the caregiver's authorization check. When configured, FastAPI receives the captured JPEG and its pose result is stored with the event. Camera timeout or failure leaves the fall alert open for human review.

### Optional camera-initiated upload

The default path remains web-to-camera /capture, so existing hardware keeps working. The optional push path applies to manual emergencies: the assigned ESP32-CAM polls for its own pending capture ID and POSTs the JPEG to Laravel with its camera device key. The alert is saved immediately and never waits for the photo. Laravel validates the assignment and the complete JPEG, then saves the image privately. Possible-fall events continue using the existing pull-and-pose-verification flow; this avoids changing their clinical review behavior. Push removes the need for Laravel to reach the camera's private IP for manual-emergency photos, but it does not repair an OV2640 frame timeout or guarantee a faster frame. In the recent local tests, the queue began within a second while capture/transfer took several seconds.

Keep CAMERA_PUSH_ENABLED unset (or false) until the ESP32-CAM has been flashed with CAMERA_DIRECT_UPLOAD_ENABLED set to 1. After that, set CAMERA_PUSH_ENABLED=true in patient-monitor/.env and restart the Laravel web server and queue worker. Enable the camera firmware first so there is no interval where the web stops requesting photos from an older firmware. The camera and main sensor are separate boards: connect and upload to the ESP32-CAM's actual serial port, never the main sensor's port. A software-only change on the PC does not update a camera powered from the wall outlet.

Current local rollout status (Oct 1, 2026): the direct-upload code and tests are present, but the flashed ESP32-CAM build and patient-monitor/.env have push disabled. The camera's /health endpoint intermittently timed out during both push and pull-only trials, so keep the established pull capture active until power/hotspot reliability and an end-to-end physical emergency test pass. Do not assume enabling push will fix an OV2640 frame timeout.

The example http:// URL is for a trusted local network only. Do not expose patient photos or device keys over public HTTP. A public/cloud deployment needs verified HTTPS on the ESP32-CAM before enabling outbound uploads.

This remains an assistive prototype. The threshold detector and camera evidence do not replace caregiver or professional medical assessment.

See [ACTUAL_SYSTEM_VERIFICATION.md](ACTUAL_SYSTEM_VERIFICATION.md) for the completed software checks, the physical trial checklist, and current reliability limits.
