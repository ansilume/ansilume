/**
 * Copy-to-clipboard helper with HTTP fallback.
 *
 * navigator.clipboard is only available in secure contexts (HTTPS or
 * localhost). For plain HTTP deployments we fall back to the legacy
 * document.execCommand('copy') API.
 *
 * Usage:
 *   copyToClipboard('some text').then(function () { ... }).catch(function () { ... });
 */
(function () {
    'use strict';

    function fallbackCopy(text) {
        return new Promise(function (resolve, reject) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.top = '0';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.focus();
            ta.select();
            var ok = false;
            try {
                ok = document.execCommand('copy');
            } catch (e) {
                ok = false;
            }
            document.body.removeChild(ta);
            if (ok) {
                resolve();
            } else {
                reject(new Error('execCommand copy failed'));
            }
        });
    }

    window.copyToClipboard = function (text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).catch(function () {
                return fallbackCopy(text);
            });
        }
        return fallbackCopy(text);
    };
})();
