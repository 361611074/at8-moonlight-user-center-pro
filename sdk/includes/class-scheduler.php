<?php
/**
 * 每日 WP-Cron 复查调度（页面加载零 HTTP，规划书 §23/§24）。
 * 每个产品的 hook 独立：at8sdk_verify_{product}；多产品并存互不干扰。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('AT8SDK_Scheduler')) {

    class AT8SDK_Scheduler
    {
        public static function init($config)
        {
            $product = $config->product;

            add_action('at8sdk_verify_' . sanitize_key($product), function () use ($product) {
                $license = AT8_License_SDK::license($product);
                if ($license && $license->get_key() !== '') {
                    $license->verify();
                }
            });

            add_action('admin_init', function () use ($config) {
                $license = AT8_License_SDK::license($config->product);
                if ($license && $license->get_key() !== '') {
                    self::schedule($config);
                }
            });
        }

        public static function schedule($config)
        {
            $hook = 'at8sdk_verify_' . sanitize_key($config->product);
            if (!wp_next_scheduled($hook)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', $hook);
            }
        }

        public static function unschedule($config)
        {
            $hook = 'at8sdk_verify_' . sanitize_key($config->product);
            $ts = wp_next_scheduled($hook);
            while ($ts) {
                wp_unschedule_event($ts, $hook);
                $ts = wp_next_scheduled($hook);
            }
        }
    }
}
