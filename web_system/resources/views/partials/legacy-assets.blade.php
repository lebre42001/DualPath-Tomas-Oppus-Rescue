@once
    @php
        // Mirror @vitejs/plugin-legacy 8.0.1's HTML loader for server-rendered Blade pages.
        // Build targets describe generated JavaScript, not a guarantee for every OS/browser.
        $legacyVite = app(\Illuminate\Foundation\Vite::class);
        $legacyBuildDirectory = $legacyBuildDirectory ?? 'build';
        $legacyManifestPath = public_path($legacyBuildDirectory.'/manifest.json');
        $legacyAssetUrls = [];
        $modernPolyfillUrl = null;
        $legacyNonce = $legacyVite->cspNonce();

        if (! $legacyVite->isRunningHot() && is_file($legacyManifestPath) && is_readable($legacyManifestPath)) {
            $legacyManifest = json_decode(file_get_contents($legacyManifestPath), true);
            $modernPolyfillFile = $legacyManifest['vite/legacy-polyfills']['file'] ?? null;
            if (is_string($modernPolyfillFile) && is_file(public_path($legacyBuildDirectory.'/'.$modernPolyfillFile))) {
                $modernPolyfillUrl = asset($legacyBuildDirectory.'/'.$modernPolyfillFile);
            }
            $legacyAssetEntries = [
                'polyfills' => 'vite/legacy-polyfills-legacy',
                'entry' => 'resources/js/app-legacy.js',
            ];

            foreach ($legacyAssetEntries as $legacyAssetName => $legacyAssetEntry) {
                $legacyAssetFile = $legacyManifest[$legacyAssetEntry]['file'] ?? null;

                if (! is_string($legacyAssetFile) || ! is_file(public_path($legacyBuildDirectory.'/'.$legacyAssetFile))) {
                    $legacyAssetUrls = [];
                    break;
                }

                $legacyAssetUrls[$legacyAssetName] = asset($legacyBuildDirectory.'/'.$legacyAssetFile);
            }
        }
    @endphp

    @if ($modernPolyfillUrl)
        <script type="module" crossorigin="anonymous" src="{{ $modernPolyfillUrl }}" @if ($legacyNonce !== null) nonce="{{ $legacyNonce }}" @endif></script>
    @endif

    @if (isset($legacyAssetUrls['polyfills'], $legacyAssetUrls['entry']))
        {{-- Keep the detector and fallback aligned with plugin-legacy's generated modern-chunk guard. --}}
        <script nomodule @if ($legacyNonce !== null) nonce="{{ $legacyNonce }}" @endif>
            !function(){var e=document,t=e.createElement("script");if(!("noModule"in t)&&"onbeforeload"in t){var n=!1;e.addEventListener("beforeload",(function(e){if(e.target===t)n=!0;else if(!e.target.hasAttribute("nomodule")||!n)return;e.preventDefault()}),!0),t.type="module",t.src=".",e.head.appendChild(t),t.remove()}}();
        </script>
        <script nomodule crossorigin="anonymous" id="vite-legacy-polyfill" src="{{ $legacyAssetUrls['polyfills'] }}" @if ($legacyNonce !== null) nonce="{{ $legacyNonce }}" @endif></script>
        <script nomodule crossorigin="anonymous" id="vite-legacy-entry" data-src="{{ $legacyAssetUrls['entry'] }}" @if ($legacyNonce !== null) nonce="{{ $legacyNonce }}" @endif>
            System.import(document.getElementById('vite-legacy-entry').getAttribute('data-src'));
        </script>
        <script type="module" @if ($legacyNonce !== null) nonce="{{ $legacyNonce }}" @endif>
            import 'data:text/javascript,if(!import.meta.resolve)throw Error("import.meta.resolve not supported")';
            import.meta.url;import('_').catch(()=>1);(async function*(){})().next();window.__vite_is_modern_browser=true;
        </script>
        <script type="module" @if ($legacyNonce !== null) nonce="{{ $legacyNonce }}" @endif>
            !function(){
                if(window.__vite_is_modern_browser)return;
                console.warn('vite: loading legacy chunks, syntax error above and the same error below should be ignored');
                var e=document.getElementById('vite-legacy-polyfill'),n=document.createElement('script');
                n.src=e.src;
                if(e.nonce)n.setAttribute('nonce',e.nonce);
                n.onload=function(){System.import(document.getElementById('vite-legacy-entry').getAttribute('data-src'))};
                document.body.appendChild(n);
            }();
        </script>
    @endif
@endonce
