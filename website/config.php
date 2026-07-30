<?php
/*
    Define the top menu structure
*/
$MENU = [
    [
        "title" => "Home",
        "menu" => "index",
        "file" => ""
    ],
    [
        "title" => "The Story",
        "menu" => "story",
        "file" => "STORY.md"
    ],
    [
        "title" => "Principles",
        "menu" => "principles",
        "file" => "PRINCIPLES.md"
    ],
    [
        "title" => "Progress",
        "menu" => "progress",
        "file" => "PROGRESS.md"
    ],
    [
        "title" => "Personas",
        "menu" => "personas",
        "file" => "PERSONAS.md"
    ],
    [
        "title" => "Examples",
        "menu" => "examples",
        "file" => "-"
    ],
    [
        "title" => "Dashboards",
        "menu" => "dashboards",
        "file" => "-"
    ],
    [
        "title" => "Downloads",
        "menu" => "downloads",
        "file" => "-"
    ],
    [
        "title" => "Future",
        "menu" => "future",
        "file" => "FUTURE-ACTIVITIES.md"
    ],
    [
        "title" => "Prevalences (EU)",
        "menu" => "eu",
        "file" => "SUMMARY-EU-MODULES.md"
    ],
    [
        "title" => "SNOMED Codes (EU)",
        "menu" => "eus",
        "file" => "SUMMARY-EU-SNOMED.md"
    ],
    [
        "title" => "Conditions for the EPS",
        "menu" => "epsca",
        "file" => "EPS-CONDITION-ADAPTATION.md"
    ],
    [
        "title" => "Policy",
        "menu" => "policy",
        "file" => "SYNDERAI-SYNTHETIC-DATA-POLICY.md"
    ],
    [
        "title" => "Credits+",
        "menu" => "credits",
        "file" => "CCC.md"
    ]
];
/*
    Navigation presentation structure.

    $MENU (above) drives ROUTING and supplies each item's title + URL.
    $NAV describes only how those items are ARRANGED in the top bar, so the
    12 menu entries collapse into a short, harmonised set of grouped
    dropdowns (Home + About + Data + Project) on desktop and an accordion
    drawer on mobile.

    Each $NAV entry is one of:
      ['type' => 'link',  'menu'  => '<menu-key from $MENU>']
      ['type' => 'group', 'label' => '<dropdown label>',
                          'items' => ['<menu-key>', '<menu-key>', ...]]

    To move an item between groups, edit it here only - $MENU is untouched.
    Every key listed here must also exist in $MENU. Any $MENU key NOT listed
    here is simply hidden from the bar (its page stays reachable by URL).
*/
$NAV = [
    ['type' => 'link',  'menu'  => 'index'],
    ['type' => 'group', 'label' => 'About',
                        'items' => ['story', 'principles', 'personas', 'credits']],
    ['type' => 'group', 'label' => 'Data',
                        'items' => ['examples', 'dashboards', 'downloads']],
    ['type' => 'group', 'label' => 'Project',
                        'items' => ['progress', 'future', 'eu', 'eus', 'epsca', 'policy']],
];

/*
    vi7eti integration – deep link
*/
$VI7ETIDEEPLINK = "https://vi7eti.net/index.php?";
$SELFURL = "https://synderai.net";