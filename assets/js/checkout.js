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

	function bankAttr($el, key) {
		// Prefer attr() so SIMF codes like "0102" are not coerced to numbers by jQuery.data().
		var raw = $el.attr('data-' + key);
		if (typeof raw === 'undefined' || raw === null) {
			raw = $el.data(key);
		}
		return raw == null ? '' : String(raw);
	}

	function normBankCode(c) {
		return String(c || '')
			.replace(/\D+/g, '')
			.padStart(4, '0')
			.slice(-4);
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
						if (normBankCode(bankAttr($(this), 'code')) === normBankCode(current)) {
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
					code: bankAttr($opt, 'code'),
					name: bankAttr($opt, 'name'),
					logo: bankAttr($opt, 'logo'),
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
					$opt.attr('id', 'vexpay-bank-option-' + bankAttr($opt, 'code'));
				}
			});

			var current = String($input.val() || '');
			if (current) {
				var $selected = options()
					.filter(function () {
						return normBankCode(bankAttr($(this), 'code')) === normBankCode(current);
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
		bindAccountSwitcher($(document.body));
	}

	function setBankByCode(code) {
		var $input = $('#vexpay_debtor_bank');
		var $picker = $('[data-vexpay-bank-picker]').first();
		if (!$input.length || !$picker.length) {
			return;
		}
		var want = String(code || '');
		$input.val(want);
		var $opt = $picker.find('.vexpay-bank-option').filter(function () {
			return normBankCode(bankAttr($(this), 'code')) === normBankCode(want);
		}).first();
		if ($opt.length) {
			var bank = {
				code: bankAttr($opt, 'code'),
				name: bankAttr($opt, 'name'),
				logo: bankAttr($opt, 'logo'),
			};
			$input.val(bank.code);
			$picker.find('.vexpay-bank-option').attr('aria-selected', 'false').removeClass('is-selected');
			$opt.attr('aria-selected', 'true').addClass('is-selected');
			renderTrigger($picker, bank);
		} else {
			renderTrigger($picker, want ? { code: want, name: want, logo: '' } : null);
		}
	}

	function bindAccountSwitcher($root) {
		var $wrap = $root.find('[data-vexpay-accounts]');
		if (!$wrap.length || $wrap.data('vexpayBound')) {
			return;
		}
		$wrap.data('vexpayBound', true);

		var $details = $root.find('.vexpay-account-details').first();
		if (!$details.length) {
			$details = $('#wc-vexpay-cc-form');
		}
		// On change-account page the form itself is the details panel.
		if (!$details.length) {
			$details = $root.find('.vexpay-change-account-form').first();
		}

		var cfg =
			typeof window.vexpayCheckout === 'object' && window.vexpayCheckout
				? window.vexpayCheckout
				: {};
		var deleteCfg = cfg.deleteAccount || {};
		var i18n = cfg.i18n || {};

		function hideDetailsPanel() {
			// Change-account editor keeps fields visible (data-vexpay-keep-details).
			if ($wrap.attr('data-vexpay-keep-details') === '1') {
				return;
			}
			if ($details.length) {
				$details.prop('hidden', true).attr('hidden', 'hidden');
			}
		}

		function showDetailsPanel() {
			if ($details.length) {
				$details.prop('hidden', false).removeAttr('hidden');
			}
		}

		function clearFormFields() {
			$('#vexpay_debtor_id_type').val('V');
			$('#vexpay_debtor_id_number').val('');
			$('#vexpay_debtor_phone_prefix').val('0412');
			$('#vexpay_debtor_phone_number').val('');
			setBankByCode('');
		}

		function markActive($chip) {
			$wrap.find('[data-vexpay-account]').removeClass('is-active').attr('aria-selected', 'false');
			$wrap.find('[data-vexpay-account-new]').removeClass('is-active');
			if ($chip && $chip.length) {
				$chip.addClass('is-active').attr('aria-selected', 'true');
			} else {
				$wrap.find('[data-vexpay-account-new]').addClass('is-active');
			}
		}

		function selectChip($chip) {
			$('#vexpay_debtor_id_type').val(String($chip.data('id-type') || 'V'));
			$('#vexpay_debtor_id_number').val(String($chip.data('id-number') || ''));
			$('#vexpay_debtor_phone_prefix').val(String($chip.data('phone-prefix') || '0412'));
			$('#vexpay_debtor_phone_number').val(String($chip.data('phone-number') || ''));
			setBankByCode(String($chip.data('bank') || ''));
			markActive($chip);
			// Selecting a saved account collapses the form again.
			hideDetailsPanel();
		}

		$wrap.on('click', '[data-vexpay-account-select]', function (e) {
			e.preventDefault();
			selectChip($(this).closest('[data-vexpay-account]'));
		});

		$wrap.on('click', '[data-vexpay-account-new]', function (e) {
			e.preventDefault();
			clearFormFields();
			markActive(null);
			showDetailsPanel();
		});

		$wrap.on('click', '[data-vexpay-account-remove]', function (e) {
			e.preventDefault();
			e.stopPropagation();

			var $btn = $(this);
			var $chip = $btn.closest('[data-vexpay-account]');
			if (!$chip.length || $btn.prop('disabled')) {
				return;
			}
			if (!cfg.ajaxUrl || !deleteCfg.action || !deleteCfg.nonce) {
				return;
			}

			var wasActive = $chip.hasClass('is-active');
			$btn.prop('disabled', true);

			$.ajax({
				url: cfg.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: deleteCfg.action,
					nonce: deleteCfg.nonce,
					id_type: String($chip.data('id-type') || ''),
					id_number: String($chip.data('id-number') || ''),
					phone_prefix: String($chip.data('phone-prefix') || ''),
					phone_number: String($chip.data('phone-number') || ''),
					bank: String($chip.data('bank') || ''),
				},
			})
				.done(function (res) {
					if (!res || !res.success) {
						$btn.prop('disabled', false);
						window.alert(i18n.removeFailed || 'Could not remove saved account.');
						return;
					}

					$chip.remove();
					if (!$wrap.find('[data-vexpay-account]').length) {
						$wrap.remove();
						clearFormFields();
						showDetailsPanel();
						return;
					}
					if (wasActive) {
						clearFormFields();
						markActive(null);
						showDetailsPanel();
					}
				})
				.fail(function () {
					$btn.prop('disabled', false);
					window.alert(i18n.removeFailed || 'Could not remove saved account.');
				});
		});

		$('#vexpay_debtor_id_type, #vexpay_debtor_id_number, #vexpay_debtor_phone_prefix, #vexpay_debtor_phone_number, #vexpay_debtor_bank')
			.off('change.vexpayAccounts input.vexpayAccounts')
			.on('change.vexpayAccounts input.vexpayAccounts', function () {
				if ($details.is('[hidden]') || $details.prop('hidden')) {
					return;
				}
				markActive(null);
			});
	}

	function bindOtpPin(root) {
		var pin = (root || document).querySelector('[data-vexpay-otp-pin]');
		if (!pin || pin.getAttribute('data-vexpay-bound') === '1') {
			return;
		}
		pin.setAttribute('data-vexpay-bound', '1');

		var form = pin.closest('form');
		var hidden = form ? form.querySelector('#vexpay_token') : null;
		var cells = Array.prototype.slice.call(pin.querySelectorAll('.vexpay-otp-cell'));
		if (!hidden || !cells.length) {
			return;
		}

		function sync() {
			var value = '';
			for (var i = 0; i < cells.length; i += 1) {
				var digit = String(cells[i].value || '').replace(/\D/g, '');
				if (!digit) {
					break;
				}
				value += digit.charAt(0);
			}
			hidden.value = value;
			pin.classList.toggle('is-complete', value.length >= 6);
			pin.classList.remove('is-invalid');
		}

		function focusAt(index) {
			var cell = cells[Math.max(0, Math.min(index, cells.length - 1))];
			if (cell) {
				cell.focus();
				cell.select();
			}
		}

		function fillFrom(startIndex, digits) {
			var i = startIndex;
			var d = 0;
			while (i < cells.length && d < digits.length) {
				cells[i].value = digits[d];
				cells[i].classList.toggle('is-filled', true);
				i += 1;
				d += 1;
			}
			for (; i < cells.length; i += 1) {
				cells[i].value = '';
				cells[i].classList.remove('is-filled');
			}
			sync();
			if (d >= cells.length) {
				focusAt(cells.length - 1);
			} else {
				focusAt(startIndex + d);
			}
		}

		cells.forEach(function (cell, index) {
			cell.addEventListener('input', function () {
				var digits = String(cell.value || '').replace(/\D/g, '');
				if (!digits) {
					cell.value = '';
					cell.classList.remove('is-filled');
					sync();
					return;
				}
				if (digits.length > 1) {
					fillFrom(index, digits.slice(0, cells.length - index));
					return;
				}
				cell.value = digits.charAt(0);
				cell.classList.add('is-filled');
				sync();
				if (index < cells.length - 1) {
					focusAt(index + 1);
				}
			});

			cell.addEventListener('keydown', function (event) {
				if (event.key === 'Backspace') {
					if (cell.value) {
						cell.value = '';
						cell.classList.remove('is-filled');
						sync();
						event.preventDefault();
						return;
					}
					if (index > 0) {
						event.preventDefault();
						cells[index - 1].value = '';
						cells[index - 1].classList.remove('is-filled');
						sync();
						focusAt(index - 1);
					}
					return;
				}
				if (event.key === 'ArrowLeft' && index > 0) {
					event.preventDefault();
					focusAt(index - 1);
					return;
				}
				if (event.key === 'ArrowRight' && index < cells.length - 1) {
					event.preventDefault();
					focusAt(index + 1);
				}
			});

			cell.addEventListener('paste', function (event) {
				var text = (event.clipboardData || window.clipboardData).getData('text') || '';
				var digits = text.replace(/\D/g, '');
				if (!digits) {
					return;
				}
				event.preventDefault();
				fillFrom(0, digits.slice(0, cells.length));
			});

			cell.addEventListener('focus', function () {
				cell.select();
				pin.classList.add('is-focused');
			});

			cell.addEventListener('blur', function () {
				window.setTimeout(function () {
					if (!pin.contains(document.activeElement)) {
						pin.classList.remove('is-focused');
					}
				}, 0);
			});
		});

		if (form) {
			form.addEventListener('submit', function (event) {
				sync();
				if (!/^\d{6,8}$/.test(hidden.value)) {
					event.preventDefault();
					pin.classList.add('is-invalid');
					focusAt(Math.min(hidden.value.length, cells.length - 1));
				}
			});
		}

		focusAt(0);
	}

	function bindOtpResendCooldown(root) {
		var scope = root || document;
		var wait = scope.querySelector('[data-vexpay-otp-cooldown]');
		if (!wait || wait.getAttribute('data-vexpay-bound') === '1') {
			return;
		}
		wait.setAttribute('data-vexpay-bound', '1');

		var form = wait.closest('form');
		var button =
			(form && form.querySelector('[data-vexpay-otp-resend]')) ||
			scope.querySelector('[data-vexpay-otp-resend]');
		var left = parseInt(wait.getAttribute('data-vexpay-otp-cooldown'), 10);
		var template = wait.getAttribute('data-vexpay-otp-cooldown-template') || '';
		var idle = wait.getAttribute('data-vexpay-otp-cooldown-idle') || '';

		if (!left || left < 1 || !template) {
			return;
		}

		function render(seconds) {
			if (template.indexOf('%d') !== -1) {
				wait.textContent = template.replace('%d', String(seconds));
			} else {
				wait.textContent = template.replace(/\d+/, String(seconds));
			}
		}

		function finish() {
			if (button) {
				button.disabled = false;
				button.removeAttribute('disabled');
			}
			wait.classList.add('vexpay-otp-resend-wait--idle');
			wait.removeAttribute('data-vexpay-otp-cooldown');
			if (idle) {
				wait.textContent = idle;
			}
		}

		render(left);

		var timer = window.setInterval(function () {
			left -= 1;
			if (left <= 0) {
				window.clearInterval(timer);
				finish();
				return;
			}
			render(left);
		}, 1000);
	}

	$(function () {
		bindSplitFields();
		bindOtpPin(document);
		bindOtpResendCooldown(document);
		$(document.body).on('updated_checkout', bindSplitFields);

		$(document).on('submit', '.vexpay-otp-send-form, .vexpay-otp-resend-form, .vexpay-otp-form, .vexpay-change-account-form', function () {
			var $btn = $(this).find('button[type="submit"]').filter(':not(:disabled)').first();
			if ($btn.length) {
				$btn.prop('disabled', true).attr('aria-busy', 'true').addClass('is-loading');
			}
		});
	});
})(jQuery);
