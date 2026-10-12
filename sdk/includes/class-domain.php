<?php
/**
 * 客户端域名标准化 + 环境判定（与服务器 AT8LIC_Domain 规则一致）。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('AT8SDK_Domain')) {

class AT8SDK_Domain
{
    public static function canonicalize($input)
    {
        $input = strtolower(trim((string) $input));
        if ($input === '') {
            return '';
        }

        if (preg_match('#^[a-z][a-z0-9+.\-]*://#', $input)) {
            $host = wp_parse_url($input, PHP_URL_HOST);
        } else {
            $host = wp_parse_url('http://' . $input, PHP_URL_HOST);
        }

        if (!is_string($host) || $host === '') {
            return '';
        }

        // IPv6 字面量：parse_url 保留方括号（[::1]），剥掉后再归一化
        if ($host[0] === '[' && substr($host, -1) === ']') {
            $host = substr($host, 1, -1);
        }

        if (function_exists('idn_to_ascii')) {
            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($host === false || $host === null) {
                return '';
            }
        }

        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }

        return $host;
    }

    public static function current_domain()
    {
        return self::canonicalize(home_url());
    }

    /** 本站是否开发环境（SDK 本地不拦截，仅用于展示提示） */
    public static function is_dev()
    {
        $d = self::current_domain();
        if ($d === 'localhost' || $d === '127.0.0.1' || $d === '::1') {
            return true;
        }
        if (preg_match('/\.(local|test|dev|example|invalid)$/', $d)) {
            return true;
        }
        if (filter_var($d, FILTER_VALIDATE_IP)) {
            return true;
        }
        return false;
    }
}

} // class_exists guard
