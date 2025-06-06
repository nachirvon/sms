jQuery(function ($) {
    'use strict';

    const form = $('#minimal-otp-form');
    if (!form.length) {
        return;
    }

    // Cache jQuery objects for performance
    const step1 = $('#otp-step-1');
    const step2 = $('#otp-step-2');
    const newUserFields = $('#new-user-fields');
    const statusMessage = $('.otp-status-message');
    const phoneInput = $('#otp_phone_number');
    const sendBtn = $('#send-otp-btn');
    const verifyBtn = $('#verify-otp-btn');
    const changeNumberBtn = $('#change-number-btn');
    const nonceField = $('#otp_nonce_field');

    // --- Event Handler for "Send Code" button ---
    sendBtn.on('click', function () {
        const phone = phoneInput.val();

        // Basic validation
        if (!phone.match(/^09[0-9]{9}$/)) {
            showMessage('لطفاً یک شماره موبایل معتبر وارد کنید.', 'error');
            return;
        }

        setButtonLoading(sendBtn, true, 'در حال ارسال...');
        
        $.ajax({
            type: 'POST',
            url: otp_object.ajax_url,
            data: {
                action: 'send_otp',
                phone: phone,
                nonce: otp_object.nonce // Send nonce for security
            },
            success: function (response) {
                if (response.success) {
                    showMessage(response.data.message, 'success');
                    step1.hide();
                    step2.show();
                    
                    // If user does not exist, show name fields
                    if (response.data.user_exists === false) {
                        newUserFields.show();
                    }

                } else {
                    showMessage(response.data.message, 'error');
                    setButtonLoading(sendBtn, false, 'ارسال کد تایید');
                }
            },
           error: function (jqXHR, textStatus, errorThrown) {
                showMessage('یک خطای پیش‌بینی نشده در ارتباط رخ داد.', 'error');
                setButtonLoading(sendBtn, false, 'ارسال کد تایید');
            }
        });
    });
    
    // --- Event Handler for "Verify/Login" button ---
    verifyBtn.on('click', function(){
        setButtonLoading(verifyBtn, true, 'در حال بررسی...');

        $.ajax({
            type: 'POST',
            url: otp_object.ajax_url,
            data: {
                action: 'verify_otp',
                phone: phoneInput.val(),
                otp: $('#otp_code').val(),
                fname: $('#otp_fname').val(),
                lname: $('#otp_lname').val(),
                nonce: otp_object.nonce
            },
            success: function(response){
                if (response.success) {
                    showMessage(response.data.message, 'success');
                    // Redirect the user on successful login
                    window.location.href = response.data.redirect;
                } else {
                    showMessage(response.data.message, 'error');
                    setButtonLoading(verifyBtn, false, 'ورود / ثبت نام');
                }
            },
            error: function(){
                showMessage('یک خطای پیش‌بینی نشده رخ داد. لطفاً دوباره تلاش کنید.', 'error');
                setButtonLoading(verifyBtn, false, 'ورود / ثبت نام');
            }
        });
    });

    // --- Event Handler for "Change Number" link ---
    changeNumberBtn.on('click', function(e){
        e.preventDefault();
        step2.hide();
        newUserFields.hide(); // Hide name fields as well
        step1.show();
        setButtonLoading(sendBtn, false, 'ارسال کد تایید');
        showMessage('', ''); // Clear any messages
    });

    // --- Helper Functions ---
    function showMessage(message, type) {
        statusMessage.text(message).removeClass('success error').addClass(type);
    }

    function setButtonLoading(button, isLoading, text) {
        button.prop('disabled', isLoading).text(text);
    }
});