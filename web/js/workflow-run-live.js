/*
 * workflow-run-live.js
 *
 * Live updates of a workflow run's page (views/workflow-job/view.php).
 * While the run has not finished, the page carries
 *   <div id="wj-live-root" hidden data-poll-url data-job-url data-banner>
 * and this script polls data-poll-url (/workflow-job/status): once when
 * the page has loaded, then three seconds after an answer that changed
 * something (the run's state, its banner or the state of a step: a step
 * whose duration grew changed nothing), and after an answer that changed
 * nothing, or a poll that failed, twice as long as the last time, at most
 * 30 seconds. While the page is hidden (another tab, a minimized window)
 * it does not poll; shown again, it polls at once and starts again from
 * three seconds. Each answer updates the page in place, never by loading
 * it again:
 *   - the run's badge (#wj-status) shows its display state;
 *   - the banner (#wj-wait-banner) is drawn again only when it changed, so
 *     its live region announces real changes only;
 *   - the Resume and Cancel forms are shown or hidden as "actions" says;
 *   - each step row is updated, and a step the run reached since is added.
 * Once the run has finished, the banner and both forms are hidden, the Live
 * badge goes and polling stops. A poll that is refused (401 or 403: signed
 * out, or no access any more; 404: the run is gone) stops polling for good:
 * the Live badge goes, and the page's note (#wj-live-stopped, the server's
 * text) asks for a reload.
 *
 * The answer is server text: it is written only as text (textContent, text
 * nodes) and attribute values. A CSS class is used only when it matches
 * ^[a-z-]+$, an address only when it starts with a single "/". A field the
 * answer lacks leaves the page as it is.
 */
(function () {
    'use strict';

    var INTERVAL = 3000;
    var MAX_INTERVAL = 30000;
    var STOP_STATUSES = [401, 403, 404];
    var NO_VALUE = '—';
    var SEPARATOR = ' · ';
    var SAFE_CLASS = /^[a-z-]+$/;
    var SAFE_HREF = /^\/(?![\/\\])/;
    var DIGITS = /^\d+$/;

    function start() {
        var root = document.getElementById('wj-live-root');
        var pollUrl = root ? root.getAttribute('data-poll-url') : null;
        if (!pollUrl) {
            return;
        }
        var live = {
            pollUrl: pollUrl,
            jobUrl: root.getAttribute('data-job-url') || '',
            banner: initialBanner(root),
            stopped: false,
            // The pace of the polls (pace()): the next wait, and the last answer's key.
            interval: INTERVAL,
            answer: null,
            // The next poll's timer, and whether a poll is on its way.
            timer: null,
            polling: false
        };
        document.addEventListener('visibilitychange', function () {
            shown(live);
        });
        tick(live);
    }

    // The banner the page was rendered with, in the form drawBanner() compares.
    function initialBanner(root) {
        try {
            return JSON.stringify(JSON.parse(root.getAttribute('data-banner') || 'null'));
        } catch (e) {
            return null;
        }
    }

    // One poll, unless polling stopped or the page is hidden (shown() polls when it is shown again).
    function tick(live) {
        live.timer = null;
        if (live.stopped || document.hidden) {
            return;
        }
        live.polling = true;
        fetch(live.pollUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (response) {
                return read(live, response);
            })
            .then(function (data) {
                pace(live, data);
                apply(live, data);
            })
            .catch(function () {
                // A transient network error: the next poll tries again, later.
                pace(live, null);
            })
            .then(function () {
                live.polling = false;
                schedule(live);
            });
    }

    // The answer's data; null for a poll that failed. A refused poll stops polling for good.
    function read(live, response) {
        if (STOP_STATUSES.indexOf(response.status) !== -1) {
            stop(live);
            return null;
        }
        return response.ok ? response.json() : null;
    }

    // The next poll, after the current interval; none while the page is hidden.
    function schedule(live) {
        if (live.stopped || document.hidden || live.timer !== null) {
            return;
        }
        live.timer = window.setTimeout(function () {
            tick(live);
        }, live.interval);
    }

    // Three seconds after an answer that changed something; after one that
    // did not, or after a failed poll, twice the last interval, at most MAX_INTERVAL.
    function pace(live, data) {
        var key = data && typeof data === 'object' ? answerKey(data) : null;
        if (key !== null && key !== live.answer) {
            live.answer = key;
            live.interval = INTERVAL;
            return;
        }
        live.interval = Math.min(live.interval * 2, MAX_INTERVAL);
    }

    // What tells answers apart: the run's state, its banner and each step's state.
    function answerKey(data) {
        var steps = Array.isArray(data.steps) ? data.steps.map(function (step) {
            return step && typeof step === 'object' ? [step.id, step.state] : null;
        }) : null;
        return JSON.stringify([data.state, data.banner, steps]);
    }

    // The page is shown again: poll at once, and from there as after a change.
    function shown(live) {
        if (live.stopped || document.hidden) {
            return;
        }
        live.interval = INTERVAL;
        live.answer = null;
        if (live.polling) {
            // The poll on its way schedules the next one.
            return;
        }
        if (live.timer !== null) {
            window.clearTimeout(live.timer);
        }
        tick(live);
    }

    // The poll was refused: no poll any more, and the note asks for a reload in place of the Live badge.
    function stop(live) {
        live.stopped = true;
        removeLiveBadge();
        toggle('wj-live-stopped', true);
    }

    function apply(live, data) {
        if (!data || typeof data !== 'object') {
            return;
        }
        setBadge(document.getElementById('wj-status'), pick(data.state_label, data.status_label), pick(data.state_css, data.status_css));
        var finishedEl = document.getElementById('wj-finished');
        if (finishedEl && data.finished_label) {
            finishedEl.textContent = String(data.finished_label);
        }
        if (Array.isArray(data.steps)) {
            data.steps.forEach(function (step) {
                updateRow(live, step);
            });
        }
        if (data.is_finished) {
            finish(live);
            return;
        }
        drawBanner(live, data.banner);
        showActions(data.actions);
    }

    // The run has finished: what waited is over, and nothing can be resumed or canceled.
    function finish(live) {
        live.stopped = true;
        var banner = document.getElementById('wj-wait-banner');
        if (banner) {
            banner.hidden = true;
        }
        toggle('wj-resume-form', false);
        toggle('wj-cancel-form', false);
        removeLiveBadge();
    }

    function removeLiveBadge() {
        var liveBadge = document.getElementById('wj-live');
        if (liveBadge && liveBadge.parentNode) {
            liveBadge.parentNode.removeChild(liveBadge);
        }
    }

    function showActions(actions) {
        if (!actions || typeof actions !== 'object') {
            return;
        }
        toggle('wj-resume-form', actions.resume === true);
        toggle('wj-cancel-form', actions.cancel === true);
    }

    function toggle(id, shown) {
        var el = document.getElementById(id);
        if (el) {
            el.hidden = !shown;
        }
    }

    function drawBanner(live, banner) {
        var el = document.getElementById('wj-wait-banner');
        if (!el || banner === undefined) {
            return;
        }
        var key = JSON.stringify(banner);
        if (key === live.banner) {
            return;
        }
        live.banner = key;
        clear(el);
        if (!banner || typeof banner !== 'object') {
            el.hidden = true;
            el.className = 'alert';
            el.removeAttribute('data-wait');
            return;
        }
        el.className = 'alert alert-' + safeClass(banner.tone, 'secondary');
        el.setAttribute('data-wait', safeClass(banner.kind, ''));
        el.appendChild(element('div', 'fw-semibold', banner.title, 'data-banner-title'));
        appendFacts(el, banner.facts);
        appendPersonal(el, banner.personal);
        if (banner.warning) {
            el.appendChild(element('div', 'mt-2 fw-semibold', banner.warning, 'data-banner-warning'));
        }
        el.hidden = false;
    }

    function appendFacts(el, facts) {
        if (!Array.isArray(facts) || facts.length === 0) {
            return;
        }
        var list = element('ul', 'small mt-1 mb-0', '', 'data-banner-facts');
        facts.forEach(function (fact) {
            var item = document.createElement('li');
            item.appendChild(factNode(fact || {}));
            list.appendChild(item);
        });
        el.appendChild(list);
    }

    // The request (a link only with an address), another fact with an address, or plain text.
    function factNode(fact) {
        var marker = fact.request ? 'data-banner-request' : null;
        var href = safeHref(fact.href);
        if (href) {
            return link(fact.text, href, marker);
        }
        return marker ? element('span', null, fact.text, marker) : document.createTextNode(text(fact.text));
    }

    function appendPersonal(el, personal) {
        if (!personal || typeof personal !== 'object') {
            return;
        }
        var line = element('div', 'mt-2', '', null);
        line.appendChild(element('span', null, personal.text, 'data-banner-personal'));
        var action = personal.action;
        var href = action ? safeHref(action.href) : null;
        if (href) {
            var review = link(action.text, href, null);
            review.id = 'wj-review-link';
            review.className = 'btn btn-sm btn-primary ms-2';
            line.appendChild(document.createTextNode(' '));
            line.appendChild(review);
        }
        el.appendChild(line);
    }

    function updateRow(live, step) {
        if (!step || typeof step !== 'object' || !DIGITS.test(String(step.id))) {
            return;
        }
        var row = document.querySelector('#wj-steps-table tr[data-wjs-id="' + String(step.id) + '"]') || appendRow(step);
        if (!row) {
            return;
        }
        row.classList.toggle('table-active', step.is_current === true);
        updateStatusCell(row, step);
        updateJobCell(live, row, step);
        setCellText(row, 'data-wjs-started-cell', step.started_label);
        setCellText(row, 'data-wjs-duration-cell', step.duration_label);
        setCellText(row, 'data-wjs-finished-cell', step.finished_label);
    }

    // A step the run reached after the page loaded: its execution's row did not exist yet.
    function appendRow(step) {
        var tbody = document.querySelector('#wj-steps-table tbody');
        if (!tbody) {
            return null;
        }
        var hint = document.getElementById('wj-empty-hint');
        if (hint && hint.parentNode) {
            hint.parentNode.removeChild(hint);
        }
        var row = document.createElement('tr');
        row.setAttribute('data-wjs-id', String(step.id));
        row.appendChild(element('td', null, step.step_index || tbody.children.length + 1, null));
        row.appendChild(element('td', null, step.step_name || NO_VALUE, 'data-wjs-name-cell'));
        row.appendChild(element('td', null, step.step_type_label || NO_VALUE, 'data-wjs-type-cell'));
        var status = element('td', null, '', 'data-wjs-status-cell');
        status.appendChild(element('span', 'badge', '', null));
        row.appendChild(status);
        row.appendChild(element('td', null, NO_VALUE, 'data-wjs-job-cell'));
        row.appendChild(element('td', null, NO_VALUE, 'data-wjs-started-cell'));
        row.appendChild(element('td', null, NO_VALUE, 'data-wjs-duration-cell'));
        row.appendChild(element('td', null, NO_VALUE, 'data-wjs-finished-cell'));
        tbody.appendChild(row);
        return row;
    }

    function updateStatusCell(row, step) {
        var cell = row.querySelector('[data-wjs-status-cell]');
        if (!cell) {
            return;
        }
        var badge = cell.querySelector('.badge');
        if (!badge) {
            badge = element('span', 'badge', '', null);
            cell.insertBefore(badge, cell.firstChild);
        }
        setBadge(badge, pick(pick(step.state_label, step.status_label), step.status), pick(step.state_css, step.status_css));
        setNote(cell, 'data-wjs-error', 'small text-danger mt-1', step.error_message);
        setNote(cell, 'data-wjs-decision', 'small text-body-secondary mt-1', step.decision_note);
    }

    // The Job / Request cell: the step's job (not the placeholder of an approval step) and its approval request.
    function updateJobCell(live, row, step) {
        var cell = row.querySelector('[data-wjs-job-cell]');
        if (!cell) {
            return;
        }
        // A field the answer lacks keeps the link the cell shows.
        var job = step.job_id === undefined ? cell.querySelector('[data-wjs-job-link]') : jobLink(live, step);
        var request = step.approval === undefined ? cell.querySelector('[data-wjs-request-link]') : requestNode(step.approval);
        fillJobCell(cell, [job, request].filter(Boolean));
    }

    function jobLink(live, step) {
        var href = DIGITS.test(String(step.job_id)) ? safeHref(live.jobUrl.replace('JOBID', String(step.job_id))) : null;
        return href && step.show_job_link !== false ? link('#' + step.job_id, href, 'data-wjs-job-link') : null;
    }

    function fillJobCell(cell, nodes) {
        clear(cell);
        if (nodes.length === 0) {
            cell.textContent = NO_VALUE;
            return;
        }
        nodes.forEach(function (node, index) {
            if (index > 0) {
                cell.appendChild(document.createTextNode(SEPARATOR));
            }
            cell.appendChild(node);
        });
    }

    // A link only for a viewer who may open the request page (an address is given).
    function requestNode(approval) {
        if (!approval || typeof approval !== 'object') {
            return null;
        }
        var href = safeHref(approval.url);
        return href ? link(approval.label, href, 'data-wjs-request-link') : element('span', null, approval.label, 'data-wjs-request-link');
    }

    // A note under the badge; an empty value removes it, a missing one leaves it.
    function setNote(cell, marker, className, value) {
        if (value === undefined) {
            return;
        }
        var note = cell.querySelector('[' + marker + ']');
        if (value === null || value === '') {
            if (note) {
                cell.removeChild(note);
            }
            return;
        }
        if (!note) {
            note = element('div', className, '', marker);
            cell.appendChild(note);
        }
        note.textContent = text(value);
    }

    function setCellText(row, marker, value) {
        var cell = row.querySelector('[' + marker + ']');
        if (cell && value !== undefined) {
            cell.textContent = value === null || value === '' ? NO_VALUE : text(value);
        }
    }

    function setBadge(el, label, css) {
        if (!el) {
            return;
        }
        if (label !== undefined && label !== null) {
            el.textContent = text(label);
        }
        if (css !== undefined && css !== null) {
            el.className = 'badge text-bg-' + safeClass(css, 'secondary');
        }
    }

    function element(tag, className, content, marker) {
        var el = document.createElement(tag);
        if (className) {
            el.className = className;
        }
        if (marker) {
            el.setAttribute(marker, '');
        }
        el.textContent = text(content);
        return el;
    }

    function link(content, href, marker) {
        var el = element('a', null, content, marker);
        el.setAttribute('href', href);
        return el;
    }

    function clear(el) {
        while (el.firstChild) {
            el.removeChild(el.firstChild);
        }
    }

    function pick(value, fallback) {
        return value === undefined || value === null || value === '' ? fallback : value;
    }

    function text(value) {
        return value === undefined || value === null ? '' : String(value);
    }

    function safeClass(value, fallback) {
        return typeof value === 'string' && SAFE_CLASS.test(value) ? value : fallback;
    }

    function safeHref(value) {
        return typeof value === 'string' && SAFE_HREF.test(value) ? value : null;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
