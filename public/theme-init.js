// Applies the saved colour mode before first paint. A separate file because the production
// Content-Security-Policy allows scripts only from this origin (no inline scripts).
(function () {
    var pref = 'light';
    try { pref = localStorage.getItem('ntrec.theme') || 'light'; } catch (e) { /* storage unavailable */ }
    var dark = pref === 'dark' || (pref === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
})();
