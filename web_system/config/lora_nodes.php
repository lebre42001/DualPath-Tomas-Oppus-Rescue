<?php

/*
| Static field-node registry.
| Add each sender ID and set its installed coordinates here. These values are
| the defaults for LoRa report locations, confirmed by the operator on 2026-10-29.
| Locations saved in Admin > Overview >
| Node locations override them; coordinates from radio payloads are ignored.
*/
return [
    'NODE_1' => [
        'node_name' => 'Gateway Node 1',
        'location_label' => 'MDRRMO office',
        'latitude' => 10.304469,
        'longitude' => 124.979829,
    ],
    'NODE_2' => [
        'node_name' => 'Field Node 2',
        'location_label' => 'Node 2 location',
        'latitude' => 10.3042205,
        'longitude' => 124.9805496,
    ],
    'NODE_3' => [
        'node_name' => 'Field Node 3',
        'location_label' => 'Node 3 location',
        'latitude' => 10.3035340,
        'longitude' => 124.9813019,
    ],
    'NODE_4' => [
        'node_name' => 'Field Node 4',
        'location_label' => 'Node 4 location',
        'latitude' => 10.3028159,
        'longitude' => 124.9820385,
    ],
    'NODE_5' => [
        'node_name' => 'Field Node 5',
        'location_label' => 'Cumajig',
        'latitude' => 10.3025949,
        'longitude' => 124.9823205,
    ],
    'NODE_6' => [
        'node_name' => 'Field Node 6',
        'location_label' => 'Node 6 location',
        'latitude' => 10.3002690,
        'longitude' => 124.9818665,
    ],
    'NODE_7' => [
        'node_name' => 'Field Node 7',
        'location_label' => 'Node 7 location',
        'latitude' => 10.2990587,
        'longitude' => 124.9816322,
    ],
    'NODE_8' => [
        'node_name' => 'Field Node 8',
        'location_label' => 'Node 8 location',
        'latitude' => 10.2971533,
        'longitude' => 124.9814451,
    ],
    'NODE_9' => [
        'node_name' => 'Field Node 9',
        'location_label' => 'Node 9 location',
        'latitude' => 10.2960984,
        'longitude' => 124.9801040,
    ],
    'NODE_10' => [
        'node_name' => 'Field Node 10',
        'location_label' => 'Node 10 location',
        'latitude' => 10.2932380,
        'longitude' => 124.9777614,
    ],
];
