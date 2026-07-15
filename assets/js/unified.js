(function ($, window, document) {
    'use strict';

    if (window.UnifiedCheckoutInitialized) {
        return;
    }

    window.UnifiedCheckoutInitialized = true;

    const UnifiedCheckout = {

        /* =========================================================
         * CONFIG
         * ========================================================= */

        PAYMENT_METHOD: unified_params.payment_method,

        /* =========================================================
         * BANK-GRADE STATE MACHINE
         * ========================================================= */

        state: {
            status: 'idle', // idle | validating | popup | processing | done
            submitting: false,
            popup: null,
            popupInterval: null,
            orderId: null,
            button: null,
            buttonText: '',
            requestInFlightClassic: false,
            requestInFlightBlock: false,
            responseHandled: false,
            finalSuccess: false
        },

        /* =========================================================
         * INIT
         * ========================================================= */

        init: function () {
            const self = this;

            this.bindClassicCheckout();
            this.bindBlockCheckout();
            this.bindInputSanitization();

            // Re-bind events if layout structures refresh via multi-step AJAX changes
            $(document.body).on('updated_checkout updated_shipping_method fragments_refreshed fragments_loaded', function () {
                self.bindClassicCheckout();
            });

            console.log('[Unified] bank-grade initialized');
        },

        setStatus: function (status) {
            this.state.status = status;
        },

        canProceed: function (type) {
            if (type === 'Classic') {
                return !this.state.requestInFlightClassic;
            }
            if (type === 'Block') {
                return !this.state.requestInFlightBlock;
            }
            return true;
        },

        releaseLock: function (type) {
            if (!type) {
                this.state.requestInFlightClassic = false;
                this.state.requestInFlightBlock = false;
                return;
            }

            if (type === 'Classic') {
                this.state.requestInFlightClassic = false;
            }

            if (type === 'Block') {
                this.state.requestInFlightBlock = false;
            }
        },

        /* =========================================================
         * CLASSIC CHECKOUT
         * ========================================================= */

        bindClassicCheckout: function () {
            const self = this;

            $('form.checkout, form#wcf-embed-checkout-form, form.wcf-embed-checkout-form-steps')
                .off('checkout_place_order_' + self.PAYMENT_METHOD)
                .on('checkout_place_order_' + self.PAYMENT_METHOD, function () {

                    const $form = $(this);

                    if (!self.canProceed('Classic')) return false;
                    if (self.state.requestInFlightClassic) return false;

                    self.state.requestInFlightClassic = true;

                    self.setStatus('validating');
                    self.clearCheckoutErrors();

                    const requiredError = self.validateRequiredFields($form);
                    if (requiredError) {
                        self.releaseLock('Classic');
                        self.setStatus('idle');
                        self.reset();
                        self.showCheckoutError(requiredError.message, requiredError.fields);
                        return false;
                    }

                    const validationError = self.validateAll($form);
                    if (validationError) {
                        self.releaseLock('Classic');
                        self.setStatus('idle');
                        self.reset();
                        self.showCheckoutError(
                            'Please correct the following errors:',
                            validationError
                        );
                        return;
                    }

                    self.setStatus('popup');

                   const popup = self.openPopupImmediately();

                    if (!popup) {
                        self.releaseLock('Classic');
                        self.setStatus('idle');
                        return false;
                    }

                    // 3. NOW move to processing
                    self.setStatus('processing');

                    // 4. Start AJAX AFTER popup exists
                    self.handleClassicCheckout($form);
                    return false;
                });
        },

        buildCheckoutPayload: function () {
            const $form = $('form.checkout, form.wc-block-checkout__form, #order_review').first();

            let data = $form.serialize();

            // =====================================================
            // CRITICAL WOOCOMMERCE STATE ENFORCEMENT
            // =====================================================

            const shipToDifferent = $(
                '#ship-to-different-address-checkbox, input[name="ship_to_different_address"]'
            ).is(':checked') ? 1 : 0;

            data += '&ship_to_different_address=' + shipToDifferent;

            // Optional safety: force billing/shipping sync flag consistency
            if (!shipToDifferent) {
                data += '&wfacp_billing_same_as_shipping=1';
            }

            return data;
        },

        handleClassicCheckout: function ($form) {
            const self = this;

            self.state.button = $('body').find('button[name="woocommerce_checkout_place_order"], #wcf-order-place-btn').first();
            self.state.buttonText = self.state.button.text();

            self.state.button.prop('disabled', true).addClass('loading').text('Processing...');

            // Form payload compilation handles scattered form fields elegantly
            const dataPayload = self.buildCheckoutPayload();

            $.ajax({
                type: 'POST',
                url: wc_checkout_params.checkout_url,
                data: dataPayload,
                dataType: 'json',
                success: function (response) {
                    self.state.requestInFlightClassic = false;
                    self.handleResponse(response);
                },
                error: function (xhr) {
                    self.state.requestInFlightClassic = false;
                    console.log('[Unified] checkout network error:', xhr.responseText);
                    self.failSafe('There was an error processing your order.');
                }
            });
        },

        /* =========================================================
         * BLOCK CHECKOUT
         * ========================================================= */

        bindBlockCheckout: function () {
            const self = this;

            document.addEventListener('click', function (e) {
                const btn = e.target.closest('.wc-block-components-checkout-place-order-button');
                if (!btn) return;

                const $form = $('form.wc-block-checkout__form');
                if (!$form.length) return;

                const selected = $form.find(
                    'input[name="radio-control-wc-payment-method-options"]:checked'
                ).val();

                if (selected !== self.PAYMENT_METHOD) return;

                e.preventDefault();
                e.stopImmediatePropagation();

                if (!self.canProceed('Block')) return;
                if (self.state.requestInFlightBlock) return;

                self.state.requestInFlightBlock = true;

                self.setStatus('validating');
                self.clearCheckoutErrors();

                const requiredError = self.validateRequiredFields($form);
                if (requiredError) {
                    self.releaseLock('Block');
                    self.setStatus('idle');
                    self.reset();
                    self.showCheckoutError(requiredError.message, requiredError.fields);
                    return;
                }

                const validationError = self.validateAll($form);
                if (validationError) {
                    self.releaseLock('Block');
                    self.setStatus('idle');
                    self.reset();
                    self.showCheckoutError(
                        'Please correct the following errors:',
                        validationError
                    );
                    return;
                }

                self.setStatus('popup');
                const popup = self.openPopupImmediately();

                if (!popup) {
                    self.releaseLock('Block');
                    self.setStatus('idle');
                    return;
                }

                self.setStatus('processing');
                self.handleBlockCheckout($form);
            }, true);

            // Prevent native block context from bypassing validation filters
            document.addEventListener('submit', function (e) {
                const form = e.target;
                if (!form.classList.contains('wc-block-checkout__form')) return;

                const selected = form.querySelector(
                    'input[name="radio-control-wc-payment-method-options"]:checked'
                )?.value;

                if (selected !== self.PAYMENT_METHOD) return;

                e.preventDefault();
                e.stopImmediatePropagation();
            }, true);
        },

        handleBlockCheckout: function ($form) {
            const self = this;

            self.state.button = $('.wc-block-components-checkout-place-order-button');
            self.state.buttonText = self.state.button.text();

            self.state.button.prop('disabled', true).addClass('loading').text('Processing...');

            let data = self.buildCheckoutPayload();
            data += '&action=unified_block_gateway_process';
            data += '&nonce=' + encodeURIComponent(unified_params.unified_nonce);

            $.ajax({
                type: 'POST',
                url: unified_params.ajax_url,
                data: data,
                success: function (response) {
                    self.state.requestInFlightBlock = false;
                    self.handleResponse(response);
                },
                error: function (xhr) {
                    self.state.requestInFlightBlock = false;
                    console.log('[Unified] block checkout error:', xhr.responseText);
                    self.failSafe('There was an error processing your order.');
                }
            });
        },

        /* =========================================================
         * RESPONSE HANDLER
         * ========================================================= */

        handleResponse: function (response) {
            const self = this;

            if (self.state.responseHandled) return;

            try {
                if (typeof response === 'string') {
                    response = JSON.parse(response);
                }

                console.log('[Unified] parsed response', response);

                const success =
                    response?.result === 'success' ||
                    response?.success === true ||
                    response?.data?.payment_status === 'success' ||
                    response?.data?.payment_status === 'paid';

                const redirect = response?.redirect || response?.data?.redirect;
                const orderId = response?.order_id || response?.data?.order_id;

                self.state.orderId = orderId;

                if (!success) {
                    self.failSafe(response?.message || response?.data?.message || 'Payment failed. Please try again.');
                    return;
                }

                if (redirect && typeof redirect === 'string' && redirect.length > 5) {
                    self.state.responseHandled = true;

                    if (self.state.popup && !self.state.popup.closed) {
                        try {
                            // FIX: Use navigateWithoutReferrer instead of
                            // directly setting location.href, which would
                            // send the WP checkout URL as Referer header
                            // to the Laravel payment page.
                            self.navigateWithoutReferrer(self.state.popup, redirect);

                        } catch (e) {

                            // Safari fallback — re-open popup and use same method
                            if (!self.state.popup || self.state.popup.closed) {
                                self.state.popup = window.open(
                                    '',
                                    '_blank'
                                );
                            }

                            self.navigateWithoutReferrer(self.state.popup, redirect);
                        }
                        self.trackPopupClose();
                    } else {
                        window.location.href = redirect;
                        self.finish();
                    }
                    return;
                }

                self.failSafe('Missing redirect URL.');

            } catch (e) {
                console.log('[Unified] response processing exception', e);
                self.failSafe('Unexpected checkout error.');
            }
        },

        /* =========================================================
         * FAIL SAFE & STATE TERMINATION
         * ========================================================= */

        failSafe: function (message) {
            this.releaseLock();
            this.cleanupPopup();
            this.showCheckoutError(message);
            this.reset(false); // IMPORTANT: restore button immediately
            this.finish();
        },

        finish: function () {

            if (this.state.popupInterval) {
                clearInterval(this.state.popupInterval);
                this.state.popupInterval = null;
            }

            this.setStatus('done');
            this.reset(true);

            this.state.responseHandled = false;
            this.state.requestInFlightClassic = false;
            this.state.requestInFlightBlock = false;
            this.state.finalSuccess = false;

            setTimeout(() => {
                this.setStatus('idle');
            }, 500);
        },

        /* =========================================================
         * POPUP HANDLERS
         * ========================================================= */

        openPopupImmediately: function () {

            if (
                this.state.popup &&
                !this.state.popup.closed
            ) {
                return this.state.popup;
            }

            this.state.popup = window.open(
                '',
                '_blank',
                'width=700,height=700'
            );

            if (!this.state.popup) {
                alert('Popup blocked. Please allow popups for your payment.');
                return null;
            }

            const logoUrl = unified_params.unified_loader
                ? encodeURI(unified_params.unified_loader)
                : '';

            this.state.popup.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Secure Payment</title>
                    <meta name="referrer" content="no-referrer">
                </head>
                <body style="
                    margin:0;
                    display:flex;
                    justify-content:center;
                    align-items:center;
                    height:100vh;
                    font-family:sans-serif;
                    background:#fff;
                    text-align:center;
                ">
                    <div>
                        ${logoUrl ? `<img src="${logoUrl}" style="max-width:120px;margin-bottom:20px;" />` : ''}
                        <h3>Connecting to secure payment...</h3>
                        <p>Please do not close this window.</p>
                    </div>
                </body>
                </html>
            `);

            this.state.popup.document.close();

            return this.state.popup;
        },
        /* =========================================================
         * NAVIGATE WITHOUT REFERRER
         * ========================================================= */

        navigateWithoutReferrer: function (popup, url) {

            // The popup is still on about:blank so we own its document.
            // We write a new page into it that has:
            //   1. <meta name="referrer" content="no-referrer">  — referrer policy
            //   2. <meta http-equiv="refresh" content="0;url=..."> — immediate redirect
            //
            // The browser navigates from THIS intermediate page to the payment URL,
            // so document.referrer on the Laravel payment page will be empty string.
            // The SDK will see no referrer.
            try {
                var logoUrl = unified_params.unified_loader ? encodeURI(unified_params.unified_loader) : '';
                popup.document.open();
                popup.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Secure Payment</title>
                    <meta name="referrer" content="no-referrer">
                    <meta http-equiv="refresh" content="0;url=` + url + `">
                </head>

                <body style="
                    margin:0;
                    display:flex;
                    justify-content:center;
                    align-items:center;
                    height:100vh;
                    font-family:sans-serif;
                    background:#fff;
                    text-align:center;
                ">

                    <div>

                        ${
                            logoUrl
                                ? `<img src="${logoUrl}" style="max-width:120px;margin-bottom:20px;" />`
                                : ''
                        }

                        <h3>Connecting to secure payment...</h3>

                        <p>Please do not close this window.</p>

                    </div>

                </body>
                </html>
            `);
                popup.document.close();

            } catch (e) {

                // Last resort fallback — referrer may leak but payment still works
                console.log('[Unified] navigateWithoutReferrer fallback', e);
                popup.location.href = url;
            }
        },

        cleanupPopup: function () {
            if (this.state.popupInterval) {
                clearInterval(this.state.popupInterval);
                this.state.popupInterval = null;
            }

            if (this.state.popup && !this.state.popup.closed) {
                try { this.state.popup.close(); } catch (e) {}
            }
            this.state.popup = null;
        },

        trackPopupClose: function () {

            const self = this;

            if (!self.state.orderId) {
                console.log('[Unified] No order ID for popup tracking');
                return;
            }

            // clear any previous interval (important safety)
            if (self.state.popupInterval) {
                clearInterval(self.state.popupInterval);
                self.state.popupInterval = null;
            }

            self.state.popupInterval = setInterval(function () {

                 if (self.state.finalSuccess) {
                    clearInterval(self.state.popupInterval);
                    self.state.popupInterval = null;
                    return;
                }

                const popupStillOpen =
                    self.state.popup &&
                    !self.state.popup.closed;

                // 👉 wait until popup closes
                if (popupStillOpen) {
                    return;
                }

                clearInterval(self.state.popupInterval);
                self.state.popupInterval = null;

                console.log('[Unified] Popup closed → single final check');

                $.post(
                    unified_params.ajax_url,
                    {
                        action: 'unified_popup_closed_event',
                        order_id: self.state.orderId,
                        security: unified_params.unified_nonce
                    },
                    function (response) {

                        const success =
                            response?.success === true ||
                            response?.data?.payment_status === 'success' ||
                            response?.data?.payment_status === 'paid';

                        const redirectUrl =
                            response?.data?.redirect ||
                            response?.redirect;

                        if (success && redirectUrl) {

                            console.log('[Unified] Payment success → redirect');

                            self.state.finalSuccess = true;

                            clearInterval(self.state.popupInterval);
                            self.state.popupInterval = null;

                            self.cleanupPopup();

                            window.location.replace(redirectUrl);
                            return;
                        }

                        console.log('[Unified] Payment failed / incomplete');

                        self.cleanupPopup();
                        self.showCheckoutError(
                            response?.message ||
                            'Your payment was not completed.'
                        );

                        self.reset();

                    },
                    'json'
                );

            }, 1000); // small check ONLY for popup close detection
        },

        /* =========================================================
         * DATA FILTERS & VALIDATIONS
         * ========================================================= */

        validateAll: function ($form) {
            const errors = [];

            const email = this.getBillingEmail($form);

            if (!email) {
                errors.push('Please enter your email address.');
            } else if (!this.isValidEmail(email)) {
                errors.push('Please enter a valid email address.');
            }

            if (!this.getBillingFirstName($form)?.trim()) {
                errors.push('Please enter your first name.');
            } else if (this.getBillingFirstName($form).length < 3) {
                errors.push('First name must contain at least 3 characters.');
            }

            if (!this.getBillingLastName($form)?.trim()) {
                errors.push('Please enter your last name.');
            } else if (this.getBillingLastName($form).length < 3) {
                errors.push('Last name must contain at least 3 characters.');
            }

            let billingAddress1 = this.getBillingAddress1($form)?.trim();

            if (!billingAddress1) {
                errors.push('Please enter your address.');
            } else if (billingAddress1.length < 5) {
                errors.push('Address must contain at least 5 characters.');
            } else if (!/[a-zA-Z]/.test(billingAddress1)) {
                errors.push('Please enter a valid address.');
            }

            if (!this.getBillingCity($form)?.trim()) {
                errors.push('Please enter your city.');
            } else if (this.getBillingCity($form).length < 2) {
                errors.push('City must contain at least 2 characters.');
            }

            const postcode = this.getBillingPostCode($form);
            if (!postcode || !postcode.trim()) {
                errors.push('Please enter your postal code.');
            } else if (postcode.trim().length < 5) {
                errors.push('Postal code must contain at least 5 characters.');
            } else if (postcode.trim().length > 10) {
                errors.push('Postal code cannot exceed 10 characters.');
            } else if (!this.isValidPostCode(postcode.trim())) {
                errors.push('Please enter a valid postal code.');
            }

            const phone = this.getPhoneNumber($form);
            if (phone && phone.trim()) {
                const cleanedPhone = phone.replace(/[\s\-().]/g, '');
                if (/[a-zA-Z]/.test(phone)) {
                    errors.push('Phone number cannot contain letters.');
                } else if (cleanedPhone.length < 10) {
                    errors.push('Phone number must contain at least 10 digits.');
                } else if (cleanedPhone.length > 15) {
                    errors.push('Phone number cannot exceed 15 digits.');
                } else if (!this.isValidPhoneNumber(phone)) {
                    errors.push('Please enter a valid phone number.');
                }
            }

            const poBox = this.validatePOBox($form);
            if (poBox) {
                errors.push(poBox);
            }

            return errors.length > 0 ? errors : null;
        },

        validateRequiredFields: function ($form) {
            let missing = [];
            let firstInvalid = null;
            const isShippingActive = this.getShippingState($form);

            const $allFields = $('form.checkout, form.wc-block-checkout__form, form#wcf-embed-checkout-form').find('[required]');

            $allFields.each(function () {
                const $field = $(this);
                const name = $field.attr('name') || '';

                if ($field.attr('type') === 'hidden') return;

                if (name.indexOf('shipping_') === 0 && !isShippingActive) return;

                const $conditionalParent = $field.closest('.wcf-conditional-field, .woocommerce-validated');
                if ($conditionalParent.length && $conditionalParent.is(':hidden')) return;

                const val = ($field.val() || '').trim();
                const $wrapper = $field.closest('.form-row, .wc-block-components-text-input, .form-row-first, .form-row-last');

                if (!val) {
                    $wrapper.addClass('woocommerce-invalid woocommerce-invalid-required-field');

                    let label = $wrapper.find('label').first().text().trim() || $field.attr('placeholder') || name;
                    label = label.replace('*', '').trim();

                    if (label && !missing.includes(label)) {
                        missing.push(label);
                    }
                    if (!firstInvalid) firstInvalid = $field;
                } else {
                    $wrapper.removeClass('woocommerce-invalid woocommerce-invalid-required-field');
                }
            });

            if (firstInvalid && firstInvalid.is(':visible')) {
                setTimeout(function () { firstInvalid.trigger('focus'); }, 100);
            }

            return missing.length ? { message: 'Please fill required fields.', fields: missing } : null;
        },

        getShippingState: function ($form) {
            const $root = $('body');
            const $shipToDifferentCheckbox = $root.find('#ship-to-different-address-checkbox, input[name="ship_to_different_address"]');
            
            if ($shipToDifferentCheckbox.length) {
                if ($shipToDifferentCheckbox.is(':checkbox')) {
                    return $shipToDifferentCheckbox.is(':checked');
                }
                const val = $shipToDifferentCheckbox.val();
                return (val === '1' || val === 'yes' || val === 'true');
            }

            const $shippingWrapper = $root.find('.shipping_address, .wcf-shipping-address-fade');
            if ($shippingWrapper.length) {
                return $shippingWrapper.is(':visible') || $shippingWrapper.css('display') === 'block';
            }

            return false;
        },

        validatePOBox: function ($form) {
            const isShippingActive = this.getShippingState($form);
            const $root = $('body');
            
            const billing1 = this.getBillingAddress1($form);
            const billing2 = $root.find('[name="billing_address_2"]').val();
            
            if (this.containsPOBox(billing1) || this.containsPOBox(billing2)) {
                return 'PO Box addresses are not allowed for Billing.';
            }

            if (isShippingActive) {
                const shipping1 = $root.find('[name="shipping_address_1"]').val();
                const shipping2 = $root.find('[name="shipping_address_2"]').val();
                
                if (this.containsPOBox(shipping1) || this.containsPOBox(shipping2)) {
                    return 'PO Box addresses are not allowed for Shipping.';
                }
            }
            return null;
        },

        containsPOBox: function (value) {
            if (!value) return false;
            const cleaned = value.toLowerCase().replace(/[^a-z0-9]/g, '');
            return (cleaned.includes('pob') || cleaned.includes('postalbox') || cleaned.includes('postofficebox'));
        },

        /* =========================================================
         * RESET & INTERFACE MANAGERS
         * ========================================================= */

        reset: function (keepDisabled = false) {

            if (this.state.popupInterval) {
                clearInterval(this.state.popupInterval);
                this.state.popupInterval = null;
            }

            this.state.submitting = false;
            this.state.status = 'idle';

            this.state.popup = null;
            this.state.orderId = null;
            this.state.button = null;
            this.state.responseHandled = false;
            this.state.requestInFlightClassic = false;
            this.state.requestInFlightBlock = false;
            this.state.finalSuccess = false;

            const $button = $('.wc-block-components-checkout-place-order-button, button[name="woocommerce_checkout_place_order"], #wcf-order-place-btn');

            if (!$button.length) return;

            if (keepDisabled) {
                $button.prop('disabled', false)   // IMPORTANT FIX
                    .removeClass('loading')
                    .text(this.state.buttonText || 'Place order');
                return;
            }

            $button
                .prop('disabled', false)
                .removeClass('loading')
                .text(this.state.buttonText || 'Place order');
        },

        showCheckoutError: function (message, fields = []) {
            $('.unified-error-wrap, .woocommerce-notices-wrapper, .wcf-woocommerce-notices-wrapper').remove();

            let fieldsHtml = '';

            if (Array.isArray(fields) && fields.length) {
                fieldsHtml = `
                    <ul class="unified-error-fields" style="margin-top: 5px; padding-left: 20px;">
                        ${fields.map(field => `<li>${field}</li>`).join('')}
                    </ul>`;
            }

            const html = `
                <div class="woocommerce-notices-wrapper wcf-woocommerce-notices-wrapper unified-error-wrap">
                    <div class="woocommerce-error unified-error-box" role="alert" style="border-left:3px solid #cc0000;padding:1em;background:#fff1f1;">
                        <div class="unified-error-header"><strong>${message}</strong></div>
                        ${fieldsHtml}
                    </div>
                </div>`;

            const targets = ['.wc-block-checkout__form', 'form.checkout', 'form#wcf-embed-checkout-form', '.wcf-embed-checkout-form-steps'];
            let inserted = false;

            for (let target of targets) {
                const $el = $(target);
                if ($el.length) {
                    $el.prepend(html);
                    inserted = true;
                    break;
                }
            }

            if (!inserted) {
                $('body').prepend(html);
            }

            const $notice = $('.woocommerce-notices-wrapper, .wcf-woocommerce-notices-wrapper');
            if ($notice.length) {
                $('html, body').animate({
                    scrollTop: $notice.offset().top - 80
                }, 300);
            }
        },

        clearCheckoutErrors: function () {
            $('.woocommerce-notices-wrapper, .wcf-woocommerce-notices-wrapper, .woocommerce-error, .wc-block-components-notice-banner, .woocommerce-message, .woocommerce-info, .unified-error-wrap').remove();
        },

        getBillingFirstName: function ($form) {
            if ($form.find('#billing_first_name').first().val() && $form.find('#billing_first_name').first().val() !== '') {
                return $form.find('#billing_first_name').first().val();
            } else if ($form.find('#billing-first_name').first().val() && $form.find('#billing-first_name').first().val() !== '') {
                return $form.find('#billing-first_name').first().val();
            } else if ($form.find('#shipping_first_name').first().val() && $form.find('#shipping_first_name').first().val() !== '') {
                return $form.find('#shipping_first_name').first().val();
            } else if ($form.find('#shipping-first_name').first().val() && $form.find('#shipping-first_name').first().val() !== '') {
                return $form.find('#shipping-first_name').first().val();
            }
            return $('body').find('#billing_first_name, #first_name, input[type="text"]').first().val() || '';
        },

        getBillingLastName: function ($form) {
            if ($form.find('#billing_last_name').first().val() && $form.find('#billing_last_name').first().val() !== '') {
                return $form.find('#billing_last_name').first().val();
            } else if ($form.find('#billing-last_name').first().val() && $form.find('#billing-last_name').first().val() !== '') {
                return $form.find('#billing-last_name').first().val();
            } else if ($form.find('#shipping_last_name').first().val() && $form.find('#shipping_last_name').first().val() !== '') {
                return $form.find('#shipping_last_name').first().val();
            } else if ($form.find('#shipping-last_name').first().val() && $form.find('#shipping-last_name').first().val() !== '') {
                return $form.find('#shipping-last_name').first().val();
            }
            return $('body').find('#billing_last_name, #last_name, input[type="text"]').first().val() || '';
        },

        getBillingCity: function ($form) {
            if ($form.find('#billing_city').first().val() && $form.find('#billing_city').first().val() !== '') {
                return $form.find('#billing_city').first().val();
            } else if ($form.find('#billing-city').first().val() && $form.find('#billing-city').first().val() !== '') {
                return $form.find('#billing-city').first().val();
            }  else if ($form.find('#shipping_city').first().val() && $form.find('#shipping_city').first().val() !== '') {
                return $form.find('#shipping_city').first().val();
            } else if ($form.find('#shipping-city').first().val() && $form.find('#shipping-city').first().val() !== '') {
                return $form.find('#shipping-city').first().val();
            }
            return $('body').find('#billing_city, #city, input[type="text"]').first().val() || '';
        },

        getBillingPostCode: function ($form) {
            if ($form.find('#billing_postcode').first().val() && $form.find('#billing_postcode').first().val() !== '') {
                return $form.find('#billing_postcode').first().val();
            } else if ($form.find('#billing-postcode').first().val() && $form.find('#billing-postcode').first().val() !== '') {
                return $form.find('#billing-postcode').first().val();
            } else if ($form.find('#shipping_postcode').first().val() && $form.find('#shipping_postcode').first().val() !== '') {
                return $form.find('#shipping_postcode').first().val();
            } else if ($form.find('#shipping-postcode').first().val() && $form.find('#shipping-postcode').first().val() !== '') {
                return $form.find('#shipping-postcode').first().val();
            }
            return $('body').find('#billing_postcode, #postcode, input[type="text"]').first().val() || '';
        },

         getBillingAddress1: function ($form) {
            let value;

            if ((value = $form.find('#shipping-address_1').first().val()) && value.trim() !== '') {
                return value;
            }

            if ((value = $form.find('#shipping_address-1').first().val()) && value.trim() !== '') {
                return value;
            }

            if ((value = $form.find('#shipping_address_1').first().val()) && value.trim() !== '') {
                return value;
            }

            if ((value = $form.find('#billing_address_1').first().val()) && value.trim() !== '') {
                return value;
            }

            if ((value = $form.find('#billing-address-1').first().val()) && value.trim() !== '') {
                return value;
            }
            
            if ((value = $form.find('#billing-address_1').first().val()) && value.trim() !== '') {
                return value;
            }
            return $('body').find('#shipping-address_1, #address_1, input[type="text"]').first().val() || '';
        },

        getBillingEmail: function ($f) {
            return $('body').find('#billing_email, #email, input[type="email"]').first().val();
        },

        getPhoneNumber: function ($f) {
            return $('body').find('input[name="billing_phone"], input[type="tel"]').first().val();
        },

        isValidEmail: function (e) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e);
        },

        isValidPostCode: function (postcode) {
            if (!postcode) {
                return false;
            }

            postcode = postcode.trim();

            // Allow only letters, numbers and spaces
            return /^[A-Za-z0-9 ]+$/.test(postcode);
        },

        isValidPhoneNumber: function (p) {

            if (!p) return true;

            const cleaned = p.replace(/[\s\-().]/g, '');

            // digits only
            if (!/^\+?\d+$/.test(cleaned)) {
                return false;
            }

            const numberOnly = cleaned.replace('+','');

            // reject repeated digits
            if (/^(\d)\1+$/.test(numberOnly)) {
                return false;
            }

            // reject common test numbers
            const invalidNumbers = [
                '0000000000',
                '1111111111',
                '2222222222',
                '3333333333',
                '4444444444',
                '5555555555',
                '6666666666',
                '7777777777',
                '8888888888',
                '9999999999',
                '1234567890',
                '9876543210'
            ];

            if (invalidNumbers.includes(numberOnly)) {
                return false;
            }


            return (
                /^1?\d{10}$/.test(numberOnly) ||
                /^[1-9]\d{6,14}$/.test(numberOnly)
            );
        },

        bindInputSanitization: function () {
            const selectors = `
                #unified_card_holder, #billing_first_name, #billing_last_name, #billing_city,
                input[name="billing_first_name"], input[name="billing_last_name"], input[name="billing_city"],
                input[name="shipping_first_name"], input[name="shipping_last_name"], input[name="shipping_city"]
            `;

            $(document).off('input keyup blur change paste', selectors).on(
                'input keyup blur change paste',
                selectors,
                function () {
                    const clean = this.value.replace(/[^A-Za-z\s\-']/g, '');
                    if (this.value !== clean) {
                        this.value = clean;
                    }
                }
            );

            $(document).off('input', '#billing_address_1, #shipping_address_1').on(
                'input',
                '#billing_address_1, #shipping_address_1',
                function () {
                    this.value = this.value.replace(/[^A-Za-z0-9\s,.\-#]/g, '');
                }
            );
        }
    };

    $(document).ready(function () {
        UnifiedCheckout.init();
    });

})(jQuery, window, document);