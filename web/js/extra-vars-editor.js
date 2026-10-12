/*
 * extra-vars-editor.js
 *
 * Progressive enhancement for `<textarea data-extra-vars-editor>` fields:
 * a CodeMirror editor with a JSON/YAML switch over the textarea. The
 * textarea stays the form field, hidden, and the server stores JSON.
 *
 * What the form posts:
 *   - On load the field keeps the stored text exactly: a save without edits
 *     posts it unchanged.
 *   - In JSON mode an edit posts the text as typed whenever it parses, so
 *     every number keeps its exact text. The server checks the rest (a
 *     number too large for JSON, such as 1e999, is refused there).
 *   - YAML mode and the switch go through JavaScript values, whose numbers
 *     are doubles. They refuse text that holds a number a double cannot
 *     carry exactly (an integer beyond ±9007199254740991, or a number too
 *     large for JSON: 1e999, YAML's .inf and .nan) instead of changing it,
 *     and leave the text and the format as they are.
 *   - While the text does not parse, or YAML mode refuses it, the form is
 *     not submitted: the status line says why and the editor gets the focus.
 *     The field then holds the text as shown, never an older value.
 *
 * Usage in a view:
 *   <?= $form->field($model, 'extra_vars')->textarea([
 *       'data-extra-vars-editor' => '1',
 *       'rows' => 6,
 *   ]) ?>
 *
 * The page must load codemirror.js + json + yaml modes + js-yaml.min.js
 * before this file; without them the plain textarea stays.
 */
(function () {
    'use strict';

    var LIMITS = 'an integer beyond ±9007199254740991, or a number too large for JSON';
    var NOT_CONVERTED_TO_YAML = 'Not converted to YAML: the JSON holds a number the editor cannot convert exactly ('
        + LIMITS + '). Edit it as JSON.';
    var INEXACT_YAML = 'The YAML holds a number the editor cannot convert exactly (' + LIMITS
        + '). Remove it, switch to JSON and enter it there.';
    // The editor's mark while a submit it blocked waits for valid text.
    var BLOCKED_CLASS = 'extra-vars-editor--blocked';

    function boot() {
        var textareas = document.querySelectorAll('textarea[data-extra-vars-editor]');
        for (var i = 0; i < textareas.length; i++) {
            attach(textareas[i]);
        }
    }

    function attach(textarea) {
        if (typeof CodeMirror === 'undefined' || typeof jsyaml === 'undefined') {
            // Libraries didn't load — degrade gracefully to the plain textarea.
            return;
        }
        if (textarea.dataset.extraVarsEditorReady) {
            return;
        }
        textarea.dataset.extraVarsEditorReady = '1';

        // Build the UI shell:
        //   [editor div]
        //   [format toggle (JSON | YAML)] [status line]
        var shell = document.createElement('div');
        shell.className = 'extra-vars-editor';
        textarea.parentNode.insertBefore(shell, textarea);

        var editorDiv = document.createElement('div');
        editorDiv.className = 'extra-vars-editor__cm';
        shell.appendChild(editorDiv);

        var controls = document.createElement('div');
        controls.className = 'extra-vars-editor__controls d-flex justify-content-between align-items-center mt-1';
        shell.appendChild(controls);

        var toggle = document.createElement('div');
        toggle.className = 'btn-group btn-group-sm';
        toggle.setAttribute('role', 'group');
        toggle.innerHTML =
            '<button type="button" class="btn btn-outline-secondary active" data-ev-format="json">JSON</button>' +
            '<button type="button" class="btn btn-outline-secondary" data-ev-format="yaml">YAML</button>';
        controls.appendChild(toggle);

        var status = document.createElement('div');
        status.className = 'extra-vars-editor__status small text-muted';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        controls.appendChild(status);

        // Hide the original textarea — keep it in the DOM so the form submits
        // the value we keep synced to it. Retains the label/error wiring that
        // Yii's ActiveForm set up on the textarea.
        textarea.style.display = 'none';

        // The server stores JSON, so the editor starts in JSON mode with the
        // stored text.
        var format = 'json';
        // Why the form must not be submitted now, or null.
        var problem = null;

        var cm = CodeMirror(editorDiv, {
            value: textarea.value || '',
            mode: modeFor('json'),
            lineNumbers: true,
            matchBrackets: true,
            autoCloseBrackets: true,
            styleActiveLine: true,
            indentUnit: 2,
            tabSize: 2,
            lineWrapping: true,
            viewportMargin: Infinity,
        });

        // Keep the editor a reasonable height — auto-sizing is too jumpy.
        // Border + background colours are set by /css/extra-vars-editor.css
        // so the editor matches the app's dark-mode theme.
        editorDiv.querySelector('.CodeMirror').style.height = '200px';

        // Only an edit or a conversion writes the field: until then it keeps
        // the stored text, byte for byte.
        cm.on('change', sync);
        show(read());

        toggle.addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-ev-format]');
            if (btn) {
                switchTo(btn.getAttribute('data-ev-format'));
            }
        });

        guardSubmit();

        // An editor that starts in a collapsed card (the launch page's
        // Advanced Overrides) is measured again once the card opens.
        var collapse = shell.closest('.collapse');
        if (collapse) {
            collapse.addEventListener('shown.bs.collapse', function () {
                cm.refresh();
            });
        }

        /**
         * What the buffer posts in the current format, and why it must not
         * be submitted (problem, null when it may).
         */
        function read() {
            var raw = cm.getValue();
            if (raw.trim() === '') {
                return {post: '', problem: null, empty: true};
            }
            var parsed = parse(raw, format);
            if (parsed.error !== null) {
                return {post: raw, problem: parsed.error, empty: false};
            }
            if (format === 'json') {
                // As typed: no rewrite, so every number keeps its exact text.
                return {post: raw, problem: null, empty: false};
            }
            if (parsed.value === null || parsed.value === undefined) {
                // An empty YAML document: comments only, or null.
                return {post: '', problem: null, empty: true};
            }
            if (!isExact(parsed.value)) {
                return {post: raw, problem: INEXACT_YAML, empty: false};
            }
            var json = stringify(parsed.value, null);
            return json.error === null
                ? {post: json.text, problem: null, empty: false}
                : {post: raw, problem: json.error, empty: false};
        }

        /** Write the field from the buffer: after an edit or a conversion. */
        function sync() {
            var state = read();
            textarea.value = state.post;
            show(state);
        }

        function show(state) {
            problem = state.problem;
            if (problem !== null) {
                setStatus('✗ ' + problem, 'text-danger');
                return;
            }
            shell.classList.remove(BLOCKED_CLASS);
            if (state.empty) {
                setStatus('', 'text-muted');
            } else {
                setStatus('✓ valid ' + format.toUpperCase(), 'text-success');
            }
        }

        /**
         * Convert the buffer to the other format. A buffer that holds a number
         * the conversion would change is left as it is, in its format. One
         * that does not parse is not converted: only the mode changes, so the
         * text can be fixed in the other format (YAML pasted in JSON mode).
         */
        function switchTo(next) {
            if (next === format) {
                return;
            }
            var raw = cm.getValue();
            var converted = null;
            var parsed = raw.trim() === '' ? null : parse(raw, format);
            if (parsed !== null && parsed.error === null) {
                if (!isExact(parsed.value)) {
                    refuseSwitch();
                    return;
                }
                converted = convert(parsed.value, next);
                if (converted.error !== null) {
                    setStatus('✗ ' + converted.error, 'text-danger');
                    return;
                }
            }
            format = next;
            cm.setOption('mode', modeFor(next));
            toggle.querySelectorAll('button').forEach(function (b) {
                b.classList.toggle('active', b.getAttribute('data-ev-format') === next);
            });
            if (converted !== null && converted.text !== raw) {
                // Fires 'change', which syncs the field.
                cm.setValue(converted.text);
            }
            sync();
        }

        function refuseSwitch() {
            if (format === 'json') {
                // The JSON stays valid and may be saved as it is.
                setStatus('✗ ' + NOT_CONVERTED_TO_YAML, 'text-warning');
                return;
            }
            setStatus('✗ ' + INEXACT_YAML, 'text-danger');
        }

        /**
         * Block a submit while the buffer has a problem. Capturing on the
         * document runs before the form's own submit handlers (Yii's
         * ActiveForm validates and submits from there), so a blocked submit
         * never reaches them.
         */
        function guardSubmit() {
            var form = textarea.form;
            if (!form) {
                return;
            }
            document.addEventListener('submit', function (e) {
                if (e.target !== form || problem === null) {
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                showBlocked();
            }, true);
        }

        function showBlocked() {
            shell.classList.add(BLOCKED_CLASS);
            setStatus('✗ ' + problem, 'text-danger');
            var collapsed = shell.closest('.collapse:not(.show)');
            if (collapsed) {
                if (window.bootstrap && window.bootstrap.Collapse) {
                    window.bootstrap.Collapse.getOrCreateInstance(collapsed, {toggle: false}).show();
                } else {
                    collapsed.classList.add('show');
                }
            }
            shell.scrollIntoView({block: 'center'});
            cm.focus();
        }

        function setStatus(text, cls) {
            status.textContent = text;
            status.className = 'extra-vars-editor__status small ' + cls;
        }
    }

    /** The value of text in fmt ('json' or 'yaml'), or why it does not parse. */
    function parse(text, fmt) {
        try {
            return {value: fmt === 'json' ? JSON.parse(text) : jsyaml.load(text), error: null};
        } catch (err) {
            return {value: undefined, error: label(fmt) + ': ' + (err.message || 'parse error')};
        }
    }

    /** value as text of the format fmt, for the buffer. */
    function convert(value, fmt) {
        if (value === undefined) {
            // An empty YAML document.
            return {text: '', error: null};
        }
        if (fmt === 'json') {
            return stringify(value, 2);
        }
        try {
            return {text: jsyaml.dump(value, {lineWidth: 100, noRefs: true}), error: null};
        } catch (err) {
            return {text: '', error: 'YAML: ' + (err.message || 'cannot be written')};
        }
    }

    /** value as JSON text; a structure that refers to itself (YAML aliases) cannot be. */
    function stringify(value, indent) {
        try {
            return {text: JSON.stringify(value, null, indent), error: null};
        } catch (err) {
            return {text: '', error: 'JSON: ' + (err.message || 'cannot be written')};
        }
    }

    /**
     * Whether every number in value survives a conversion through JavaScript
     * unchanged: finite, and, when it is a whole number, one a double holds
     * exactly. JSON.parse reads 9007199254740993 as 9007199254740992 and
     * 1e999 as Infinity; JSON.stringify writes Infinity and NaN as null.
     */
    function isExact(value) {
        var pending = [value];
        var seen = new WeakSet();
        while (pending.length > 0) {
            var item = pending.pop();
            if (typeof item === 'number') {
                if (!Number.isFinite(item) || (Number.isInteger(item) && !Number.isSafeInteger(item))) {
                    return false;
                }
            } else if (item !== null && typeof item === 'object' && !seen.has(item)) {
                seen.add(item);
                var keys = Object.keys(item);
                for (var i = 0; i < keys.length; i++) {
                    pending.push(item[keys[i]]);
                }
            }
        }
        return true;
    }

    function label(fmt) {
        return fmt === 'json' ? 'JSON' : 'YAML';
    }

    function modeFor(fmt) {
        return fmt === 'json' ? {name: 'javascript', json: true} : 'yaml';
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
