<?php
/**
 * Custom Login Form for Minimal OTP Plugin
 *
 * This template is overriding the default WooCommerce 'myaccount/form-login.php'.
 *
 * @version 1.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Display any standard WooCommerce notices.
wc_print_notices(); 
?>

<div class="otp-login-form-container">
    <h2>ورود یا ثبت‌نام با شماره موبایل</h2>
    <p>برای ورود یا ثبت‌نام، شماره موبایل خود را وارد کرده و کد تایید را دریافت نمایید.</p>

    <form id="minimal-otp-form">

        <?php wp_nonce_field('otp_nonce_secret', 'otp_nonce_field'); ?>
        
        <p class="otp-status-message"></p>
        
        <div class="otp-step" id="otp-step-1">
            <label for="otp_phone_number">شماره موبایل</label>
            <input type="tel" class="input-text" name="phone" id="otp_phone_number" autocomplete="tel" placeholder="مثال: 09123456789" />
            <button type="button" id="send-otp-btn" class="button">ارسال کد تایید</button>
        </div>

        <div class="otp-step" id="otp-step-2" style="display: none;">
            <label for="otp_code">کد تایید</label>
            <input type="text" class="input-text" name="otp" id="otp_code" placeholder="کدی که پیامک شد را وارد کنید" />
            
            <div id="new-user-fields" style="display: none;">
                <label for="otp_fname">نام</label>
                <input type="text" class="input-text" name="fname" id="otp_fname" autocomplete="given-name" />
                
                <label for="otp_lname">نام خانوادگی</label>
                <input type="text" class="input-text" name="lname" id="otp_lname" autocomplete="family-name" />
            </div>
            
            <button type="button" id="verify-otp-btn" class="button">ورود / ثبت نام</button>
            <a href="#" id="change-number-btn">تغییر شماره موبایل</a>
        </div>

    </form>
</div>