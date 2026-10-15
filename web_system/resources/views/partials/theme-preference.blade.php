{{-- Apply the saved/device theme before styles load; this initializer must remain ES5. --}}
<script>
    (function () {
        var root = document.documentElement;
        var storageKey = 'stitch-theme';
        var preference = 'system';
        var storage = null;
        var storageAvailable = false;
        var media = null;
        var listeners = [];

        function normalize(value) {
            return value === 'light' || value === 'dark' ? value : 'system';
        }

        try {
            storage = window.localStorage;
            preference = normalize(storage.getItem(storageKey));
            storageAvailable = true;
        } catch (error) {
            // Device appearance still works when this browser blocks storage.
        }

        try {
            media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
        } catch (error) {
            // Browsers without a color preference use light for the system option.
        }

        function getState() {
            return {
                preference: preference,
                theme: preference === 'system' ? (media && media.matches ? 'dark' : 'light') : preference,
                storageAvailable: storageAvailable
            };
        }

        function apply() {
            var state = getState();
            root.setAttribute('data-theme', state.theme);
            root.setAttribute('data-theme-preference', state.preference);
            if (state.theme === 'dark') {
                root.classList.add('dark');
            } else {
                root.classList.remove('dark');
            }
            root.style.colorScheme = state.theme;
            var themeColor = document.querySelector('meta[name="theme-color"]');
            if (themeColor) {
                themeColor.setAttribute('content', state.theme === 'dark' ? '#0b1723' : '#c91c21');
            }
            listeners.slice().forEach(function (listener) { listener(state); });
            return state;
        }

        window.StitchTheme = {
            getState: getState,
            setPreference: function (value) {
                preference = normalize(value);
                try {
                    if (!storage) storage = window.localStorage;
                    storage.setItem(storageKey, preference);
                    storageAvailable = true;
                } catch (error) {
                    // Keep this page usable even if the preference cannot persist.
                    storageAvailable = false;
                }
                return apply();
            },
            subscribe: function (listener) {
                if (typeof listener !== 'function') return function () {};
                listeners.push(listener);
                listener(getState());
                return function () {
                    var index = listeners.indexOf(listener);
                    if (index !== -1) listeners.splice(index, 1);
                };
            }
        };

        function onDeviceChange() {
            if (preference === 'system') apply();
        }
        if (media) {
            if (media.addEventListener) {
                media.addEventListener('change', onDeviceChange);
            } else if (media.addListener) {
                media.addListener(onDeviceChange);
            }
        }

        window.addEventListener('storage', function (event) {
            if (event.key !== storageKey && event.key !== null) return;
            if (event.storageArea && storage && event.storageArea !== storage) return;
            preference = normalize(event.newValue);
            apply();
        });

        apply();
    })();
</script>
