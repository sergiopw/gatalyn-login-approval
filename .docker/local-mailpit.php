<?php

if (! defined('ABSPATH')) {
    exit;
}

add_action(
    'phpmailer_init',
    static function ($phpmailer) {
        $phpmailer->isSMTP();
        $phpmailer->Host = 'mailpit';
        $phpmailer->Port = 1025;
        $phpmailer->SMTPAuth = false;
        $phpmailer->SMTPAutoTLS = false;
    }
);
