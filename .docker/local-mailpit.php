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

// WordPress builds the default sender from the site host ("wordpress@localhost"),
// which PHPMailer rejects as invalid, so no mail would ever reach Mailpit.
add_filter(
    'wp_mail_from',
    static function () {
        return 'wordpress@example.com';
    }
);
