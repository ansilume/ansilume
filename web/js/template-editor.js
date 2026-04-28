/*
 * template-editor.js
 *
 * Progressive enhancement for `<textarea data-template-editor>` fields
 * that carry Twig/Jinja-style notification template content. Mounts
 * CodeMirror with a tiny custom mode that highlights:
 *
 *   {{ var }}    → cm-property  (variable interpolation)
 *   {% tag %}    → cm-keyword   (control / block tag)
 *   {# comment #} → cm-comment  (template comment)
 *
 * The mode is defined inline as a plain CM mode (no overlay addon
 * needed), so the host page only has to load codemirror.js itself —
 * no extra vendored mode files. Backing textarea always carries the
 * raw template text — no transformation, server stores it literally.
 *
 * Usage:
 *   <?= $form->field($model, 'body_template')->textarea([
 *       'data-template-editor' => '1',
 *       'rows' => 8,
 *   ]) ?>
 */
(function () {
    'use strict';

    function defineTemplateMode() {
        if (CodeMirror.modes && CodeMirror.modes['ansilume-template']) {
            return;
        }
        CodeMirror.defineMode('ansilume-template', function () {
            return {
                token: function (stream) {
                    if (stream.match('{{')) {
                        while (stream.next() != null && !stream.match('}}', false)) {
                            /* consume up to the closing pair */
                        }
                        stream.match('}}');
                        return 'property';
                    }
                    if (stream.match('{%')) {
                        while (stream.next() != null && !stream.match('%}', false)) {
                            /* consume */
                        }
                        stream.match('%}');
                        return 'keyword';
                    }
                    if (stream.match('{#')) {
                        while (stream.next() != null && !stream.match('#}', false)) {
                            /* consume */
                        }
                        stream.match('#}');
                        return 'comment';
                    }
                    // Skip plain prose one character at a time but bail out
                    // as soon as we hit the next potential opening brace —
                    // the next token() call re-checks the templating triggers.
                    while (stream.next() != null) {
                        if (stream.peek() === '{') {
                            return null;
                        }
                    }
                    return null;
                },
            };
        });
    }

    function boot() {
        if (typeof CodeMirror === 'undefined') {
            return;
        }
        defineTemplateMode();

        var textareas = document.querySelectorAll('textarea[data-template-editor]');
        for (var i = 0; i < textareas.length; i++) {
            attach(textareas[i]);
        }
    }

    function attach(textarea) {
        if (textarea.dataset.templateEditorReady) {
            return;
        }
        textarea.dataset.templateEditorReady = '1';

        var shell = document.createElement('div');
        shell.className = 'template-editor';
        textarea.parentNode.insertBefore(shell, textarea);

        var editorDiv = document.createElement('div');
        editorDiv.className = 'template-editor__cm';
        shell.appendChild(editorDiv);

        textarea.style.display = 'none';

        var rows = parseInt(textarea.getAttribute('rows') || '8', 10);
        var pixelHeight = Math.max(160, rows * 18);

        var cm = CodeMirror(editorDiv, {
            value: textarea.value || '',
            mode: 'ansilume-template',
            lineNumbers: true,
            matchBrackets: true,
            styleActiveLine: true,
            indentUnit: 2,
            tabSize: 2,
            lineWrapping: true,
            viewportMargin: Infinity,
        });
        editorDiv.querySelector('.CodeMirror').style.height = pixelHeight + 'px';

        cm.on('change', function () {
            textarea.value = cm.getValue();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
