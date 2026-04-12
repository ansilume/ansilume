/**
 * Lightweight client-side table filter.
 *
 * Usage: add data-table-filter="<tableId>" to an input element.
 * All <tbody> rows in the target table are matched against the input value.
 */
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-table-filter]').forEach(function (input) {
            var tableId = input.getAttribute('data-table-filter');
            var table = document.getElementById(tableId);
            if (!table) return;

            input.addEventListener('input', function () {
                var q = this.value.toLowerCase().trim();
                var rows = table.querySelectorAll('tbody tr');
                rows.forEach(function (row) {
                    var text = row.textContent.toLowerCase();
                    row.style.display = !q || text.indexOf(q) !== -1 ? '' : 'none';
                });
            });
        });
    });
})();
