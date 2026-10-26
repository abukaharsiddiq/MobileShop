{{--
  Light/dark theme: <head> e (CSS er age) - page ake howar agei theme bose, tai shada jhilik dekha jay na.
  Bachai localStorage 'theme' e (ei browser); na thakle computer/phone er setting (prefers-color-scheme).
  [data-theme-toggle] button e click korle bodlay.
--}}
<script>
(function () {
    var KEY = 'theme', root = document.documentElement;
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function saved() { try { return localStorage.getItem(KEY); } catch (e) { return null; } }
    function system() { return media && media.matches ? 'dark' : 'light'; }
    function apply(theme) {
        root.setAttribute('data-theme', theme);
        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            var icon = button.querySelector('i');
            if (icon) icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
            button.setAttribute('title', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
            button.setAttribute('aria-label', button.getAttribute('title'));
        });
    }

    apply(saved() || system());
    document.addEventListener('DOMContentLoaded', function () { apply(root.getAttribute('data-theme')); });

    document.addEventListener('click', function (event) {
        var button = event.target.closest && event.target.closest('[data-theme-toggle]');
        if (!button) return;
        event.preventDefault();
        var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        root.classList.add('theme-anim');
        apply(next);
        try { localStorage.setItem(KEY, next); } catch (e) {}
        setTimeout(function () { root.classList.remove('theme-anim'); }, 350);
    });

    // nijer bachai na thakle computer er setting bodlale sathe sathe
    if (media && media.addEventListener) {
        media.addEventListener('change', function (event) { if (!saved()) apply(event.matches ? 'dark' : 'light'); });
    }
})();
</script>
