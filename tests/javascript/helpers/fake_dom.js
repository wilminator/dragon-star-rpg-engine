'use strict';

// Just enough of the DOM for the dialogs to build into and be clicked:
// elements with children, attributes, style, focus, click() (running
// onclick and click listeners), contains(), and getElementById() over
// everything attached under the document's body.
function fakeDocument() {
    const doc = { activeElement: null };
    const make = (tag) => {
        const el = {
            tagName: tag.toUpperCase(), children: [], style: {}, attributes: {}, className: '', textContent: '',
            id: '', parentNode: null, listeners: {}, disabled: false,
            appendChild(child) { this.children.push(child); child.parentNode = this; return child; },
            removeChild(child) { this.children.splice(this.children.indexOf(child), 1); child.parentNode = null; },
            get firstChild() { return this.children[0] || null; },
            get isConnected() { let e = this; while (e.parentNode) e = e.parentNode; return e === doc.body; },
            setAttribute(name, value) { this.attributes[name] = String(value); },
            getAttribute(name) { return this.attributes[name] ?? null; },
            focus() { doc.activeElement = this; },
            contains(other) { for (let e = other; e; e = e.parentNode) if (e === this) return true; return false; },
            addEventListener(type, fn, opts) { (this.listeners[type] ||= []).push({ fn, once: opts && opts.once }); },
            click() {
                if (this.onclick) this.onclick({ target: this });
                const list = this.listeners.click || [];
                this.listeners.click = list.filter((l) => !l.once);
                list.forEach((l) => l.fn({ target: this }));
            },
            set innerHTML(html) { this._html = html; this.textContent = html.replace(/<br>/g, '\n').replace(/&nbsp;/g, ' '); },
            get innerHTML() { return this._html ?? this.textContent; },
        };
        return el;
    };
    doc.createElement = make;
    doc.body = make('body');
    doc.getElementById = (id) => find(doc.body, (e) => e.id === id);
    return doc;
}

function find(el, predicate) {
    if (predicate(el)) return el;
    for (const child of el.children) {
        const found = find(child, predicate);
        if (found) return found;
    }
    return null;
}

function findAll(el, predicate, out = []) {
    if (predicate(el)) out.push(el);
    el.children.forEach((child) => findAll(child, predicate, out));
    return out;
}

function allText(el) {
    return [el.textContent, ...el.children.map(allText)].join(' ');
}

function button(el, label) {
    return find(el, (e) => e.tagName === 'BUTTON' && e.textContent === label);
}

// Lets every pending promise callback run (answers cross from the test's
// realm into the scripts' one, which takes a few turns).
function settled() {
    return new Promise((resolve) => setImmediate(resolve));
}

module.exports = { fakeDocument, find, findAll, allText, button, settled };
