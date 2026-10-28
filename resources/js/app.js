import './bootstrap';

// Print buttons (no inline handlers: the content security policy forbids them).
document.addEventListener('click', (event) => {
    if (event.target.closest('[data-print]')) {
        window.print();
    }
});
