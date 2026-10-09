const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const vm = require('node:vm');

test('valid patient creation shows the heartbeat once before posting', () => {
    const handlers = {};
    const attributes = new Map();
    let timer;
    let delay;
    let posts = 0;
    const trigger = { addEventListener(type, handler) { handlers['trigger:' + type] = handler; }, focus() {} };
    const codeField = { focus() {} };
    const submit = { disabled: false };
    const loading = { hidden: true };
    const form = {
        addEventListener(type, handler) { handlers['form:' + type] = handler; },
        setAttribute(name, value) { attributes.set(name, value); },
        removeAttribute(name) { attributes.delete(name); },
    };
    const dialog = {
        open: false,
        querySelector() { return form; },
        hasAttribute() { return false; },
        showModal() { this.open = true; },
        close() { this.open = false; },
        addEventListener(type, handler) { handlers['dialog:' + type] = handler; },
    };
    const elements = {
        'add-patient-trigger': trigger,
        'create-patient': dialog,
        'new-patient-code': codeField,
        'patient-modal-submit': submit,
        'patient-modal-loading': loading,
    };
    const source = readFileSync(join(__dirname, '../../public/patient-modal.js'), 'utf8');
    vm.runInNewContext(source, {
        document: { getElementById(id) { return elements[id]; } },
        window: {
            addEventListener(type, handler) { handlers['window:' + type] = handler; },
            clearTimeout() {},
            matchMedia() { return { matches: false }; },
            setTimeout(handler, ms) { timer = handler; delay = ms; return 1; },
        },
        HTMLFormElement: { prototype: { submit() { assert.equal(this, form); posts++; } } },
    });

    let prevented = false;
    handlers['form:submit']({ defaultPrevented: false, preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(loading.hidden, false);
    assert.equal(submit.disabled, true);
    assert.equal(attributes.get('aria-busy'), 'true');
    assert.equal(delay, 850);

    handlers['form:submit']({ defaultPrevented: false, preventDefault() {} });
    timer();
    assert.equal(posts, 1);

    let closePrevented = false;
    handlers['dialog:cancel']({ preventDefault() { closePrevented = true; } });
    assert.equal(closePrevented, true);

    handlers['window:pageshow']({ persisted: true });
    assert.equal(loading.hidden, true);
    assert.equal(submit.disabled, false);
    assert.equal(attributes.has('aria-busy'), false);
});

test('patient modal is compact, scrollable and hides loading until save', () => {
    const css = readFileSync(join(__dirname, '../../public/patient-modal.css'), 'utf8');
    assert.match(css, /\.patient-modal \{[^}]*height: auto;/s);
    assert.match(css, /\.patient-modal \{[^}]*max-height: min\(680px, calc\(100dvh - 32px\)\);/s);
    assert.match(css, /\.patient-modal-body \{[^}]*overflow-y: auto;/s);
    assert.match(css, /\.patient-modal-loading\[hidden\] \{ display: none; \}/);
    assert.match(css, /@media \(prefers-reduced-motion: reduce\)/);
});
