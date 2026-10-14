/*
 * workflow-step-form.js
 *
 * The step form of a workflow (views/workflow-template/_step-form.php): Add
 * Step on the workflow template page, and the form of the page that edits a
 * step. The server renders the form for the step's type and the selected
 * Position, and every text this script shows; the script keeps the form so
 * when the user changes the type (Add Step only) or the Position:
 *
 *   - Only the target and the branches the type takes are shown. The others
 *     are hidden and disabled, so the browser neither checks nor posts them:
 *     a hidden required select kept the form from being sent.
 *   - A pause step's On Success reads "After Resume → go to".
 *   - The routing help of the type is shown.
 *   - The Position preview, its warnings (the job-step warning only for a
 *     job step, the approval-step warning only for an approval step) and
 *     the "Next in the list" choices are copied from the data-* attributes
 *     of the selected Position.
 *   - Add Step: an approval step's empty On Failure ends the run as failed,
 *     so its second end choice ("0") is detached, and attached again for the
 *     other types (Safari ignores hidden options). A new job step ends the
 *     workflow when it fails: switching to a job step selects "0" again,
 *     unless the user chose another On Failure (data-touched): then the
 *     user's latest choice, under any type (an approval step's empty choice
 *     ends the run as failed: "0").
 *
 * "+ Insert below" opens the page at the form (#add-step), where a browser
 * skips autofocus: once the page has loaded, the script focuses the field
 * the server marks with autofocus (the name), unless the user has focused
 * something else.
 *
 * Texts are set with textContent only.
 */
(function () {
    'use strict';

    var TYPE_JOB = 'job';
    var TYPE_APPROVAL = 'approval';
    var TYPE_PAUSE = 'pause';
    var HELP_TYPES = [TYPE_JOB, TYPE_APPROVAL, TYPE_PAUSE];
    // The value of the branch that ends the workflow.
    var END = '0';

    function byId(id) {
        return document.getElementById(id);
    }

    // Show or hide the element `id`, and enable or disable its selects.
    function showPart(id, visible) {
        var part = byId(id);
        if (!part) {
            return;
        }
        part.style.display = visible ? '' : 'none';
        var selects = part.querySelectorAll('select');
        for (var i = 0; i < selects.length; i++) {
            selects[i].disabled = !visible;
        }
    }

    function hideClass(element, hidden) {
        if (element) {
            element.classList.toggle('d-none', hidden);
        }
    }

    function setText(element, text) {
        if (element) {
            element.textContent = text || '';
        }
    }

    // The empty choice of a select: its prompt.
    function emptyChoice(select) {
        return select ? select.querySelector('option[value=""]') : null;
    }

    function selectedPosition() {
        var select = byId('ws-position');
        if (!select || select.selectedIndex < 0) {
            return null;
        }
        return select.options[select.selectedIndex];
    }

    function chosenType() {
        var select = byId('ws-step-type');
        return select ? select.value : TYPE_JOB;
    }

    function isAdd(form) {
        return form.getAttribute('data-step-form') === 'add';
    }

    // Two texts as one line, without empty ones.
    function joinTexts(first, second) {
        var texts = [];
        if (first) {
            texts.push(first);
        }
        if (second) {
            texts.push(second);
        }
        return texts.join(' ');
    }

    function syncSuccessColumn(type) {
        var column = byId('ws-route-success');
        if (!column) {
            return;
        }
        var isPause = type === TYPE_PAUSE;
        column.classList.toggle('col-md-4', !isPause);
        column.classList.toggle('col-md-12', isPause);
        setText(column.querySelector('label'), column.getAttribute(isPause ? 'data-label-pause' : 'data-label-default'));
    }

    function syncHelp(type) {
        for (var i = 0; i < HELP_TYPES.length; i++) {
            hideClass(byId('ws-routing-help-' + HELP_TYPES[i]), HELP_TYPES[i] !== type);
        }
    }

    // The empty On Failure choice says where an empty branch leads: for an
    // approval step the end of the run, else the next step of the Position.
    function syncFailurePrompt(type, position) {
        var column = byId('ws-route-failure');
        var prompt = emptyChoice(byId('workflowstep-on_failure_step_id'));
        if (!column || !prompt) {
            return;
        }
        if (type === TYPE_APPROVAL) {
            setText(prompt, column.getAttribute('data-prompt-approval'));
        } else if (position) {
            setText(prompt, position.getAttribute('data-failure-prompt'));
        } else {
            setText(prompt, column.getAttribute('data-prompt-job'));
        }
    }

    // The Position's warnings for a step of `type`: data-warn for any type,
    // then data-warn-job for a job step or data-warn-approval for an
    // approval step (Add Step; Edit Step puts every warning in data-warn).
    //
    // Regression: only data-warn and data-warn-job were shown, so Add Step
    // never showed the warning the server renders for an approval step that
    // no success would lead to (data-warn-approval).
    function syncPosition(type) {
        var position = selectedPosition();
        if (position) {
            var warning = joinTexts(
                position.getAttribute('data-warn'),
                type === TYPE_JOB ? position.getAttribute('data-warn-job') : (type === TYPE_APPROVAL ? position.getAttribute('data-warn-approval') : '')
            );
            var box = byId('ws-position-warning');
            setText(byId('ws-position-preview'), position.getAttribute('data-preview'));
            setText(box, warning);
            hideClass(box, warning === '');
            setText(emptyChoice(byId('workflowstep-on_success_step_id')), position.getAttribute('data-success-prompt'));
        }
        syncFailurePrompt(type, position);
    }

    // Add Step: an approval step's only end choice is the empty one.
    function syncEndChoice(state) {
        var failure = byId('workflowstep-on_failure_step_id');
        if (!failure) {
            return;
        }
        var end = failure.querySelector('option[value="' + END + '"]');
        if (state.type === TYPE_APPROVAL && end) {
            state.detachedEnd = end;
            failure.removeChild(end);
        } else if (state.type !== TYPE_APPROVAL && !end && state.detachedEnd) {
            var empty = emptyChoice(failure);
            failure.insertBefore(state.detachedEnd, empty ? empty.nextSibling : failure.firstChild);
            state.detachedEnd = null;
        }
    }

    function syncType(form, state) {
        var type = state.type;
        showPart('ws-target-job', type === TYPE_JOB);
        showPart('ws-target-approval', type === TYPE_APPROVAL);
        showPart('ws-route-failure', type !== TYPE_PAUSE);
        showPart('ws-route-always', type !== TYPE_PAUSE && type !== TYPE_APPROVAL);
        syncSuccessColumn(type);
        syncHelp(type);
        if (isAdd(form)) {
            syncEndChoice(state);
            hideClass(byId('ws-failure-default-note'), type !== TYPE_JOB);
        }
        syncPosition(type);
    }

    // Add Step: back to a job step, On Failure ends the workflow again,
    // unless the user chose another branch: then it is the latest choice,
    // made as a job step (captured here when the form leaves the job type)
    // or as an approval step (captured by the select's change listener).
    //
    // Regression: only the choice the form had when it left the job type
    // was kept, so a step chosen while the type was Approval became "0"
    // again back on Job, and an end chosen as an approval step became the
    // step chosen before.
    function changeType(form, state) {
        var failure = byId('workflowstep-on_failure_step_id');
        var previous = state.type;
        if (failure && previous === TYPE_JOB) {
            state.jobFailure = failure.value;
        }
        state.type = chosenType();
        syncType(form, state);
        if (failure && isAdd(form) && state.type === TYPE_JOB && previous !== TYPE_JOB) {
            failure.value = failure.hasAttribute('data-touched') ? state.jobFailure : END;
        }
    }

    // A browser skips autofocus on a page opened at a fragment ("+ Insert
    // below" links to #add-step) and moves the focus to the fragment's
    // target, so the [autofocus] field is focused here, after the page has
    // loaded. Only when nothing has the focus: a field the user focused
    // meanwhile keeps it.
    function focusAutofocus(form) {
        var field = form.querySelector('[autofocus]');
        if (!field) {
            return;
        }
        var focus = function () {
            if (!document.activeElement || document.activeElement === document.body) {
                field.focus();
            }
        };
        var later = function () {
            setTimeout(focus, 0);
        };
        if (document.readyState === 'complete') {
            later();
        } else {
            window.addEventListener('load', later);
        }
    }

    function attach(form) {
        var typeSelect = byId('ws-step-type');
        var position = byId('ws-position');
        var failure = byId('workflowstep-on_failure_step_id');
        var state = {
            type: chosenType(),
            jobFailure: failure ? failure.value : '',
            detachedEnd: null
        };
        if (typeSelect) {
            typeSelect.addEventListener('change', function () {
                changeType(form, state);
            });
        }
        if (position) {
            position.addEventListener('change', function () {
                syncPosition(state.type);
            });
        }
        if (failure) {
            failure.addEventListener('change', function () {
                failure.setAttribute('data-touched', '1');
                // The latest choice, whatever the type: back to a job step,
                // changeType() restores it. An approval step's empty choice
                // ends the run as failed, which is "0" for a job step.
                state.jobFailure = (state.type === TYPE_APPROVAL && failure.value === '') ? END : failure.value;
            });
        }
        syncType(form, state);
        focusAutofocus(form);
    }

    function boot() {
        var forms = document.querySelectorAll('form[data-step-form]');
        for (var i = 0; i < forms.length; i++) {
            attach(forms[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
