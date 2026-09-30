(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-zcd-toggle]');

        if (toggle) {
            var row = document.getElementById(toggle.getAttribute('data-zcd-toggle'));

            if (row) {
                var hidden = row.classList.toggle('zcd-hidden');
                toggle.textContent = hidden ? toggle.dataset.zcdShow : toggle.dataset.zcdHide;
            }

            return;
        }

        var confirmer = event.target.closest('[data-zcd-confirm]');

        if (confirmer && !window.confirm(confirmer.getAttribute('data-zcd-confirm'))) {
            event.preventDefault();
        }
    });

    document.querySelectorAll('[data-zcd-toggle]').forEach(function (toggle) {
        toggle.dataset.zcdShow = toggle.textContent.trim();
        toggle.dataset.zcdHide = 'Hide output';
    });
}());
