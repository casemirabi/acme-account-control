<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('acme_api_public_is_enabled')) {
    function acme_api_public_is_enabled(): bool
    {
        $optionValue = get_option('acme_public_api_enabled', 'yes');
        return $optionValue === 'yes';
    }
}

if (!function_exists('acme_api_public_set_enabled')) {
    function acme_api_public_set_enabled(bool $enabled): bool
    {
        return update_option('acme_public_api_enabled', $enabled ? 'yes' : 'no', false);
    }
}