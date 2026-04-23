/*
 * extra-vars-editor.js
 *
 * Progressive enhancement for `<textarea data-extra-vars-editor>` fields.
 * Replaces the visible textarea with a CodeMirror editor that supports
 * live JSON/YAML switching while keeping the backing form field in JSON
 * so the server contract (extra_vars is JSON) stays unchanged.
 *
 * Usage in a view:
 *   <?= $form->field($model, 'extra_vars')->textarea([
 *       'data-extra-vars-editor' => '1',
 *       'rows' => 6,
 *   ]) ?>
 *
 * The page must load codemirror.js + json + yaml modes + js-yaml.min.js
 * before this file. See docker/php/layouts/main.php.
 */
(function () {
    'use strict';

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
        controls.appendChild(status);

        // Hide the original textarea — keep it in the DOM so the form submits
        // the value we keep synced to it. Retains the label/error wiring that
        // Yii's ActiveForm set up on the textarea.
        textarea.style.display = 'none';

        // Initial format is always JSON because that's what the server stores.
        // Populate the CM editor with either the current JSON value or an
        // empty buffer.
        var format = 'json';
        var initial = textarea.value || '';

        var cm = CodeMirror(editorDiv, {
            value: initial,
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

        // Sync editor → hidden textarea on every keystroke. When YAML mode is
        // active, parse YAML then dump JSON; when invalid, leave the textarea
        // at the last valid JSON value so the server doesn't receive garbage.
        cm.on('change', function () {
            validateAndSync();
        });
        validateAndSync();

        // Toggle handler: convert buffer on the fly, swap mode, update active class.
        toggle.addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-ev-format]');
            if (!btn) {
                return;
            }
            var next = btn.getAttribute('data-ev-format');
            if (next === format) {
                return;
            }
            convertBuffer(format, next);
            format = next;
            cm.setOption('mode', modeFor(next));
            toggle.querySelectorAll('button').forEach(function (b) {
                b.classList.toggle('active', b.getAttribute('data-ev-format') === next);
            });
            // Sync again — the converted buffer might now parse differently.
            validateAndSync();
        });

        function validateAndSync() {
            var raw = cm.getValue();
            if (raw.trim() === '') {
                textarea.value = '';
                setStatus('', 'text-muted');
                return;
            }
            try {
                var parsed = format === 'json' ? JSON.parse(raw) : jsyaml.load(raw);
                // Normalize to JSON for the server no matter what the user typed.
                textarea.value = JSON.stringify(parsed);
                setStatus('✓ valid ' + format.toUpperCase(), 'text-success');
            } catch (err) {
                setStatus('✗ ' + (format === 'json' ? 'JSON' : 'YAML') + ': ' + (err.message || 'parse error'), 'text-danger');
                // Don't overwrite textarea with garbage — keep the last valid JSON.
            }
        }

        function convertBuffer(fromFormat, toFormat) {
            var raw = cm.getValue();
            if (raw.trim() === '') {
                return;
            }
            var parsed;
            try {
                parsed = fromFormat === 'json' ? JSON.parse(raw) : jsyaml.load(raw);
            } catch (err) {
                // Invalid in the source format — don't convert, just swap mode
                // so the user can fix it and re-convert.
                return;
            }
            var dumped = toFormat === 'json'
                ? JSON.stringify(parsed, null, 2)
                : jsyaml.dump(parsed, {lineWidth: 100, noRefs: true});
            cm.setValue(dumped);
        }

        function modeFor(fmt) {
            return fmt === 'json' ? {name: 'javascript', json: true} : 'yaml';
        }

        function setStatus(text, cls) {
            status.textContent = text;
            status.className = 'extra-vars-editor__status small ' + cls;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
