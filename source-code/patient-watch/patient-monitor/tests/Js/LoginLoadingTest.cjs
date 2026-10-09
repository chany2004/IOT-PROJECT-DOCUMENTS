const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const vm = require('node:vm');

test('login submit shows the loading overlay once, then posts the validated form', () => {
    const handlers = {};
    const windowHandlers = {};
    const attributes = new Map();
    let timer;
    let delay;
    let posts = 0;
    const loading = { hidden: true };
    const password = { type: 'password', focus() {} };
    const toggle = {
        title: 'Show password',
        addEventListener(type, handler) { handlers['toggle:' + type] = handler; },
        setAttribute(name, value) { attributes.set(name, value); },
    };
    const submit = { disabled: false };
    const form = {
        addEventListener(type, handler) { handlers[type] = handler; },
        setAttribute(name, value) { attributes.set(name, value); },
        removeAttribute(name) { attributes.delete(name); },
    };
    const elements = {
        'login-form': form,
        'login-password': password,
        'login-password-toggle': toggle,
        'login-submit': submit,
        'login-loading': loading,
    };
    const source = readFileSync(join(__dirname, '../../public/login.js'), 'utf8');
    vm.runInNewContext(source, {
        document: { getElementById(id) { return elements[id]; } },
        window: {
            addEventListener(type, handler) { windowHandlers[type] = handler; },
            clearTimeout() {},
            matchMedia() { return { matches: false }; },
            setTimeout(handler, ms) { timer = handler; delay = ms; return 1; },
        },
        HTMLFormElement: { prototype: { submit() { assert.equal(this, form); posts++; } } },
    });

    const event = { defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
    handlers.submit(event);
    assert.equal(event.defaultPrevented, true);
    assert.equal(attributes.get('aria-busy'), 'true');
    assert.equal(submit.disabled, true);
    assert.equal(loading.hidden, false);
    assert.equal(delay, 850);

    handlers.submit({ defaultPrevented: false, preventDefault() {} });
    timer();
    assert.equal(posts, 1);

    windowHandlers.pageshow({ persisted: true });
    assert.equal(submit.disabled, false);
    assert.equal(loading.hidden, true);
    assert.equal(attributes.has('aria-busy'), false);

    handlers['toggle:click']();
    assert.equal(password.type, 'text');
    assert.equal(attributes.get('aria-label'), 'Hide password');
    assert.equal(attributes.get('aria-pressed'), 'true');
    assert.equal(toggle.title, 'Hide password');
    handlers['toggle:click']();
    assert.equal(password.type, 'password');
    assert.equal(attributes.get('aria-label'), 'Show password');
    assert.equal(attributes.get('aria-pressed'), 'false');
});

test('login loading overlay has a hidden state and reduced-motion fallback', () => {
    const css = readFileSync(join(__dirname, '../../public/login.css'), 'utf8');
    assert.match(css, /\.login-loading \{[^}]*position: fixed;/s);
    assert.match(css, /\.login-loading-content \{[^}]*left: var\(--login-split\);/s);
    assert.match(css, /\.login-loading-content \{[^}]*background: transparent;/s);
    assert.match(css, /\.login-loading-content \{[^}]*box-shadow: none;/s);
    assert.match(css, /\.login-loading\[hidden\] \{ display: none; \}/);
    assert.match(css, /\.login-ecg-trace/);
    assert.match(css, /@media \(prefers-reduced-motion: reduce\)/);
});
