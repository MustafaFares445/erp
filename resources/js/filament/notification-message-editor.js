// Edits notification wording with dynamic information shown as inline pills.
// The stored text keeps the backend placeholder format ("{{ name }}"); pills are only the visual form.
const PLACEHOLDER = /\{\{\s*([A-Za-z0-9_]+)\s*\}\}/g;
const CARET_ANCHOR = '​';

export default function notificationMessageEditor({ state, config }) {
    return {
        state,
        config,
        language: config.languages[0]?.locale ?? '',
        previewFormat: config.formats[0]?.channel ?? '',
        rootEl: null,
        savedRanges: {},

        init() {
            this.rootEl = this.$el;

            // The entangled state can arrive after the editors mounted, so track it deeply (initial load,
            // typing, and server-side changes such as "Reset to system default").
            window.Alpine.effect(() => {
                JSON.stringify(this.state);
                this.$nextTick(() => this.refreshEditors());
            });
        },

        editors() {
            return [...this.rootEl.querySelectorAll('[data-editor-key]')];
        },

        editorFor(key, field) {
            return this.rootEl.querySelector(`[data-editor-key="${key}"][data-editor-field="${field}"]`);
        },

        textOf(key, field) {
            const value = this.state?.[key]?.[field];

            return typeof value === 'string' ? value : '';
        },

        labelFor(locale, name) {
            const known = this.config.information[locale]?.find((item) => item.name === name);

            return known?.label ?? name.replace(/_/g, ' ').replace(/^./, (letter) => letter.toUpperCase());
        },

        mountEditor(el) {
            this.renderEditor(el);

            el.addEventListener('input', () => this.commit(el));
            el.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter') {
                    return;
                }

                event.preventDefault();

                if (el.dataset.multiline === '1') {
                    this.insertText(el, '\n');
                }
            });
            el.addEventListener('paste', (event) => {
                event.preventDefault();

                const text = (event.clipboardData ?? window.clipboardData).getData('text/plain');

                this.insertText(el, el.dataset.multiline === '1' ? text : text.replace(/\s*\n\s*/g, ' '));
            });

            ['keyup', 'mouseup', 'focusout'].forEach((name) => el.addEventListener(name, () => this.remember(el)));
        },

        renderEditor(el) {
            const value = this.textOf(el.dataset.editorKey, el.dataset.editorField);
            const fragment = document.createDocumentFragment();
            let offset = 0;

            for (const match of value.matchAll(PLACEHOLDER)) {
                if (match.index > offset) {
                    fragment.append(document.createTextNode(value.slice(offset, match.index)));
                }

                fragment.append(this.tokenNode(el.dataset.editorLocale, match[1]));
                offset = match.index + match[0].length;
            }

            if (offset < value.length) {
                fragment.append(document.createTextNode(value.slice(offset)));
            }

            if (fragment.lastChild?.nodeType === 1) {
                fragment.append(document.createTextNode(CARET_ANCHOR));
            }

            el.replaceChildren(fragment);
        },

        refreshEditors() {
            this.editors().forEach((el) => {
                if (this.serialize(el) !== this.textOf(el.dataset.editorKey, el.dataset.editorField)) {
                    this.renderEditor(el);
                }
            });
        },

        tokenNode(locale, name) {
            const token = document.createElement('span');

            token.className = 'nm-token';
            token.contentEditable = 'false';
            token.dataset.token = name;
            token.textContent = this.labelFor(locale, name);

            return token;
        },

        serialize(el) {
            const walk = (node) => {
                if (node.nodeType === Node.TEXT_NODE) {
                    return node.nodeValue;
                }

                if (node.nodeType !== Node.ELEMENT_NODE) {
                    return '';
                }

                if (node.dataset?.token) {
                    return `{{ ${node.dataset.token} }}`;
                }

                if (node.tagName === 'BR') {
                    return '\n';
                }

                const inner = [...node.childNodes].map(walk).join('');

                return ['DIV', 'P'].includes(node.tagName) && node.nextSibling ? `${inner}\n` : inner;
            };

            const text = [...el.childNodes].map(walk).join('').replaceAll(CARET_ANCHOR, '').replaceAll(' ', ' ');

            return el.dataset.multiline === '1' ? text : text.replace(/\s*\n\s*/g, ' ');
        },

        commit(el) {
            const { editorKey: key, editorField: field } = el.dataset;
            const value = this.serialize(el);

            if (value === this.textOf(key, field)) {
                return;
            }

            this.state = { ...this.state, [key]: { ...(this.state?.[key] ?? {}), [field]: value } };
        },

        remember(el) {
            const selection = window.getSelection();

            if (selection.rangeCount && el.contains(selection.anchorNode)) {
                this.savedRanges[`${el.dataset.editorKey}.${el.dataset.editorField}`] = selection.getRangeAt(0).cloneRange();
            }
        },

        currentRange(el) {
            const selection = window.getSelection();

            if (selection.rangeCount && el.contains(selection.anchorNode)) {
                return selection.getRangeAt(0);
            }

            const saved = this.savedRanges[`${el.dataset.editorKey}.${el.dataset.editorField}`];

            if (saved && el.contains(saved.startContainer)) {
                return saved;
            }

            const range = document.createRange();

            range.selectNodeContents(el);
            range.collapse(false);

            return range;
        },

        place(el, range) {
            const selection = window.getSelection();

            el.focus();
            selection.removeAllRanges();
            selection.addRange(range);
            this.commit(el);
            this.remember(el);
        },

        insertText(el, text) {
            const range = this.currentRange(el);
            const node = document.createTextNode(text);

            range.deleteContents();
            range.insertNode(node);

            let caretAfter = node;

            if (text.endsWith('\n') && ! node.nextSibling) {
                caretAfter = document.createTextNode(CARET_ANCHOR);
                node.after(caretAfter);
            }

            range.setStartAfter(caretAfter);
            range.collapse(true);
            this.place(el, range);
        },

        insertToken(key, field, name) {
            const el = this.editorFor(key, field);
            const range = this.currentRange(el);
            const token = this.tokenNode(el.dataset.editorLocale, name);
            const anchor = document.createTextNode(CARET_ANCHOR);

            range.deleteContents();
            range.insertNode(token);
            token.after(anchor);
            range.setStart(anchor, 1);
            range.collapse(true);
            this.place(el, range);
        },

        preview(key, field) {
            const section = this.config.sections.find((item) => item.key === key);
            const samples = this.config.samples[section.locale] ?? {};

            return this.textOf(key, field).replace(PLACEHOLDER, (_, name) => samples[name] ?? this.labelFor(section.locale, name));
        },
    };
}
