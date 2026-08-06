/**
 * Front-end helpers for classic checkout.
 */
(function ($) {
	'use strict';

	function digitsOnly($el, max) {
		if (!$el.length) {
			return;
		}
		$el.off('input.vexpayDigits').on('input.vexpayDigits', function () {
			var v = String($el.val() || '').replace(/\D+/g, '');
			if (max) {
				v = v.slice(0, max);
			}
			$el.val(v);
		});
	}

	function renderTrigger($picker, bank) {
		var $content = $picker.find('.vexpay-bank-trigger-content');
		if (!bank || !bank.code) {
			$content.html(
				'<span class="vexpay-bank-placeholder">' +
					($picker.data('placeholder') || 'Select your bank') +
					'</span>'
			);
			return;
		}
		var logo = bank.logo
			? '<img class="vexpay-bank-logo" src="' +
			  bank.logo.replace(/"/g, '&quot;') +
			  '" alt="" width="32" height="32" loading="lazy" decoding="async" />'
			: '<span class="vexpay-bank-logo vexpay-bank-logo--fallback" aria-hidden="true">' +
			  String(bank.code).slice(-2) +
			  '</span>';
		$content.html(
			'<span class="vexpay-bank-option-inner">' +
				logo +
				'<span class="vexpay-bank-meta">' +
				'<span class="vexpay-bank-name">' +
				$('<div>').text(bank.name).html() +
				'</span> ' +
				'<span class="vexpay-bank-code">(' +
				$('<div>').text(bank.code).html() +
				')</span>' +
				'</span></span>'
		);
	}

	function bindBankPicker($root) {
		$root.find('[data-vexpay-bank-picker]').each(function () {
			var $picker = $(this);
			if ($picker.data('vexpayBound')) {
				return;
			}
			$picker.data('vexpayBound', true);

			var $input = $picker.closest('.vexpay-bank-field').find('#vexpay_debtor_bank');
			var $list = $picker.find('.vexpay-bank-list');
			var $trigger = $picker.find('.vexpay-bank-trigger');
			var placeholder = $picker.find('.vexpay-bank-placeholder').text();
			$picker.data('placeholder', placeholder);

			function close() {
				$list.attr('hidden', true);
				$trigger.attr('aria-expanded', 'false');
				$picker.removeClass('is-open');
			}

			function open() {
				$list.removeAttr('hidden');
				$trigger.attr('aria-expanded', 'true');
				$picker.addClass('is-open');
			}

			function selectOption($opt) {
				var bank = {
					code: String($opt.data('code') || ''),
					name: String($opt.data('name') || ''),
					logo: String($opt.data('logo') || ''),
				};
				$input.val(bank.code).trigger('change');
				$list.find('.vexpay-bank-option').attr('aria-selected', 'false').removeClass('is-selected');
				$opt.attr('aria-selected', 'true').addClass('is-selected');
				renderTrigger($picker, bank);
				close();
			}

			var current = String($input.val() || '');
			if (current) {
				var $selected = $list.find('.vexpay-bank-option').filter(function () {
					return String($(this).data('code')) === current;
				}).first();
				if ($selected.length) {
					selectOption($selected);
				}
			}

			$trigger.on('click', function (e) {
				e.preventDefault();
				if ($picker.hasClass('is-open')) {
					close();
				} else {
					open();
				}
			});

			$list.on('click', '.vexpay-bank-option', function (e) {
				e.preventDefault();
				selectOption($(this));
			});

			$(document).on('mousedown.vexpayBank' + $picker.index(), function (e) {
				if (!$picker.is(e.target) && $picker.has(e.target).length === 0) {
					close();
				}
			});
		});
	}

	function bindSplitFields() {
		digitsOnly($('#vexpay_debtor_id_number'), 9);
		digitsOnly($('#vexpay_debtor_phone_number'), 7);
		bindBankPicker($(document.body));
	}

	$(function () {
		bindSplitFields();
		$(document.body).on('updated_checkout', bindSplitFields);
	});
})(jQuery);
