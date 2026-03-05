<?php
if (!defined('ABSPATH')) exit;

function acme_role_label(string $role): string {
  return match ($role) {
    'child' => 'Master',
    'grandchild' => 'Sub-Login',
    default => $role,
  };
}

function acme_user_primary_role_label($user): string {
  if (!$user) return '';
  $roles = (array) $user->roles;
  if (in_array('child', $roles, true)) return 'Master';
  if (in_array('grandchild', $roles, true)) return 'Sub-Login';
  if (in_array('administrator', $roles, true)) return 'Admin';
  return $roles[0] ?? '';
}
