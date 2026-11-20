(() => {
    const root = document.querySelector('.readiness');
    if (!root) return;
    const setOpen = open => root.querySelectorAll('.rr-expandable').forEach(item => { item.open = open; });
    for (const [selector, callback] of [
        ['[data-rr-expand]', () => setOpen(true)],
        ['[data-rr-collapse]', () => setOpen(false)],
        ['[data-rr-print]', () => window.print()],
    ]) {
        const button = root.querySelector(selector);
        if (button) { button.hidden = false; button.addEventListener('click', callback); }
    }
    const revealAnchor = () => {
        let id;
        try { id = decodeURIComponent(location.hash.slice(1)); } catch { return; }
        const target = document.getElementById(id);
        if (!target || !root.contains(target)) return;
        if (target.tagName === 'DETAILS') target.open = true;
        let parent = target.parentElement;
        while (parent && parent !== root) {
            if (parent.tagName === 'DETAILS') parent.open = true;
            parent = parent.parentElement;
        }
        target.scrollIntoView({ block: 'start' });
    };
    window.addEventListener('hashchange', revealAnchor);
    revealAnchor();
    let beforePrint = [];
    window.addEventListener('beforeprint', () => {
        beforePrint = [...root.querySelectorAll('details')].map(item => [item, item.open]);
        beforePrint.forEach(([item]) => { item.open = true; });
    });
    window.addEventListener('afterprint', () => beforePrint.forEach(([item, open]) => { item.open = open; }));
})();
