/**
 * Front-end helpers for classic checkout.
 */
(function ($) {
	'use strict';

	function digitsOnly($el, max) {
		if (!$el.length) {
			return;
		}
		$el.on('input', function () {
			var v = String($el.val() || '').replace(/\D+/g, '');
			if (max) {
				v = v.slice(0, max);
			}
			$el.val(v);
		});
	}

	function bindSplitFields() {
		digitsOnly($('#vexpay_debtor_id_number'), 9);
		digitsOnly($('#vexpay_debtor_phone_number'), 7);
	}

	$(function () {
		bindSplitFields();
		$(document.body).on('updated_checkout', bindSplitFields);
	});
})(jQuery);
