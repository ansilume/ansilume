/*
 * yaml-editor.js
 *
 * Progressive enhancement for `<textarea data-yaml-editor>` fields.
 * Replaces the visible textarea with a CodeMirror editor in YAML mode.
 * Unlike extra-vars-editor.js, the backing textarea always carries raw
 * YAML — there is no JSON-on-the-wire conversion because every consumer
 * of these fields (e.g. inventory.content) stores YAML as-is.
 *
 * Usage in a view:
 *   <?= $form->field($model, 'content')->textarea([
 *       'data-yaml-editor' => '1',
 *       'rows' => 12,
 *   ]) ?>
 *
 * The page must load codemirror.js + yaml mode + js-yaml.min.js before
 * this file. See views/inventory/form.php for a reference wiring.
 */
(function () {
    'use strict';

    function boot() {
        var textareas = document.querySelectorAll('textarea[data-yaml-editor]');
        for (var i = 0; i < textareas.length; i++) {
            attach(textareas[i]);
        }
    }

    function attach(textarea) {
        if (typeof CodeMirror === 'undefined') {
            // CM didn't load — degrade gracefully to the plain textarea.
            return;
        }
        if (textarea.dataset.yamlEditorReady) {
            return;
        }
        textarea.dataset.yamlEditorReady = '1';

        // Build the UI shell:
        //   [editor div]
        //   [status line]
        var shell = document.createElement('div');
        shell.className = 'yaml-editor';
        textarea.parentNode.insertBefore(shell, textarea);

        var editorDiv = document.createElement('div');
        editorDiv.className = 'yaml-editor__cm';
        shell.appendChild(editorDiv);

        var status = document.createElement('div');
        status.className = 'yaml-editor__status small text-muted mt-1';
        shell.appendChild(status);

        // Hide the original textarea — keep it in the DOM so the form submits
        // the value we keep synced to it. Retains the label/error wiring that
        // Yii's ActiveForm set up on the textarea.
        textarea.style.display = 'none';

        var rows = parseInt(textarea.getAttribute('rows') || '12', 10);
        var pixelHeight = Math.max(180, rows * 18);

        var cm = CodeMirror(editorDiv, {
            value: textarea.value || '',
            mode: 'yaml',
            lineNumbers: true,
            matchBrackets: true,
            autoCloseBrackets: true,
            styleActiveLine: true,
            indentUnit: 2,
            tabSize: 2,
            lineWrapping: true,
            viewportMargin: Infinity,
        });
        editorDiv.querySelector('.CodeMirror').style.height = pixelHeight + 'px';

        cm.on('change', function () {
            // Always write the raw editor buffer back to the textarea — the
            // server stores YAML literally, so we must not transform it.
            textarea.value = cm.getValue();
            validate();
        });
        validate();

        function validate() {
            var raw = cm.getValue();
            if (raw.trim() === '') {
                setStatus('', 'text-muted');
                return;
            }
            if (typeof jsyaml === 'undefined') {
                // js-yaml not loaded — skip validation, the editor still works.
                setStatus('', 'text-muted');
                return;
            }
            try {
                jsyaml.load(raw);
                setStatus('\u2713 valid YAML', 'text-success');
            } catch (err) {
                setStatus('\u2717 YAML: ' + (err.message || 'parse error'), 'text-danger');
            }
        }

        function setStatus(text, cls) {
            status.textContent = text;
            status.className = 'yaml-editor__status small ' + cls;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
