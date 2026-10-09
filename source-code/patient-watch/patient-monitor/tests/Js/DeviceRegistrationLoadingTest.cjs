const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const vm = require('node:vm');

test('valid registration shows the heartbeat once before the normal form POST', () => {
    const handlers = {};
    const attributes = new Map();
    let timer;
    let delay;
    let posts = 0;
    const submit = { disabled: false };
    const loading = { hidden: true };
    const form = {
        addEventListener(type, handler) { handlers['form:' + type] = handler; },
        setAttribute(name, value) { attributes.set(name, value); },
        removeAttribute(name) { attributes.delete(name); },
    };
    const dialog = {
        querySelector() { return form; },
        addEventListener(type, handler) { handlers['dialog:' + type] = handler; },
    };
    const elements = {
        'register-device': dialog,
        'device-registration-submit': submit,
        'device-registration-loading': loading,
    };
    const source = readFileSync(join(__dirname, '../../public/device-registration-modal.js'), 'utf8');
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
    handlers['form:submit']({ defaultPrevented: false, submitter: submit, preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(loading.hidden, false);
    assert.equal(submit.disabled, true);
    assert.equal(attributes.get('aria-busy'), 'true');
    assert.equal(delay, 850);

    handlers['form:submit']({ defaultPrevented: false, submitter: submit, preventDefault() {} });
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

test('registration modal fits its content and hides its loading overlay until save', () => {
    const css = readFileSync(join(__dirname, '../../public/device-registration-modal.css'), 'utf8');
    assert.match(css, /\.device-workspace \.device-registration-dialog \{[^}]*height: auto;/s);
    assert.match(css, /\.device-workspace \.device-registration-dialog \{[^}]*top: 50%;[^}]*bottom: auto;/s);
    assert.doesNotMatch(css, /\.device-workspace \.device-registration-dialog \{[^}]*inset: 0;/s);
    assert.match(css, /\.device-registration-loading\[hidden\] \{ display: none; \}/);
    assert.match(css, /\.device-registration-ecg-trace/);
    assert.match(css, /@media \(prefers-reduced-motion: reduce\)/);
});
