{{--
    Inline FOUC Prevention & OS Auto-Detection Script
    Executes synchronously in the <head> before page render.
    If no theme cookie is present, it instantly resolves system preference.
--}}
<script>
    (function () {
        try {
            var cookieMatch = document.cookie.match(/(?:^|; )theme=([^;]*)/);
            var preference = cookieMatch ? decodeURIComponent(cookieMatch[1]) : 'system';
            var mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
            var isDark = preference === 'dark' || (preference === 'system' && mediaQuery.matches);
            var root = document.documentElement;

            if (isDark) {
                root.classList.add('dark');
                root.classList.remove('light');
            } else {
                root.classList.add('light');
                root.classList.remove('dark');
            }

            root.setAttribute('data-theme', preference);
            root.setAttribute('data-theme-resolved', isDark ? 'dark' : 'light');
            root.style.colorScheme = isDark ? 'dark' : 'light';
        } catch (e) {
            // Fail safely without blocking rendering
        }
    })();
</script>
