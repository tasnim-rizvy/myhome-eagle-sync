(function ($) {
	'use strict';

	function setStatus(msg, isError) {
		var $status = $('#mes-status');
		$status.prop('hidden', false);
		$status.toggleClass('mes-status-error', !!isError);
		$status.text(msg);
	}

	function setSpinner(show) {
		$('#mes-spinner').prop('hidden', !show);
	}

	$(document).on('click', '#mes-start-sync', function () {
		$('#mes-start-sync').prop('disabled', true);
		setSpinner(true);
		setStatus('Counting listings…');
		runBatch();
	});

	function runBatch() {
		var transientFailures = 0;

		function step() {
			$.ajax({
				url: MES.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				timeout: 65000,
				data: { action: 'mes_import_batch', nonce: MES.nonce }
			})
				.done(function (res) {
					transientFailures = 0;
					if (!res || !res.success) {
						setStatus(res && res.data && res.data.message ? res.data.message : 'Sync failed.', true);
						setSpinner(false);
						$('#mes-start-sync').prop('disabled', false);
						return;
					}

					var data = res.data;
					if (data.phase === 'running') {
						var msg = data.message || ('Checking, importing and updating... ' + data.processed + '/' + data.total);
						if (data.images_total && data.images_total > 0) {
							msg += ' (images ' + data.images_done + '/' + data.images_total + ')';
						}
						setStatus(msg);
						setSpinner(true);
						setTimeout(step, Math.max(1000, Number(data.retry_after || 1) * 1000));
					} else if (data.phase === 'done') {
						setStatus(data.processed + '/' + data.processed + ' completed');
						setSpinner(false);
						setTimeout(function () { window.location.reload(); }, 1500);
					} else {
						setStatus(data.message || 'The server returned an unexpected sync state.', true);
						setSpinner(false);
						$('#mes-start-sync').prop('disabled', false);
					}
				})
				.fail(function (xhr) {
					var message = 'Request failed';
					var status = xhr ? Number(xhr.status || 0) : 0;
					var transient = [0, 408, 429, 500, 502, 503, 504].indexOf(status) !== -1;
					if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						message = xhr.responseJSON.data.message;
					} else if (xhr && xhr.status) {
						message += ' (HTTP ' + xhr.status + ')';
					}
					if ([502, 503, 504].indexOf(status) !== -1) {
						message += '. The hosting server ended this batch before it completed';
					}

					if (transient && transientFailures < 6) {
						transientFailures++;
						var retryDelay = Math.min(30000, Math.pow(2, transientFailures - 1) * 1000);
						setStatus(message + '. Resuming safely in ' + Math.round(retryDelay / 1000) + ' second(s)…');
						setSpinner(true);
						setTimeout(step, retryDelay);
						return;
					}

					setStatus(message + '. Check Eagle Sync → Log for details, then click Update Home Listings again to resume safely.', true);
					setSpinner(false);
					$('#mes-start-sync').prop('disabled', false);
				});
		}

		step();
	}
})(jQuery);
