(() => {
    const body = document.body;
    const menuButton = document.querySelector('.menu-toggle');
    const navigation = document.getElementById('main-navigation');
    if (menuButton && navigation) {
        const mobile = window.matchMedia('(max-width: 900px)');
        const setMenu = (expanded, restoreFocus = false) => {
            body.classList.toggle('nav-expanded', expanded);
            menuButton.setAttribute('aria-expanded', String(expanded));
            menuButton.setAttribute('aria-label', expanded ? 'Close navigation' : 'Open navigation');
            menuButton.querySelector('[data-menu-label]').textContent = expanded ? 'Close' : 'Menu';
            if (restoreFocus) menuButton.focus();
        };
        menuButton.addEventListener('click', () => setMenu(menuButton.getAttribute('aria-expanded') !== 'true'));
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && mobile.matches && body.classList.contains('nav-expanded')) {
                setMenu(false, true);
            }
        });
        navigation.addEventListener('click', event => {
            if (mobile.matches && event.target.closest('a')) setMenu(false);
        });
        mobile.addEventListener('change', () => {
            const focusWasInMenu = navigation.contains(document.activeElement);
            setMenu(false, mobile.matches && focusWasInMenu);
        });
        // Navigation remains visible if JavaScript is unavailable.
        body.classList.add('navigation-ready');
    }
    const prepareTables = (root = document) => {
        root.querySelectorAll('.table-wrap').forEach(wrapper => {
            const table = wrapper.querySelector('table');
            if (!table) return;
            table.setAttribute('role', 'table');
            const headings = [...table.querySelectorAll('thead th')].map(heading => {
                heading.setAttribute('scope', 'col');
                heading.setAttribute('role', 'columnheader');
                return heading.textContent.trim();
            });
            table.querySelectorAll('tbody tr').forEach(row => {
                row.setAttribute('role', 'row');
                [...row.children].forEach((cell, index) => {
                    if (cell.tagName !== 'TD' || cell.colSpan > 1) return;
                    cell.setAttribute('role', 'cell');
                    cell.dataset.label = headings[index] || 'Action';
                });
            });
            wrapper.classList.add('mobile-records');
            wrapper.setAttribute('tabindex', '0');
            wrapper.setAttribute('role', 'region');
            wrapper.setAttribute('aria-label', 'Records table');
        });
    };
    prepareTables();
    // Keep urgent patients first after each live update.
    const patientNameOrder = new Intl.Collator(undefined, {numeric: true, sensitivity: 'base'});
    const preparePatientOverview = (root = document) => {
        const overview = root.querySelector('[data-patient-overview]');
        if (!overview) return;
        const cards = [...overview.querySelectorAll('[data-patient-card]')];
        cards.sort((a, b) => {
            return Number(a.dataset.priority) - Number(b.dataset.priority)
                || patientNameOrder.compare(a.dataset.name, b.dataset.name);
        });
        const grid = overview.querySelector('.care-patient-grid');
        cards.forEach(card => grid.appendChild(card));
    };
    preparePatientOverview();
    /*
     * Live polling enhances the dashboard DOM for accessibility and mobile
     * layouts. Compare a cleaned clone so those client-only attributes do not
     * make an unchanged overview look new every five seconds.
     */
    const comparableLiveMarkup = region => {
        const clone = region.cloneNode(true);
        clone.querySelectorAll('.chart-feedback').forEach(node => node.remove());
        clone.querySelectorAll('.dashboard-charts').forEach(node => {
            node.removeAttribute('data-chart-ready');
        });
        clone.querySelectorAll('.activity-day, .status-row').forEach(node => {
            node.removeAttribute('tabindex');
            node.removeAttribute('role');
            node.removeAttribute('data-chart-target');
            node.classList.remove('is-selected', 'is-pulsing');
        });
        clone.querySelectorAll('.activity-point').forEach(node => {
            node.classList.remove('is-selected', 'is-hovered');
        });
        clone.querySelectorAll('.activity-card, .status-card, .status-donut').forEach(node => {
            node.classList.remove('is-pulsing');
        });
        clone.querySelectorAll('.table-wrap').forEach(wrapper => {
            wrapper.classList.remove('mobile-records');
            wrapper.removeAttribute('tabindex');
            wrapper.removeAttribute('role');
            wrapper.removeAttribute('aria-label');
        });
        clone.querySelectorAll('.table-wrap table').forEach(table => table.removeAttribute('role'));
        clone.querySelectorAll('.table-wrap thead th').forEach(heading => {
            heading.removeAttribute('scope');
            heading.removeAttribute('role');
        });
        clone.querySelectorAll('.table-wrap tbody tr').forEach(row => row.removeAttribute('role'));
        clone.querySelectorAll('.table-wrap tbody td').forEach(cell => {
            cell.removeAttribute('role');
            cell.removeAttribute('data-label');
        });
        return clone.innerHTML;
    };
    // Device management enhancement is independent of the monitoring feed.
    const registration = document.getElementById('register-device');
    let registrationTrigger = document.querySelector('[data-open-device-registration]');
    const openRegistration = () => {
        if (!registration || typeof registration.showModal !== 'function') return false;
        if (registration.open) registration.close();
        registration.showModal();
        (registration.querySelector('.device-registration-errors')
            || registration.querySelector('#new-device-code'))?.focus();
        return true;
    };
    document.querySelectorAll('[data-open-device-registration]').forEach(link => {
        link.addEventListener('click', event => {
            registrationTrigger = link;
            if (openRegistration()) event.preventDefault();
        });
    });
    registration?.addEventListener('close', () => {
        if (!registration.open) registrationTrigger?.focus({preventScroll: true});
    });
    if (registration?.hasAttribute('open') || window.location.hash === '#register-device') openRegistration();
    const typeSelector = registration?.querySelector('[data-device-type]');
    const cameraSettings = registration?.querySelector('[data-camera-registration]');
    if (typeSelector && cameraSettings) {
        const updateCameraSettings = () => {
            const isCamera = typeSelector.value === 'esp32_cam';
            cameraSettings.hidden = !isCamera;
            cameraSettings.open = isCamera;
            cameraSettings.querySelectorAll('input').forEach(input => { input.disabled = !isCamera; });
        };
        typeSelector.addEventListener('change', updateCameraSettings);
        updateCameraSettings();
    }
    document.querySelectorAll('.device-assignment-form').forEach(form => {
        const select = form.querySelector('[name="patient_id"]');
        const submit = form.querySelector('button[type="submit"], button:not([type])');
        if (!select || !submit) return;
        const updateAssignment = () => {
            submit.disabled = select.value === form.dataset.currentPatient;
        };
        select.addEventListener('change', updateAssignment);
        updateAssignment();
        window.addEventListener('pageshow', updateAssignment);
    });
    // Reveal collapsed settings before the browser focuses an invalid field.
    const roomRegistration = document.getElementById('create-room');
    const openRoomRegistration = () => {
        if (!roomRegistration) return;
        roomRegistration.open = true;
        roomRegistration.querySelector('input[name="number"]')?.focus({preventScroll: true});
        roomRegistration.scrollIntoView({behavior: 'smooth', block: 'start'});
    };
    document.querySelectorAll('[data-open-room-registration]').forEach(link => {
        link.addEventListener('click', event => {
            event.preventDefault();
            openRoomRegistration();
        });
    });
    if (window.location.hash === '#create-room') openRoomRegistration();
    document.querySelectorAll('.device-workspace, .room-workspace').forEach(workspace => workspace.addEventListener('invalid', event => {
        let details = event.target.closest('details');
        while (details) {
            details.open = true;
            details = details.parentElement.closest('details');
        }
    }, true));
    if (!body.dataset.feed) return;
    let audio = null, alertBuzzerTimer = null, alertBuzzerUrgent = false;
    let buzzerGeneration = 0;
    const soundingNotes = new Set();
    let soundEnabled = false, previousAttention = null, refreshing = false, activeAlertId = null;
    let refreshingOverview = false, nextOverviewRefresh = 0, capturePending = false;
    let alertQueue = [], alertEvents = new Map(), alertMinimized = false, lastFocusedElement = null;
    const button = document.getElementById('sound-toggle');
    const popup = {
        layer: document.getElementById('emergency-alert-layer'),
        dialog: document.querySelector('.emergency-alert-dialog'),
        kicker: document.getElementById('emergency-alert-kicker'),
        title: document.getElementById('emergency-alert-title'),
        time: document.getElementById('emergency-alert-time'),
        patient: document.getElementById('emergency-alert-patient'),
        room: document.getElementById('emergency-alert-room'),
        patientStatus: document.getElementById('emergency-alert-patient-status'),
        caregiver: document.getElementById('emergency-alert-caregiver'),
        device: document.getElementById('emergency-alert-device'),
        deviceStatus: document.getElementById('emergency-alert-device-status'),
        cameraStatus: document.getElementById('emergency-alert-camera-status'),
        cameraDevice: document.getElementById('emergency-alert-camera-device'),
        response: document.getElementById('emergency-alert-response'),
        detected: document.getElementById('emergency-alert-detected'),
        image: document.getElementById('emergency-alert-image'),
        imageExpand: document.getElementById('emergency-image-expand'),
        cameraPlaceholder: document.getElementById('emergency-camera-placeholder'),
        message: document.getElementById('emergency-alert-message'),
        acknowledge: document.getElementById('emergency-alert-acknowledge'),
        view: document.getElementById('emergency-alert-view'),
        next: document.getElementById('emergency-alert-next'),
        minimize: document.getElementById('emergency-alert-minimize'),
        sound: document.getElementById('emergency-alert-sound'),
        queueCount: document.getElementById('emergency-alert-queue-count'),
        reopen: document.getElementById('emergency-alert-reopen'),
        reopenCount: document.getElementById('emergency-alert-reopen-count')
    };
    const viewer = {
        dialog: document.getElementById('emergency-image-viewer'),
        photo: document.getElementById('emergency-image-viewer-photo'),
        title: document.getElementById('emergency-image-viewer-title'),
        stage: document.getElementById('emergency-image-viewer-stage'),
        zoomOut: document.getElementById('emergency-image-zoom-out'),
        zoomIn: document.getElementById('emergency-image-zoom-in'),
        zoomReset: document.getElementById('emergency-image-zoom-reset'),
        zoomLevel: document.getElementById('emergency-image-zoom-level'),
        close: document.getElementById('emergency-image-viewer-close')
    };
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    try {
        // Arm alerts by default. Browsers still require one click/tap before
        // Web Audio may play; the first interaction below unlocks it.
        soundEnabled = localStorage.getItem('patient-watch-alert-buzzer') !== 'disabled';
    } catch {}
    const updateSoundControls = () => {
        const running = soundEnabled && audio?.state === 'running';
        if (button) {
            button.textContent = soundEnabled
                ? (running ? 'Mute alert buzzer' : 'Activate alert buzzer')
                : 'Enable alert buzzer';
            button.setAttribute('aria-pressed', String(soundEnabled));
        }
        if (popup.sound) {
            popup.sound.querySelector('span').textContent = soundEnabled
                ? (running ? 'Mute buzzer' : 'Activate buzzer')
                : 'Enable buzzer';
            popup.sound.setAttribute('aria-pressed', String(soundEnabled));
        }
        popup.layer?.classList.toggle('sound-enabled', soundEnabled);
    };
    const ensureAudio = async () => {
        try {
            audio ??= new (window.AudioContext || window.webkitAudioContext)();
            if (audio.state === 'suspended') await audio.resume();
        } catch {
            audio = null;
        }
        updateSoundControls();
        return audio?.state === 'running';
    };
    const playBuzzerPattern = (urgent = false) => {
        if (!soundEnabled || audio?.state !== 'running' || !pendingAlertIds().length) return;
        const frequencies = urgent ? [880, 660, 880] : [720, 720];
        frequencies.forEach((frequency, index) => {
            const start = audio.currentTime + index * .27;
            const oscillator = audio.createOscillator();
            const gain = audio.createGain();
            oscillator.type = urgent ? 'square' : 'sine';
            oscillator.frequency.setValueAtTime(frequency, start);
            oscillator.connect(gain);
            gain.connect(audio.destination);
            gain.gain.setValueAtTime(.0001, start);
            gain.gain.exponentialRampToValueAtTime(urgent ? .12 : .08, start + .02);
            gain.gain.exponentialRampToValueAtTime(.0001, start + .2);
            soundingNotes.add(oscillator);
            oscillator.onended = () => {
                soundingNotes.delete(oscillator);
                oscillator.disconnect();
                gain.disconnect();
            };
            oscillator.start(start);
            oscillator.stop(start + .21);
        });
    };
    const stopAlertBuzzer = () => {
        buzzerGeneration++;
        for (const note of soundingNotes) {
            try { note.stop(); } catch {}
        }
        soundingNotes.clear();
        if (alertBuzzerTimer !== null) window.clearInterval(alertBuzzerTimer);
        alertBuzzerTimer = null;
        popup.layer?.classList.remove('buzzer-active');
    };
    const startAlertBuzzer = async (urgent = false) => {
        alertBuzzerUrgent ||= urgent;
        if (!soundEnabled || alertBuzzerTimer !== null) return;
        const generation = buzzerGeneration;
        if (!(await ensureAudio())) return;
        if (!soundEnabled || generation !== buzzerGeneration
            || alertBuzzerTimer !== null || !pendingAlertIds().length) return;
        popup.layer?.classList.add('buzzer-active');
        playBuzzerPattern(alertBuzzerUrgent);
        alertBuzzerTimer = window.setInterval(
            () => playBuzzerPattern(alertBuzzerUrgent), 2600
        );
    };
    const setSoundEnabled = async enabled => {
        soundEnabled = enabled;
        try {
            localStorage.setItem('patient-watch-alert-buzzer', enabled ? 'enabled' : 'disabled');
        } catch {}
        if (!enabled) {
            stopAlertBuzzer();
            if (audio?.state === 'running') await audio.suspend();
        } else {
            await ensureAudio();
            const ids = pendingAlertIds();
            if (ids.length) {
                startAlertBuzzer(ids.some(id =>
                    alertEvents.get(id)?.popup_kind === 'manual_emergency'));
            }
        }
        updateSoundControls();
    };
    const toggleSound = () => setSoundEnabled(!soundEnabled || audio?.state !== 'running');
    button?.addEventListener('click', toggleSound);
    popup.sound?.addEventListener('click', toggleSound);
    if (soundEnabled) {
        const activateArmedSound = async event => {
            // The dedicated sound controls handle their own click. Skipping
            // them here prevents one pointer action from activating and then
            // immediately muting the buzzer.
            if (event.target?.closest?.('#sound-toggle, #emergency-alert-sound')) return;
            if (!(await ensureAudio())) return;
            const ids = pendingAlertIds();
            if (ids.length) {
                startAlertBuzzer(ids.some(id =>
                    alertEvents.get(id)?.popup_kind === 'manual_emergency'));
            }
        };
        document.addEventListener('pointerdown', activateArmedSound, {once: true});
        document.addEventListener('keydown', activateArmedSound, {once: true});
    }
    updateSoundControls();
    const formatTime = value => value
        ? new Date(value).toLocaleTimeString([], {hour: 'numeric', minute: '2-digit', second: '2-digit'})
        : 'Just now';
    const titleFor = event => {
        if (event.popup_kind === 'manual_emergency') return 'Manual emergency';
        if (event.popup_kind === 'likely_fall') return 'Likely fall detected';
        return 'Camera result needs immediate review';
    };
    const cameraDisplayStatus = event => {
        const camera = event.camera;
        // Older feeds stored a firmware frame error as a timeout. Preserve the
        // specific failure label even if an open page receives that old status.
        const frameFailed = ['timeout', 'unavailable'].includes(camera.status) &&
            /^Camera (?:is reachable, but could not capture a frame|is busy and could not provide this snapshot|returned an error instead of a snapshot)/i.test(camera.result || '');
        return frameFailed ? 'unavailable' : (camera.display_status || camera.status);
    };
    const cameraLabel = event => ({
        pending: 'Capturing image…', captured: event.camera.image_url ? 'Captured' : 'Incomplete image',
        incomplete: 'Incomplete image', timeout: 'Camera timeout', unavailable: 'Camera unavailable',
        not_requested: 'Not requested'
    }[cameraDisplayStatus(event)] || cameraDisplayStatus(event) || 'Checking…');
    const setCameraLoading = loading => {
        popup.cameraPlaceholder.classList.toggle('is-loading', loading);
        popup.cameraStatus.classList.toggle('is-loading', loading);
    };
    const pendingAlertIds = () => alertQueue.filter(id => alertEvents.get(id)?.status === 'pending');
    const updateReopenButton = () => {
        const count = pendingAlertIds().length;
        popup.reopen.hidden = !alertMinimized || count === 0;
        popup.reopenCount.textContent = count === 1 ? 'Open urgent alert' : `Open ${count} urgent alerts`;
    };
    let imageZoom = 1, imagePanX = 0, imagePanY = 0;
    let pinchDistance = null;
    const imagePointers = new Map();
    const clampImagePan = () => {
        const stageWidth = viewer.stage.clientWidth;
        const stageHeight = viewer.stage.clientHeight;
        const imageWidth = viewer.photo.naturalWidth;
        const imageHeight = viewer.photo.naturalHeight;
        const fit = imageWidth && imageHeight && stageWidth && stageHeight
            ? Math.min(stageWidth / imageWidth, stageHeight / imageHeight) : 0;
        const maxX = Math.max(0, (imageWidth * fit * imageZoom - stageWidth) / 2);
        const maxY = Math.max(0, (imageHeight * fit * imageZoom - stageHeight) / 2);
        imagePanX = Math.max(-maxX, Math.min(maxX, imagePanX));
        imagePanY = Math.max(-maxY, Math.min(maxY, imagePanY));
    };
    const updateImageZoom = () => {
        clampImagePan();
        viewer.photo.style.transform = `translate(${imagePanX}px, ${imagePanY}px) scale(${imageZoom})`;
        viewer.zoomLevel.value = `${Math.round(imageZoom * 100)}%`;
        viewer.zoomOut.disabled = imageZoom <= 1;
        viewer.zoomIn.disabled = imageZoom >= 5;
        viewer.stage.classList.toggle('is-zoomed', imageZoom > 1);
    };
    const setImageZoom = value => {
        imageZoom = Math.max(1, Math.min(5, Math.round(value * 100) / 100));
        if (imageZoom === 1) imagePanX = imagePanY = 0;
        updateImageZoom();
    };
    const closeImageViewer = () => {
        if (viewer.dialog.open) viewer.dialog.close();
    };
    let imageViewerReturnFocus = null;
    const openImageViewer = (source, title, returnFocus) => {
        if (!source || viewer.dialog.open) return;
        viewer.photo.src = source;
        viewer.title.textContent = title;
        imageViewerReturnFocus = returnFocus;
        viewer.dialog.showModal();
        imagePanX = imagePanY = 0;
        setImageZoom(1);
        viewer.close.focus({preventScroll: true});
    };
    const openPopupImageViewer = () => {
        if (popup.image.hidden || !popup.image.complete || !popup.image.naturalWidth) return;
        openImageViewer(popup.image.currentSrc || popup.image.src,
            `${popup.patient.textContent} - camera image`, popup.imageExpand);
    };
    popup.imageExpand?.addEventListener('click', openPopupImageViewer);
    popup.image?.addEventListener('click', openPopupImageViewer);
    document.querySelectorAll('[data-camera-image-viewer]').forEach(trigger => {
        trigger.addEventListener('click', () => {
            const image = trigger.querySelector('img');
            openImageViewer(image?.currentSrc || image?.src,
                trigger.dataset.cameraImageTitle || 'Camera image', trigger);
        });
    });
    viewer.photo?.addEventListener('load', updateImageZoom);
    window.addEventListener('resize', () => {
        if (viewer.dialog.open) updateImageZoom();
    });
    viewer.close?.addEventListener('click', closeImageViewer);
    viewer.dialog?.addEventListener('close', () => {
        viewer.photo.removeAttribute('src');
        imagePointers.clear();
        pinchDistance = null;
        if (imageViewerReturnFocus?.isConnected && !imageViewerReturnFocus.hidden) {
            imageViewerReturnFocus.focus({preventScroll: true});
        }
        imageViewerReturnFocus = null;
    });
    viewer.zoomIn?.addEventListener('click', () => setImageZoom(imageZoom + .25));
    viewer.zoomOut?.addEventListener('click', () => setImageZoom(imageZoom - .25));
    viewer.zoomReset?.addEventListener('click', () => setImageZoom(1));
    viewer.stage?.addEventListener('wheel', event => {
        event.preventDefault();
        setImageZoom(imageZoom + (event.deltaY < 0 ? .25 : -.25));
    }, {passive: false});
    viewer.stage?.addEventListener('dblclick', () => setImageZoom(imageZoom === 1 ? 2 : 1));
    const pointerDistance = () => {
        const [a, b] = [...imagePointers.values()];
        return Math.hypot(a.x - b.x, a.y - b.y);
    };
    viewer.stage?.addEventListener('pointerdown', event => {
        imagePointers.set(event.pointerId, {x: event.clientX, y: event.clientY});
        viewer.stage.setPointerCapture(event.pointerId);
        if (imagePointers.size === 2) pinchDistance = pointerDistance();
    });
    viewer.stage?.addEventListener('pointermove', event => {
        const previous = imagePointers.get(event.pointerId);
        if (!previous) return;
        imagePointers.set(event.pointerId, {x: event.clientX, y: event.clientY});
        if (imagePointers.size === 2) {
            const nextDistance = pointerDistance();
            if (pinchDistance) setImageZoom(imageZoom * nextDistance / pinchDistance);
            pinchDistance = nextDistance;
        } else if (imageZoom > 1) {
            imagePanX += event.clientX - previous.x;
            imagePanY += event.clientY - previous.y;
            updateImageZoom();
        }
    });
    const releaseImagePointer = event => {
        imagePointers.delete(event.pointerId);
        pinchDistance = null;
    };
    viewer.stage?.addEventListener('pointerup', releaseImagePointer);
    viewer.stage?.addEventListener('pointercancel', releaseImagePointer);
    viewer.dialog?.addEventListener('keydown', event => {
        if (event.key === '+' || event.key === '=') setImageZoom(imageZoom + .25);
        else if (event.key === '-') setImageZoom(imageZoom - .25);
        else if (event.key === '0') setImageZoom(1);
        else return;
        event.preventDefault();
        event.stopPropagation();
    });
    const renderAlert = event => {
        if (!event || !popup.layer) return;
        if (viewer.dialog.open && popup.image.dataset.source !== (event.camera.image_url || '')) {
            closeImageViewer();
        }
        popup.layer.dataset.kind = event.popup_kind || 'review_required';
        popup.kicker.textContent = event.popup_kind === 'manual_emergency' ? 'MANUAL EMERGENCY' : 'URGENT MONITORING ALERT';
        popup.title.textContent = titleFor(event);
        popup.time.textContent = formatTime(event.received_at);
        popup.patient.textContent = event.patient.name;
        popup.room.textContent = event.patient.room ? `Room ${event.patient.room}` : 'Room not assigned';
        popup.patientStatus.textContent = event.patient_status;
        popup.caregiver.textContent = event.patient.caregiver || 'Unassigned';
        popup.device.textContent = event.device.code;
        popup.deviceStatus.textContent = event.device.online
            ? (event.device.sensor_ok === false ? 'Online · sensor needs attention' : 'Online · sensor ready')
            : 'Offline / heartbeat overdue';
        popup.cameraStatus.textContent = cameraLabel(event);
        popup.cameraDevice.textContent = event.camera.device_code || 'Not assigned';
        popup.response.textContent = event.status === 'acknowledged'
            ? `Acknowledged${event.acknowledged_by ? ` by ${event.acknowledged_by}` : ''}` : 'New alert';
        popup.detected.textContent = formatTime(event.detected_at);
        popup.view.href = event.event_url;
        popup.message.textContent = event.status === 'acknowledged'
            ? 'Response recorded. Check the patient, then complete the incident report.'
            : 'A camera image is supporting evidence only. Check the patient immediately.';
        popup.acknowledge.disabled = event.status !== 'pending';
        popup.acknowledge.textContent = event.status === 'pending'
            ? 'Acknowledge — I’m responding' : 'Acknowledged — response in progress';

        if (event.camera.image_url) {
            const shouldLoad = popup.image.dataset.source !== event.camera.image_url
                || popup.image.dataset.failed === 'true';
            if (shouldLoad) {
                popup.image.dataset.source = event.camera.image_url;
                popup.image.dataset.failed = 'false';
                popup.image.hidden = true;
                popup.imageExpand.hidden = true;
                popup.cameraPlaceholder.hidden = false;
                setCameraLoading(true);
                popup.cameraPlaceholder.querySelector('strong').textContent = 'Loading captured image…';
                popup.cameraPlaceholder.querySelector('small').textContent = 'The image will appear automatically.';
                popup.cameraStatus.textContent = 'Loading image…';
                const separator = event.camera.image_url.includes('?') ? '&' : '?';
                popup.image.src = event.camera.image_url + separator + 'request=' + Date.now();
            } else if (popup.image.complete && popup.image.naturalWidth > 0) {
                popup.image.hidden = false;
                popup.imageExpand.hidden = false;
                popup.cameraPlaceholder.hidden = true;
                popup.cameraStatus.textContent = 'Captured';
                setCameraLoading(false);
            } else {
                setCameraLoading(true);
            }
        } else {
            popup.image.hidden = true;
            popup.imageExpand.hidden = true;
            popup.image.removeAttribute('src');
            popup.image.dataset.source = '';
            popup.image.dataset.failed = 'false';
            popup.cameraPlaceholder.hidden = false;
            setCameraLoading(event.camera.status === 'pending');
            const strong = popup.cameraPlaceholder.querySelector('strong');
            const small = popup.cameraPlaceholder.querySelector('small');
            strong.textContent = cameraLabel(event);
            small.textContent = cameraDisplayStatus(event) === 'incomplete'
                ? 'The connection ended before the full JPEG arrived.'
                : (event.camera.result && ['timeout', 'unavailable'].includes(event.camera.status)
                    ? event.camera.result : 'The emergency does not wait for an image.');
        }
        const pendingCount = pendingAlertIds().length;
        popup.next.hidden = pendingCount < 2;
        popup.queueCount.textContent = pendingCount > 1 ? `${pendingCount} urgent alerts in queue` : '';
    };
    const showAlert = (id, focus = false) => {
        const event = alertEvents.get(id);
        if (!event) return;
        if (focus) lastFocusedElement = document.activeElement;
        activeAlertId = id;
        alertMinimized = false;
        renderAlert(event);
        popup.layer.hidden = false;
        popup.reopen.hidden = true;
        popup.reopen.classList.remove('is-announcing');
        body.classList.add('has-emergency-alert');
        if (focus) popup.dialog.focus({preventScroll: true});
    };
    const announceAlert = id => {
        const event = alertEvents.get(id);
        if (!event || event.status !== 'pending') return;
        closeImageViewer();
        activeAlertId = id;
        alertMinimized = true;
        popup.layer.hidden = true;
        popup.reopen.classList.add('is-announcing');
        body.classList.remove('has-emergency-alert');
        // Load the photo as soon as it is ready, even while details are closed.
        renderAlert(event);
        updateReopenButton();
    };
    const minimizeAlert = () => {
        closeImageViewer();
        const active = alertEvents.get(activeAlertId);
        if (active && active.status !== 'pending') {
            alertQueue = alertQueue.filter(id => id !== activeAlertId);
            activeAlertId = null;
        }
        popup.layer.hidden = true;
        alertMinimized = true;
        body.classList.remove('has-emergency-alert');
        updateReopenButton();
        if (lastFocusedElement?.isConnected) lastFocusedElement.focus({preventScroll: true});
    };
    popup.minimize?.addEventListener('click', minimizeAlert);
    popup.reopen?.addEventListener('click', () => {
        const id = activeAlertId && alertEvents.get(activeAlertId)?.status === 'pending'
            ? activeAlertId : pendingAlertIds()[0];
        if (id) showAlert(id, true);
    });
    popup.next?.addEventListener('click', () => {
        const ids = pendingAlertIds();
        if (ids.length < 2) return;
        const current = Math.max(0, ids.indexOf(activeAlertId));
        showAlert(ids[(current + 1) % ids.length]);
    });
    popup.image?.addEventListener('load', () => {
        const event = alertEvents.get(activeAlertId);
        if (!event?.camera.image_url || popup.image.dataset.source !== event.camera.image_url) return;
        popup.image.dataset.failed = 'false';
        popup.image.hidden = false;
        popup.imageExpand.hidden = false;
        popup.cameraPlaceholder.hidden = true;
        popup.cameraStatus.textContent = 'Captured';
        setCameraLoading(false);
    });
    popup.image?.addEventListener('error', () => {
        closeImageViewer();
        popup.image.dataset.failed = 'true';
        popup.image.hidden = true;
        popup.imageExpand.hidden = true;
        popup.cameraPlaceholder.hidden = false;
        setCameraLoading(true);
        popup.cameraPlaceholder.querySelector('strong').textContent = 'Retrying captured image…';
        popup.cameraPlaceholder.querySelector('small').textContent = 'The saved image will appear automatically when the connection recovers.';
        popup.cameraStatus.textContent = 'Retrying image…';
    });
    popup.acknowledge?.addEventListener('click', async () => {
        const event = alertEvents.get(activeAlertId);
        if (!event || event.status !== 'pending') return;
        popup.acknowledge.disabled = true;
        popup.acknowledge.textContent = 'Recording response…';
        popup.message.textContent = 'Saving your acknowledgement…';
        try {
            const response = await fetch(event.acknowledge_url, {
                method: 'POST',
                headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
                signal: AbortSignal.timeout(8000)
            });
            if (!response.ok) throw new Error('acknowledgement_failed');
            const result = await response.json();
            event.status = result.status;
            event.patient_status = 'Caregiver responding';
            event.acknowledged_by = result.acknowledged_by;
            renderAlert(event);
            popup.message.textContent = result.message;
            if (!pendingAlertIds().length) {
                alertBuzzerUrgent = false;
                stopAlertBuzzer();
            }
        } catch {
            popup.acknowledge.disabled = false;
            popup.acknowledge.textContent = 'Try acknowledgement again';
            popup.message.textContent = 'Could not record the response. Check the connection and try again.';
        }
    });
    document.addEventListener('keydown', event => {
        if (viewer.dialog.open) return;
        if (event.key === 'Escape' && !popup.layer.hidden) minimizeAlert();
        if (event.key === 'Tab' && !popup.layer.hidden) {
            const controls = [...popup.dialog.querySelectorAll('button:not(:disabled), a[href]')]
                .filter(element => !element.hidden && element.getClientRects().length);
            const first = controls[0], last = controls[controls.length - 1];
            if (!first) return;
            if (event.shiftKey && (document.activeElement === first
                || document.activeElement === popup.dialog)) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (document.activeElement === last
                || document.activeElement === popup.dialog)) {
                event.preventDefault();
                first.focus();
            }
        }
    });

    const syncAlerts = (events, attention, newAttention) => {
        alertEvents = new Map(events.map(event => [event.public_id, event]));
        alertQueue = alertQueue.filter(id => alertEvents.has(id));
        const candidates = events.filter(event => event.popup_kind && event.status === 'pending');
        let incoming = [];
        if (previousAttention === null) {
            incoming = candidates;
        } else {
            incoming = candidates.filter(event => newAttention.has(event.public_id));
        }
        incoming.slice().reverse().forEach(event => {
            if (!alertQueue.includes(event.public_id)) alertQueue.unshift(event.public_id);
        });
        if (activeAlertId && !alertEvents.has(activeAlertId)) {
            closeImageViewer();
            activeAlertId = null;
            popup.layer.hidden = true;
            popup.reopen.classList.remove('is-announcing');
            body.classList.remove('has-emergency-alert');
        }
        if (incoming.length) {
            const currentIsOpen = activeAlertId && !popup.layer.hidden
                && alertEvents.get(activeAlertId)?.status === 'pending';
            if (!currentIsOpen) announceAlert(incoming[0].public_id);
            else renderAlert(alertEvents.get(activeAlertId));
        } else if (activeAlertId) {
            renderAlert(alertEvents.get(activeAlertId));
        } else if (!activeAlertId && pendingAlertIds().length) {
            activeAlertId = pendingAlertIds()[0];
            alertMinimized = true;
        }
        updateReopenButton();
        const pendingIds = pendingAlertIds();
        if (!pendingIds.length) {
            alertBuzzerUrgent = false;
            stopAlertBuzzer();
        } else {
            startAlertBuzzer(pendingIds.some(id =>
                alertEvents.get(id)?.popup_kind === 'manual_emergency'));
        }
    };
    const refreshOverview = async () => {
        const regionIds = ['overview-live', 'event-camera-live']
            .filter(id => document.getElementById(id));
        if (refreshingOverview || !regionIds.length || Date.now() < nextOverviewRefresh) return;
        refreshingOverview = true;
        nextOverviewRefresh = Date.now() + 5000;
        try {
            const page = await fetch(location.href, {
                headers: {'Accept': 'text/html'}, cache: 'no-store',
                signal: AbortSignal.timeout(8000)
            });
            if (!page.ok) return;
            const html = new DOMParser().parseFromString(await page.text(), 'text/html');
            regionIds.forEach(id => {
                const next = html.getElementById(id);
                const current = document.getElementById(id);
                // Preserve response forms, keyboard focus, and table position.
                if (!next || !current || current.contains(document.activeElement)
                    || comparableLiveMarkup(current) === comparableLiveMarkup(next)) return;
                const scrollPositions = [...current.querySelectorAll('.table-wrap')].map(table => table.scrollLeft);
                prepareTables(next);
                preparePatientOverview(next);
                current.replaceWith(next);
                window.PatientWatchDashboardCharts?.init(next.querySelector('.dashboard-charts'));
                next.querySelectorAll('.table-wrap').forEach((table, index) => {
                    table.scrollLeft = scrollPositions[index] || 0;
                });
            });
        } catch {
            // Secondary page refresh must never stall live alerts or pictures.
        } finally {
            refreshingOverview = false;
        }
    };
    const update = async () => {
        if (refreshing) return;
        refreshing = true;
        try {
            const response = await fetch(body.dataset.feed, {
                headers: {'Accept': 'application/json'},
                cache: 'no-store',
                signal: AbortSignal.timeout(8000)
            });
            if (!response.ok) throw new Error('offline');
            const data = await response.json();
            capturePending = data.events.some(event => event.camera.status === 'pending');
            const attention = new Set(data.events.filter(event => event.requires_attention).map(event => event.public_id));
            const newAttention = new Set(previousAttention
                ? [...attention].filter(id => !previousAttention.has(id)) : []);
            syncAlerts(data.events, attention, newAttention);
            previousAttention = attention;
            document.getElementById('nav-alert-count').textContent = attention.size || '';
            document.getElementById('connection-status').textContent = 'Monitoring connected / Updated ' + new Date().toLocaleTimeString();
            document.getElementById('connection-dot').classList.remove('offline');
            // Prioritize capture readiness; refresh page sections independently.
            if (!capturePending) refreshOverview();
        } catch {
            document.getElementById('connection-status').textContent = 'Connection unavailable. Displayed information may be out of date.';
            document.getElementById('connection-dot').classList.add('offline');
        } finally {
            refreshing = false;
            setTimeout(update, capturePending ? 750 : 2000);
        }
    };
    update();
})();
