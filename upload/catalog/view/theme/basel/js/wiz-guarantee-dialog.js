(function () {
    'use strict';

    var dialog = document.getElementById('wiz-guarantee-dialog');
    if (!dialog || typeof dialog.showModal !== 'function') return;

    var opener;
    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.altKey || event.metaKey || event.shiftKey) return;
        var link = event.target.closest && event.target.closest('a[data-wiz-guarantee]');
        if (!link || dialog.open) return;
        dialog.showModal();
        event.preventDefault();
        opener = link;
    });

    dialog.addEventListener('click', function (event) {
        if (event.target !== dialog) return;
        var bounds = dialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX >= bounds.right || event.clientY < bounds.top || event.clientY >= bounds.bottom) {
            dialog.close();
        }
    });

    dialog.addEventListener('close', function () {
        if (dialog.open) return;
        if (opener && opener.isConnected) opener.focus({preventScroll: true});
        opener = null;
    });
}());
