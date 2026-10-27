@php
    $connectionRoutes = [
        [
            'id' => 'online', 'label' => 'With internet', 'title' => 'Online reporting',
            'copy' => 'Use the AksyRes app or website after verifying your account.',
            'steps' => [
                ['title' => 'Send a report', 'copy' => 'Add a photo, GPS, landmark and description, then pass the live blink check.', 'icon' => 'M8 3h8v18H8zM11 18h2'],
                ['title' => 'Saved securely', 'copy' => 'The server saves your report and gives you a reference code.', 'icon' => 'M4 4h16v6H4zM4 14h16v6H4zM7 7h1M7 17h1'],
                ['title' => 'Admin reviews', 'copy' => 'AI assists severity review. An admin confirms the assessment.', 'icon' => 'M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6zM8 12l3 3 5-6'],
                ['title' => 'Follow updates', 'copy' => 'Check the response and report status in your account.', 'icon' => 'M4 5h16v12H8l-4 4zM8 9h8M8 13h5'],
            ],
        ],
        [
            'id' => 'mesh', 'label' => 'Local Wi-Fi + LoRa', 'title' => 'Through a nearby node',
            'copy' => 'No mobile data? Use an installed node’s local dashboard.',
            'steps' => [
                ['title' => 'Join node Wi-Fi', 'copy' => 'Open its local dashboard and enter your emergency message.', 'icon' => 'M3 9a15 15 0 0 1 18 0M6 12a10 10 0 0 1 12 0M9 15a5 5 0 0 1 6 0M12 19h.01'],
                ['title' => 'Nodes relay', 'copy' => 'The emergency travels through available LoRa links.', 'icon' => 'M3 3h5v5H3zM16 3h5v5h-5zM9 16h6v5H9zM8 6h8M6 8l6 8 6-8'],
                ['title' => 'Gateway forwards', 'copy' => 'NODE_1 sends it to the admin server when its link is available.', 'icon' => 'M12 3v18M7 21l5-10 5 10M5 5a10 10 0 0 0 0 12M19 5a10 10 0 0 1 0 12M8 8a6 6 0 0 0 0 6M16 8a6 6 0 0 1 0 6'],
                ['title' => 'Reply returns', 'copy' => 'The admin’s response returns to the originating node dashboard.', 'icon' => 'M9 5 3 11l6 6M3 11h10a7 7 0 0 1 7 7v3'],
            ],
        ],
    ];
@endphp
<section class="system-flow" aria-labelledby="system-flow-title">
    <header class="system-flow-heading">
        <p class="system-flow-eyebrow">Two ways to reach the response team</p>
        <h2 id="system-flow-title">How AksyRes connects you</h2>
        <p>From your emergency report to admin review and response updates.</p>
    </header>
    @foreach ($connectionRoutes as $connectionRoute)
        <section class="system-flow-route system-flow-route--{{ $connectionRoute['id'] }}" aria-labelledby="flow-{{ $connectionRoute['id'] }}-title">
            <div class="system-flow-label">
                <span>{{ $connectionRoute['label'] }}</span>
                <h3 id="flow-{{ $connectionRoute['id'] }}-title">{{ $connectionRoute['title'] }}</h3>
                <p>{{ $connectionRoute['copy'] }}</p>
            </div>
            <ol class="system-flow-steps">
                @foreach ($connectionRoute['steps'] as $connectionStep)
                    <li class="system-flow-step">
                        <span class="system-flow-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $connectionStep['icon'] }}"/></svg></span>
                        <div><h4><span class="system-flow-number">{{ $loop->iteration }}.</span> {{ $connectionStep['title'] }}</h4><p>{{ $connectionStep['copy'] }}</p></div>
                    </li>
                @endforeach
            </ol>
        </section>
    @endforeach
    <p class="system-flow-note"><strong>When the gateway link is unavailable:</strong> the node keeps the pending report and retries when the route returns. Stay connected to that node’s Wi-Fi to see its reply.</p>
</section>
