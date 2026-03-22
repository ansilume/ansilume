from __future__ import (absolute_import, division, print_function)
__metaclass__ = type

DOCUMENTATION = '''
    callback: ansilume_callback
    type: notification
    short_description: Write per-task results to an NDJSON file for Ansilume
    description:
      - Writes one JSON line per task result to the file specified by
        ANSILUME_CALLBACK_FILE. The worker process reads this file after
        execution to populate the job_task table.
'''

import json
import os
import time

from ansible.plugins.callback import CallbackBase


class CallbackModule(CallbackBase):
    CALLBACK_VERSION = 2.0
    CALLBACK_TYPE = 'notification'
    CALLBACK_NAME = 'ansilume_callback'
    CALLBACK_NEEDS_ENABLED = False

    def __init__(self):
        super().__init__()
        self._task_starts = {}
        self._seq = 0
        self._fh = None
        path = os.environ.get('ANSILUME_CALLBACK_FILE', '')
        if path:
            try:
                self._fh = open(path, 'w', buffering=1)
            except Exception:
                pass

    def v2_playbook_on_task_start(self, task, is_conditional):
        self._task_starts[task._uuid] = time.time()

    def _write(self, data):
        if self._fh:
            try:
                self._fh.write(json.dumps(data, ensure_ascii=False) + '\n')
            except Exception:
                pass

    def _record(self, result, status):
        task  = result._task
        start = self._task_starts.get(task._uuid, time.time())
        raw   = result._result or {}
        self._write({
            'seq':         self._seq,
            'name':        task.get_name(),
            'action':      task.action,
            'host':        result._host.get_name(),
            'status':      status,
            'changed':     bool(raw.get('changed', False)),
            'duration_ms': int((time.time() - start) * 1000),
        })
        self._seq += 1

    def v2_runner_on_ok(self, result):
        status = 'changed' if result._result.get('changed') else 'ok'
        self._record(result, status)

    def v2_runner_on_failed(self, result, ignore_errors=False):
        self._record(result, 'failed')

    def v2_runner_on_skipped(self, result):
        self._record(result, 'skipped')

    def v2_runner_on_unreachable(self, result):
        self._record(result, 'unreachable')

    def __del__(self):
        if self._fh:
            try:
                self._fh.close()
            except Exception:
                pass
