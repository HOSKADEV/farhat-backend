<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Key
    |--------------------------------------------------------------------------
    |
    | This key is used by the EnsureApiKeyIsValid middleware to protect every
    | incoming request. Requests must provide a matching X-Api-Key header.
    |
    */

    'key' => env('API_KEY'),

];
