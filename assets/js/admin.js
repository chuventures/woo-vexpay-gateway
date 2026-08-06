/**
 * VEXPay gateway admin — Sandbox toggle + Test connection.
 */
(function ($) {
	'use strict';

	function fieldVal(name) {
		var $el = $('#woocommerce_vexpay_' + name);
		if (!$el.length) {
			return '';
		}
		if ($el.is(':checkbox')) {
			return $el.is(':checked') ? 'yes' : 'no';
		}
		return String($el.val() || '');
	}

	function setResult(ok, message) {
		var $box = $('#vexpay-test-connection-result');
		$box
			.removeClass('vexpay-connection-ok vexpay-connection-error')
			.addClass(ok ? 'vexpay-connection-ok' : 'vexpay-connection-error')
			.text(message || '');
	}

	/**
	 * Sandbox checked → show test key, hide live key.
	 * Sandbox unchecked → show live key, hide test key.
	 */
	function syncApiKeyVisibility() {
		var sandboxOn = $('#woocommerce_vexpay_testmode').is(':checked');
		var $body = $(document.body);

		$body.toggleClass('vexpay-sandbox-on', sandboxOn);
		$body.toggleClass('vexpay-sandbox-off', !sandboxOn);
	}

	$(function () {
		if (!$('#woocommerce_vexpay_testmode').length) {
			return;
		}

		syncApiKeyVisibility();
		$('#woocommerce_vexpay_testmode').on('change', syncApiKeyVisibility);

		$('#vexpay-test-connection').on('click', function (e) {
			e.preventDefault();
			if (typeof vexpayAdmin === 'undefined') {
				return;
			}

			var $btn = $(this);
			var $spin = $('#vexpay-test-connection-spinner');

			$btn.prop('disabled', true);
			$spin.css('visibility', 'visible');
			setResult(true, vexpayAdmin.i18n.testing);

			$.post(vexpayAdmin.ajaxUrl, {
				action: 'vexpay_test_connection',
				nonce: vexpayAdmin.nonce,
				testmode: fieldVal('testmode'),
				live_api_key: fieldVal('live_api_key'),
				test_api_key: fieldVal('test_api_key')
			})
				.done(function (res) {
					if (res && res.success && res.data && res.data.message) {
						setResult(true, res.data.message);
					} else {
						var msg =
							(res && res.data && res.data.message) ||
							vexpayAdmin.i18n.failed;
						setResult(false, msg);
					}
				})
				.fail(function (xhr) {
					var msg = vexpayAdmin.i18n.failed;
					if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						msg = xhr.responseJSON.data.message;
					}
					setResult(false, msg);
				})
				.always(function () {
					$btn.prop('disabled', false);
					$spin.css('visibility', 'hidden');
				});
		});
	});
})(jQuery);
