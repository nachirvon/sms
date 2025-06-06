<?php
/**
 * Plugin Name: Minimal OTP Login (ippanel)
 * Description: Replaces WooCommerce login/registration with a secure OTP system using ippanel.
 * Version: 2.0 (Stable & Final)
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 */

if (!defined('ABSPATH')) {
    exit;
}

class Minimal_OTP_Login {

    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'load_assets']);
        add_filter('wc_get_template', [$this, 'override_wc_login_form'], 10, 3);
        $this->register_ajax_handlers();
    }

    public function load_assets() {
        if (is_account_page()) {
            wp_enqueue_style('minimal-otp-style', plugin_dir_url(__FILE__) . 'assets/css/style.css', [], '2.0');
            wp_enqueue_script('minimal-otp-script', plugin_dir_url(__FILE__) . 'assets/js/main.js', ['jquery'], '2.0', true);
            wp_localize_script('minimal-otp-script', 'otp_object', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('otp_nonce_secret')
            ]);
        }
    }

    public function override_wc_login_form($template, $template_name, $template_path) {
        if ($template_name === 'myaccount/form-login.php') {
            $template = plugin_dir_path(__FILE__) . 'templates/my-account/form-login.php';
        }
        return $template;
    }

    private function register_ajax_handlers() {
        add_action('wp_ajax_nopriv_send_otp', [$this, 'ajax_send_otp']);
        add_action('wp_ajax_nopriv_verify_otp', [$this, 'ajax_verify_otp']);
    }

    public function ajax_send_otp() {
        check_ajax_referer('otp_nonce_secret', 'nonce');
        
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        if (!preg_match('/^09[0-9]{9}$/', $phone)) {
            wp_send_json_error(['message' => 'فرمت شماره موبایل صحیح نیست.']);
        }
        
        if (get_transient('otp_rate_limit_' . $phone)) {
            wp_send_json_error(['message' => 'لطفاً ۶۰ ثانیه دیگر دوباره تلاش کنید.']);
        }

        $otp = rand(10000, 99999);
        $otp_hash = password_hash($otp, PASSWORD_DEFAULT);
        
        $api_response = $this->send_ippanel_pattern($phone, $otp);

        if (is_wp_error($api_response) || (wp_remote_retrieve_response_code($api_response) < 200 || wp_remote_retrieve_response_code($api_response) >= 300)) {
            // برای مدیر سایت خطا را لاگ می‌گیریم اما به کاربر پیام عمومی نمایش می‌دهیم
            error_log('IPPANEL API Error: ' . print_r($api_response, true));
            wp_send_json_error(['message' => 'خطا در سیستم ارسال پیامک. لطفاً بعداً تلاش کنید.']);
        }
        
        set_transient('otp_hash_' . $phone, $otp_hash, 5 * MINUTE_IN_SECONDS);
        set_transient('otp_rate_limit_' . $phone, true, 60);

        $user_exists = $this->user_exists($phone);
        
        wp_send_json_success([
            'message' => 'کد تایید با موفقیت ارسال شد.', 
            'user_exists' => $user_exists
        ]);
    }
    
    private function send_ippanel_pattern($recipient, $code) {
        $api_key = defined('IPPANEL_API_KEY') ? IPPANEL_API_KEY : '';
        $pattern_code = defined('IPPANEL_PATTERN_CODE') ? IPPANEL_PATTERN_CODE : '';
        $sender = defined('IPPANEL_SENDER_NUMBER') ? IPPANEL_SENDER_NUMBER : '';

        $url = 'https://api2.ippanel.com/api/v1/sms/pattern/normal/send';
        $body = [
            'code'      => $pattern_code,
            'sender'    => $sender,
            'recipient' => $recipient,
            'variable'  => [
                'code' => (string) $code, // Fix 1
            ],
        ];

        return wp_remote_post($url, [
            'method'  => 'POST',
            'headers' => [
                'Content-Type'  => 'application/json',
                'apikey'        => $api_key, // Fix 2: The Final Fix!
            ],
            'body'    => json_encode($body),
            'timeout' => 20,
        ]);
    }
    
    public function ajax_verify_otp() {
        check_ajax_referer('otp_nonce_secret', 'nonce');
        
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $otp   = isset($_POST['otp']) ? sanitize_text_field($_POST['otp']) : '';
        $fname = isset($_POST['fname']) ? sanitize_text_field($_POST['fname']) : '';
        $lname = isset($_POST['lname']) ? sanitize_text_field($_POST['lname']) : '';

        $otp_hash = get_transient('otp_hash_' . $phone);
        if (!$otp_hash || !password_verify($otp, $otp_hash)) {
            wp_send_json_error(['message' => 'کد تایید نامعتبر است یا منقضی شده.']);
        }
        
        delete_transient('otp_hash_' . $phone);

        $user_id = $this->user_exists($phone);
        if (!$user_id) {
            if (empty($fname) || empty($lname)) {
                wp_send_json_error(['message' => 'نام و نام خانوادگی الزامی است.']);
            }
            $username = $phone;
            $password = wp_generate_password();
            $user_id = wp_create_user($username, $password);
            
            if (is_wp_error($user_id)) {
                wp_send_json_error(['message' => 'خطا در ایجاد حساب کاربری: ' . $user_id->get_error_message()]);
            }
            
            wp_update_user(['ID' => $user_id, 'first_name' => $fname, 'last_name' => $lname]);
            update_user_meta($user_id, 'billing_phone', $phone);
        }
        
        wp_clear_auth_cookie();
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);
        
        wp_send_json_success([
            'message' => 'ورود با موفقیت انجام شد.',
            'redirect' => wc_get_page_permalink('myaccount')
        ]);
    }
    
    private function user_exists($phone) {
        if ($user = get_user_by('login', $phone)) {
            return $user->ID;
        }

        $user_query = new WP_User_Query(['meta_key' => 'billing_phone', 'meta_value' => $phone, 'number' => 1, 'fields' => 'ID']);
        $users = $user_query->get_results();
        
        return !empty($users) ? $users[0] : false;
    }
}

new Minimal_OTP_Login();