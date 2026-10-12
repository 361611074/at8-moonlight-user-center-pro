/**
 * AT8 Moonlight User Center Pro — admin settings script.
 *
 * Powers the membership level editor: "Add level" clones a template row and
 * rewrites its placeholder field names, and the delete buttons remove rows
 * that have not been saved yet. Existing levels are always removed through
 * the "Delete" checkbox, so the form also works with JavaScript disabled.
 */
(function () {
    'use strict';

    var button = document.getElementById('at8-mlucp-add-level');
    var tbody = document.getElementById('at8-mlucp-levels-tbody');
    var template = document.getElementById('at8-mlucp-level-row-tpl');

    if (!button || !tbody || !template || !template.content) {
        return;
    }

    var counter = 0;

    button.addEventListener('click', function (event) {
        event.preventDefault();
        counter += 1;

        var newKey = '__new__' + counter + '__';
        var row = template.content.querySelector('tr').cloneNode(true);

        row.querySelectorAll('[data-name]').forEach(function (field) {
            var name = field.getAttribute('data-name').replace('__NEWKEY__', newKey);
            field.setAttribute('name', name);
            field.removeAttribute('data-name');
        });

        tbody.appendChild(row);
    });

    tbody.addEventListener('click', function (event) {
        var target = event.target;

        if (target && target.classList && target.classList.contains('at8-mlucp-del-level')) {
            event.preventDefault();
            var row = target.closest('tr');

            if (row && row.parentNode) {
                row.parentNode.removeChild(row);
            }
        }
    });
})();
