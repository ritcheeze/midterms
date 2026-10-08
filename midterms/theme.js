/* Applies the saved theme before first paint, then wires up the toggle button. */
(function () {
    var KEY = 'lspu-theme';
    var root = document.documentElement;

    function saved() {
        try { return localStorage.getItem(KEY); } catch (e) { return null; }
    }

    var theme = saved() || (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    root.setAttribute('data-theme', theme);

    document.addEventListener('DOMContentLoaded', function () {
        var btn = document.getElementById('themeToggle');
        if (!btn) return;

        function sync() {
            var dark = root.getAttribute('data-theme') === 'dark';
            btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
            btn.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
            btn.title = dark ? 'Switch to light mode' : 'Switch to dark mode';
        }
        sync();

        btn.addEventListener('click', function () {
            var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', next);
            try { localStorage.setItem(KEY, next); } catch (e) {}
            sync();
        });
    });
})();
