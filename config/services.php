<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'api_sports' => [
        'key' => env('RUGBY_API_SPORTS_KEY'),
        'base_url' => 'https://v1.rugby.api-sports.io',
        'daily_limit' => env('RUGBY_API_SPORTS_DAILY_LIMIT', 100),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    ],

];
