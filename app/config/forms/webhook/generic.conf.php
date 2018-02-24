<?php
/** @var \App\Url $url */

return [
    'method' => 'post',

    'groups' => [

        'api_info' => [
            'legend' => _('Web Hook Details'),
            'description' => sprintf(_('Web hooks automatically send a HTTP POST request to the URL you specify to 
                notify it any time one of the triggers you specify occurs on your station. The body of the POST message
                is the exact same as the <a href="%s" target="_blank">Now Playing API response</a> for your station. 
                In order to process quickly, web hooks have a short timeout, so the responding service should be
                optimized to handle the request in under 2 seconds.'),
                $url->named('api:nowplaying:index')),

            'elements' => [

                'webhook_url' => [
                    'text',
                    [
                        'label' => _('Web Hook URL'),
                        'description' => _('The URL that will receive the POST messages any time an event is triggered.'),
                        'belongsTo' => 'config',
                        'required' => true,
                    ]
                ],

                'triggers' => [
                    'multiCheckbox',
                    [
                        'label' => _('Web Hook Triggers'),
                        'options' => \AzuraCast\Webhook\Dispatcher::getTriggers(),
                        'required' => true,
                    ]
                ],

            ],
        ],

        'submit_grp' => [
            'elements' => [

                'submit' => [
                    'submit',
                    [
                        'type' => 'submit',
                        'label' => _('Save Changes'),
                        'class' => 'ui-button btn-lg btn-primary',
                    ]
                ],

            ]
        ]
    ],
];