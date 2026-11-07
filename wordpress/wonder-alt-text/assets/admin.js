/* global WonderAlt, jQuery */
(function ($) {
	'use strict';

	var t = WonderAlt.i18n;

	function post(action, data) {
		return $.post(WonderAlt.ajax, $.extend({ action: 'wonder_alt_' + action, nonce: WonderAlt.nonce }, data || {}));
	}

	function errorText(xhr) {
		var r = xhr && xhr.responseJSON;
		return (r && r.data && r.data.message) || (xhr && xhr.statusText) || 'Error';
	}

	/* ---------- Backfill dashboard ---------- */

	var running = false;
	var $start = $('#wonder-alt-start');
	var $stop = $('#wonder-alt-stop');
	var $progress = $('#wonder-alt-progress');
	var $log = $('#wonder-alt-log');

	function logResult(r) {
		var $li = $('<li/>');
		if (r.thumb) {
			$li.append($('<img alt="" width="48" height="48"/>').attr('src', r.thumb));
		}
		var $body = $('<div/>');
		$body.append($('<strong/>').text(r.title || '#' + r.id));
		if (r.error) {
			$li.addClass('is-error');
			$body.append($('<p/>').text(t.failed + ': ' + r.error));
		} else {
			var meta = r.mode + (r.personas ? ' · ' + r.personas : '') + ' · ' + (r.status === 'queued' ? t.queued : t.applied);
			$body.append($('<p/>').text(r.alt));
			$body.append($('<p class="description"/>').text(meta));
		}
		$li.append($body);
		$log.prepend($li);
	}

	function step() {
		if (!running) {
			return;
		}
		post('batch')
			.done(function (res) {
				if (!res.success) {
					finish(errorText({ responseJSON: res }));
					return;
				}
				$.each(res.data.results, function (_, r) { logResult(r); });
				$('#wonder-alt-todo').text(res.data.remaining);
				$progress.text(res.data.remaining + ' ' + t.remaining);
				if (res.data.done) {
					finish(t.done);
				} else {
					step();
				}
			})
			.fail(function (xhr) {
				finish(errorText(xhr));
			});
	}

	function finish(message) {
		running = false;
		$start.prop('disabled', false).text($start.data('label'));
		$stop.prop('hidden', true);
		$progress.text(message);
	}

	$start.data('label', $start.text()).on('click', function () {
		running = true;
		$start.prop('disabled', true).text(t.working);
		$stop.prop('hidden', false);
		step();
	});

	$stop.on('click', function () {
		running = false;
		$progress.text(t.stopped);
	});

	$('#wonder-alt-clear-errors').on('click', function () {
		post('clear_errors').always(function () { window.location.reload(); });
	});

	$('#wonder-alt-approve-all').on('click', function () {
		if (!window.confirm(t.confirm)) {
			return;
		}
		var $b = $(this).prop('disabled', true);
		post('approve_all').always(function () { window.location.reload(); }).fail(function () { $b.prop('disabled', false); });
	});

	/* ---------- Review queue ---------- */

	$(document).on('click', '.wonder-alt-queue [data-review]', function () {
		var $btn = $(this);
		var $row = $btn.closest('tr');
		var task = $btn.data('review');
		var data = { id: $row.data('id'), task: task };
		if (task === 'approve') {
			data.alt = $row.find('textarea').val();
		}
		$row.find('button').prop('disabled', true);
		post('review', data)
			.done(function (res) {
				if (!res.success) {
					window.alert(errorText({ responseJSON: res }));
					$row.find('button').prop('disabled', false);
					return;
				}
				if (task === 'regenerate' && res.data) {
					$row.find('textarea').val(res.data.alt);
					$row.find('.description').text(res.data.mode + (res.data.personas ? ' · ' + res.data.personas : ''));
					$row.find('button').prop('disabled', false);
				} else {
					$row.fadeOut(200, function () { $row.remove(); });
				}
			})
			.fail(function (xhr) {
				window.alert(errorText(xhr));
				$row.find('button').prop('disabled', false);
			});
	});

	/* ---------- Media modal and attachment edit screen ---------- */

	function altField($from) {
		var $scope = $from.closest('.attachment-details, .media-modal, form#post');
		var $f = $scope.find('textarea[data-setting="alt"], input[data-setting="alt"], #attachment_alt').first();
		if (!$f.length) {
			$f = $('textarea[data-setting="alt"], #attachment_alt').first();
		}
		return $f;
	}

	$(document).on('click', '.wonder-alt-generate', function () {
		var $btn = $(this);
		var $wrap = $btn.closest('.wonder-alt-field');
		var $status = $wrap.find('.wonder-alt-status');
		$btn.prop('disabled', true).text(t.working);
		$status.text('');
		post('generate', {
			id: $wrap.data('id'),
			mode: $wrap.find('.wonder-alt-mode').val(),
			persona: $wrap.find('.wonder-alt-persona').val()
		})
			.done(function (res) {
				if (!res.success) {
					$status.text(errorText({ responseJSON: res }));
					return;
				}
				var $alt = altField($btn);
				if ($alt.length) {
					// Changing the field lets WordPress save it the usual way, so a person always sees it first.
					$alt.val(res.data.alt).trigger('change').trigger('input').focus();
					$status.text(res.data.mode + (res.data.personas ? ' · ' + res.data.personas : '') + '. ' + t.review);
				} else {
					$status.text(res.data.alt);
				}
			})
			.fail(function (xhr) { $status.text(errorText(xhr)); })
			.always(function () { $btn.prop('disabled', false).text(t.generate); });
	});
}(jQuery));
