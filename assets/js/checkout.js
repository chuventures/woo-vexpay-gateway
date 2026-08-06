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
			var activeIndex = -1;
			$picker.data('placeholder', placeholder);

			function options() {
				return $list.find('.vexpay-bank-option');
			}

			function setActive(index) {
				var $opts = options();
				if (!$opts.length) {
					return;
				}
				if (index < 0) {
					index = $opts.length - 1;
				}
				if (index >= $opts.length) {
					index = 0;
				}
				activeIndex = index;
				$opts.removeClass('is-active');
				var $active = $opts.eq(activeIndex).addClass('is-active');
				$trigger.attr('aria-activedescendant', $active.attr('id') || null);
				if ($active.length && $active[0].scrollIntoView) {
					$active[0].scrollIntoView({ block: 'nearest' });
				}
			}

			function close() {
				$list.attr('hidden', true);
				$trigger.attr('aria-expanded', 'false').removeAttr('aria-activedescendant');
				$picker.removeClass('is-open');
				options().removeClass('is-active');
				activeIndex = -1;
			}

			function open(startIndex) {
				$list.removeAttr('hidden');
				$trigger.attr('aria-expanded', 'true');
				$picker.addClass('is-open');
				var current = String($input.val() || '');
				var $opts = options();
				var idx = 0;
				if (typeof startIndex === 'number') {
					idx = startIndex;
				} else if (current) {
					$opts.each(function (i) {
						if (String($(this).data('code')) === current) {
							idx = i;
							return false;
						}
					});
				}
				setActive(idx);
			}

			function selectOption($opt) {
				if (!$opt || !$opt.length) {
					return;
				}
				var bank = {
					code: String($opt.data('code') || ''),
					name: String($opt.data('name') || ''),
					logo: String($opt.data('logo') || ''),
				};
				$input.val(bank.code).trigger('change');
				options().attr('aria-selected', 'false').removeClass('is-selected');
				$opt.attr('aria-selected', 'true').addClass('is-selected');
				renderTrigger($picker, bank);
				close();
				$trigger.trigger('focus');
			}

			// Stable ids for aria-activedescendant.
			options().each(function () {
				var $opt = $(this);
				if (!$opt.attr('id')) {
					$opt.attr('id', 'vexpay-bank-option-' + String($opt.data('code') || ''));
				}
			});

			var current = String($input.val() || '');
			if (current) {
				var $selected = options()
					.filter(function () {
						return String($(this).data('code')) === current;
					})
					.first();
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

			$trigger.on('keydown', function (e) {
				var key = e.key;
				var $opts = options();
				if (!$opts.length) {
					return;
				}

				if (key === 'ArrowDown' || key === 'ArrowUp') {
					e.preventDefault();
					if (!$picker.hasClass('is-open')) {
						open(key === 'ArrowUp' ? $opts.length - 1 : 0);
						return;
					}
					setActive(key === 'ArrowDown' ? activeIndex + 1 : activeIndex - 1);
					return;
				}
				if (key === 'Home' && $picker.hasClass('is-open')) {
					e.preventDefault();
					setActive(0);
					return;
				}
				if (key === 'End' && $picker.hasClass('is-open')) {
					e.preventDefault();
					setActive($opts.length - 1);
					return;
				}
				if ((key === 'Enter' || key === ' ') && $picker.hasClass('is-open') && activeIndex >= 0) {
					e.preventDefault();
					selectOption($opts.eq(activeIndex));
					return;
				}
				if (key === 'Enter' || key === ' ') {
					e.preventDefault();
					if ($picker.hasClass('is-open')) {
						close();
					} else {
						open();
					}
					return;
				}
				if (key === 'Escape' && $picker.hasClass('is-open')) {
					e.preventDefault();
					close();
					$trigger.trigger('focus');
				}
			});

			$list.on('mouseenter', '.vexpay-bank-option', function () {
				setActive(options().index(this));
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
