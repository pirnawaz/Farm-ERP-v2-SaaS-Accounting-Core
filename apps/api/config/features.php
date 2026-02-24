<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Land parcel documents (upload/download)
    |--------------------------------------------------------------------------
    | When false, document routes are not registered and no storage is used.
    | Enable when S3 or local storage is configured.
    */
    'land_documents_enabled' => env('LAND_DOCUMENTS_ENABLED', false),

];
