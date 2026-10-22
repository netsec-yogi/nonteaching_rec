/**
 * Below 576px, tables marked `table-cards` reflow into labelled cards (the
 * hostel DataTable behaviour). Each cell is labelled from its column header;
 * a single observer keeps labels current as views render and paginate.
 */
function label(root: ParentNode): void {
    root.querySelectorAll<HTMLTableElement>('table.table-cards').forEach((table) => {
        const headers = [...table.querySelectorAll('thead th')].map((th) => (th as HTMLElement).innerText.trim());
        table.querySelectorAll('tbody tr').forEach((tr) => {
            [...tr.children].forEach((td, i) => {
                const text = (td as HTMLElement).hasAttribute('colspan') ? '' : (headers[i] ?? '');
                if (td.getAttribute('data-label') !== text) td.setAttribute('data-label', text);
            });
        });
    });
}

export function installTableCards(): void {
    let queued = false;
    new MutationObserver(() => {
        if (queued) return;
        queued = true;
        requestAnimationFrame(() => { queued = false; label(document); });
    }).observe(document.body, { childList: true, subtree: true });
}
