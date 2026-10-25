/**
 * Scrolling panes (`table-responsive`, `ws-scroll`) must be reachable from the keyboard so their
 * hidden part can be scrolled into view (WCAG 2.1.1). A pane gets tabindex="0" only while it
 * actually overflows, so desktop users don't tab through every table wrapper.
 */
const SELECTOR = '.table-responsive, .ws-scroll';

function update(root: ParentNode): void {
    root.querySelectorAll<HTMLElement>(SELECTOR).forEach((el) => {
        const overflows = el.scrollWidth > el.clientWidth + 1 || el.scrollHeight > el.clientHeight + 1;
        if (overflows && !el.hasAttribute('tabindex')) {
            // Remember which attributes we added so we only ever remove our own.
            const added = ['tabindex'];
            el.setAttribute('tabindex', '0');
            if (!el.hasAttribute('role')) { el.setAttribute('role', 'region'); added.push('role'); }
            if (!el.hasAttribute('aria-label')) {
                el.setAttribute('aria-label', el.querySelector('caption')?.textContent?.trim() || 'Scrollable content');
                added.push('aria-label');
            }
            el.dataset.scrollRegion = added.join(' ');
        } else if (!overflows && el.dataset.scrollRegion !== undefined) {
            el.dataset.scrollRegion.split(' ').forEach((a) => el.removeAttribute(a));
            delete el.dataset.scrollRegion;
        }
    });
}

export function installScrollRegions(): void {
    let queued = false;
    const schedule = () => {
        if (queued) return;
        queued = true;
        requestAnimationFrame(() => { queued = false; update(document); });
    };
    new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    window.addEventListener('resize', schedule);
}
