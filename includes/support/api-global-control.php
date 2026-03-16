<?php

if (!defined('ABSPATH')) {
    exit;
}

function acme_api_public_is_enabled()
{
    $value = get_option('acme_public_api_enabled', 'yes');
    return $value === 'yes';
}

function acme_api_public_set_enabled($enabled)
{
    update_option(
        'acme_public_api_enabled',
        $enabled ? 'yes' : 'no',
        false
    );
}