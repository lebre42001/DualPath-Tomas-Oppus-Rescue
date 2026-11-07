<?php

// Newest first. Add civilian-facing changes for each feature release.
// These release labels are separate from the app's runtime build fingerprint.
return [
    [
        'id' => '2026.11.07.12',
        'date' => '2026-11-07',
        'title' => 'Incident selection tutorial and notification sound',
        'changes' => [
            'Quick tutorial now explains choosing the incident type before the scene photo. Road Accident is available; other incident types remain locked.',
            'New admin responses, report changes and available app updates can play a short notification chime while the app is open and connected.',
            'Open Updates for Enable sound, Test sound and Mute sound. The browser may need a tap first; device volume and silent mode also affect playback.',
            'Old unread notices do not chime on page opening. Muting keeps the unread badge, report updates and permanent System Log records available.',
        ],
    ],
    [
        'id' => '2026.11.07.11',
        'date' => '2026-11-07',
        'title' => 'Clearer incident type selector',
        'changes' => [
            'Type of incident now appears as a bordered selection control with a larger Road Accident label, a visible dropdown arrow and a tap hint.',
            'The selected option and unavailable incident types are clearer on phones and desktop in light and dark mode. Keyboard users return to the selector after choosing.',
        ],
    ],
    [
        'id' => '2026.11.07.10',
        'date' => '2026-11-07',
        'title' => 'Device permissions and automatic report GPS',
        'changes' => [
            'Open Device permissions from Home to allow camera and location access, or choose a photo for a local access check. No test sends a report.',
            'When location permission is already granted, Report automatically gets fresh GPS on opening, returning or restoring a draft. Location is checked again before sending.',
            'If phone Location is off, unavailable or blocked, follow the on-screen instructions to enable it and retry Get GPS. The app cannot turn on the phone switch for you.',
            'Camera access is requested when you choose it; permission checks close the camera immediately. Your browser controls whether and how long grants are remembered.',
            'Photo uploads use the device picker and access only the file you choose. Account verification, a fresh live selfie, GPS and a written location are still required to submit.',
        ],
    ],
    [
        'id' => '2026.11.07.9',
        'date' => '2026-11-07',
        'title' => 'Notifications for admin report updates',
        'changes' => [
            'See an in-app notice when an admin acknowledges, responds to or updates your report. Server receipt and admin acknowledgement have separate messages.',
            'Open Updates to see the newest unread notices first, with the report code, Philippine date and time, and View report links.',
            'Dismiss a notice without marking it read. Unread counts remain, and marking an update as read keeps its permanent System Log record.',
            'Repeated saves without a changed status, response or assignment do not create extra alerts. Reconnect or reopen AksyRes to retrieve saved updates.',
            'Report status alerts in Settings control the in-app notice. The same notification flow works on phones and desktop; closed-app push delivery is not available.',
        ],
    ],
    [
        'id' => '2026.11.07.8',
        'date' => '2026-11-07',
        'title' => 'Human-reviewed report severity',
        'changes' => [
            'Reports show Severity under review until an authorized reviewer records a current severity assessment.',
            'After review, see Human-reviewed Severity and expand Assessment details for the original AI suggestion, confirmation or correction, reviewer, date and public explanation.',
            'Acknowledgement and response progress remain separate from severity review. AI results are not shown as confirmed assessments.',
            'The same assessment appears on Home, Report History, View All, full reports and submission receipts. Internal review notes and model scores stay private.',
        ],
    ],
    [
        'id' => '2026.11.07.7',
        'date' => '2026-11-07',
        'title' => 'Contact Admin for help',
        'changes' => [
            'Find Contact Admin on civilian Home and App Settings for account concerns, app issues or feedback.',
            'Tap Email Admin or aksyres900@gmail.com to open your email app addressed to the administrator.',
            'For emergencies, continue using Report Emergency.',
        ],
    ],
    [
        'id' => '2026.11.07.6',
        'date' => '2026-11-07',
        'title' => 'Step-by-step civilian tutorial',
        'changes' => [
            'New unverified sign-ups and logins open Home so you can learn the app before verifying.',
            'A guided tour points to tabs and explains photo, GPS, description, live check and server receipt using a safe report preview.',
            'Use Next, Back, Skip or Done. Replay Quick tutorial from Home or Settings.',
            'The tutorial never sends a report or asks for camera/location access. Account verification is still required before real reporting.',
        ],
    ],
    [
        'id' => '2026.11.07.5',
        'date' => '2026-11-07',
        'title' => 'Clearer report buttons',
        'changes' => [
            'Report buttons now say View report or View draft so you know what will open.',
            'Larger, bold labels and stronger text contrast make these buttons easier to read in light and dark mode.',
            'Button spacing keeps the full label readable on phones, laptops and desktops.',
        ],
    ],
    [
        'id' => '2026.11.07.4',
        'date' => '2026-11-07',
        'title' => 'Readable update details',
        'changes' => [
            'Open the floating Updates badge to read what changed before choosing Update app.',
            'See the release date and a clear list of added or improved features.',
            'Read earlier update notes even after marking notifications as read.',
        ],
    ],
    [
        'id' => '2026.11.07.3',
        'date' => '2026-11-07',
        'title' => 'Organized report lists',
        'changes' => [
            'Draft Reports, Report History and Report Status have a View All table with search, date and status filters.',
            'Browse ten reports per page using Previous, Next or the numbered page buttons.',
            'View a specific report, then go Back to the same list, filters and page.',
            'Main pages show shorter previews. Your account drafts can still be viewed while offline on this device.',
        ],
    ],
    [
        'id' => '2026.11.07.2',
        'date' => '2026-11-07',
        'title' => 'Move the Updates badge',
        'changes' => [
            'Drag the Updates badge with your finger or mouse. Tap or click to open it.',
            'Your chosen position is remembered for this account in this browser and adjusts to the screen size.',
            'Use the same civilian Updates, Draft Reports and System Log features on phone, laptop and desktop.',
        ],
    ],
    [
        'id' => '2026.11.07.1',
        'date' => '2026-11-07',
        'title' => 'Draft recovery and account activity',
        'changes' => [
            'Unfinished report details and scene photos are saved as account drafts on this device when storage is available.',
            'Draft Reports has its own navigation entry and saved-draft count.',
            'System Log shows recorded report/admin actions, sign-ins, sign-outs and app activity with search, date filters and Copy text.',
            'The floating Updates panel shows unread notices, Check again, app builds and Update app. An app update saves unfinished report work before reloading.',
        ],
    ],
];
