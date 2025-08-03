<?php
require_once __DIR__ . '/vendor/autoload.php';
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

if (!function_exists('wp_push_notify_send_webpush')) {
    function wp_push_notify_send_webpush($sub, $title, $body, $url = '') {
        $vapid = [
            'subject' => get_option('siteurl'),
            'publicKey' => defined('WP_PUSH_NOTIFY_VAPID_PUBLIC') ? WP_PUSH_NOTIFY_VAPID_PUBLIC : get_option('wp_push_notify_vapid_public', ''),
            'privateKey' => defined('WP_PUSH_NOTIFY_VAPID_PRIVATE') ? WP_PUSH_NOTIFY_VAPID_PRIVATE : get_option('wp_push_notify_vapid_private', ''),
        ];
        if (empty($vapid['publicKey']) || empty($vapid['privateKey'])) {
            return false;
        }
        $subscription = Subscription::create([
            'endpoint' => $sub->endpoint,
            'publicKey' => $sub->p256dh,
            'authToken' => $sub->auth,
            'contentEncoding' => 'aesgcm',
        ]);
        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'icon' => '',
            'url' => $url
        ]);
        try {
            $webPush = new WebPush([
                'VAPID' => $vapid
            ]);
            $report = $webPush->sendOneNotification($subscription, $payload);
            return $report && $report->isSuccess();
        } catch (\Exception $e) {
            error_log('WP Push Notify WebPush error: ' . $e->getMessage());
            return false;
        }
    }
}