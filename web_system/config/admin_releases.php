<?php

return [
    [
        'id' => '2026.11.07.4', 'date' => '2026-11-07', 'title' => 'LoRa response recovery and reliable report queues',
        'changes' => [
            'Missed field-node responses can be recovered from the existing report without creating a duplicate incident.',
            'Matching firmware keeps older report responses, saves replies before clearing pending reports, and notifies again for later admin updates.',
            'Gateway scheduling and acknowledgment retries keep other reports moving. Existing LED colors, pins and status rules are preserved.',
            'Upload the matching updated NODE_1 and NODE_2–NODE_10 sketches after this server update. Updating the website alone does not update the boards.',
        ],
    ],
    [
        'id' => '2026.11.07.3', 'date' => '2026-11-07', 'title' => 'Floating report notifications and admin updates',
        'changes' => [
            'A draggable Notifications badge shows unseen incoming reports and unread app updates. Open Reports to review the reports that triggered the alarm.',
            'The Updates tab shows release notes, the running and latest builds, Check again and Update app. Applying an update is manual so an open review is not unexpectedly reloaded.',
            'Update detection, requests, version openings, notification actions and existing report decisions are recorded in System Log under the signed-in officer.',
            'The ESP32 firmware removes the 60-second submission cooldown and queues separate reports while earlier reports await review. Upload the matching new firmware to the nodes to apply this device change.',
        ],
    ],
    [
        'id' => '2026.11.07.2', 'date' => '2026-11-07', 'title' => 'Consistent report review layout',
        'changes' => ['Online Report Review and Live Monitoring use the original stacked LoRa review style. Online photo, selfie and GPS controls remain available.'],
    ],
    [
        'id' => '2026.11.07.1', 'date' => '2026-11-07', 'title' => 'Officer activity and report alarm',
        'changes' => ['Admin System Log includes officer duty, account and report actions, date filters and Print / Save as PDF.', 'Report alarm preferences persist across navigation and use the short alarm recording.'],
    ],
];
