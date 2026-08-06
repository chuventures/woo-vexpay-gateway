/**
 * Front-end helpers for classic checkout.
 */
(function ($) {
	'use strict';

	function normalizePhoneHint() {
		var $phone = $('#vexpay_debtor_phone');
		if (!$phone.length) {
			return;
		}
		$phone.on('blur', function () {
			var v = String($phone.val() || '').replace(/\D+/g, '');
			if (/^0(4\d{9})$/.test(v)) {
				$phone.val('58' + v.slice(1));
			} else if (/^4\d{9}$/.test(v)) {
				$phone.val('58' + v);
			}
		});
	}

	$(function () {
		normalizePhoneHint();
		$(document.body).on('updated_checkout', normalizePhoneHint);
	});
})(jQuery);
