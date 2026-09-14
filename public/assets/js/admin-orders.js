(() => {
    const dialogs = new Map();

    document.querySelectorAll('[data-order-cancel-dialog]').forEach((dialog) => {
        dialogs.set(dialog.dataset.orderCancelDialog, dialog);

        dialog.querySelectorAll('[data-order-cancel-close]').forEach((button) => {
            button.addEventListener('click', () => dialog.close());
        });

        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                const bounds = dialog.getBoundingClientRect();
                const inside = event.clientX >= bounds.left && event.clientX <= bounds.right
                    && event.clientY >= bounds.top && event.clientY <= bounds.bottom;
                if (!inside) dialog.close();
            }
        });
    });

    document.querySelectorAll('[data-order-cancel-open]').forEach((button) => {
        button.addEventListener('click', () => {
            const dialog = dialogs.get(button.dataset.orderCancelOpen);
            if (!dialog) return;
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', 'open');
            }
        });
    });
})();
