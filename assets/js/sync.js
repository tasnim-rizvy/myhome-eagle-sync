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
		function step() {
			$.post(MES.ajaxUrl, { action: 'mes_import_batch', nonce: MES.nonce })
				.done(function (res) {
					if (!res || !res.success) {
						setStatus(res && res.data && res.data.message ? res.data.message : 'Sync failed.', true);
						setSpinner(false);
						$('#mes-start-sync').prop('disabled', false);
						return;
					}

					var data = res.data;
					if (data.phase === 'running') {
						var msg = 'Checking, importing and updating... ' + data.processed + '/' + data.total;
						if (data.images_total && data.images_total > 0) {
							msg += ' (images ' + data.images_done + '/' + data.images_total + ')';
						}
						setStatus(msg);
						setSpinner(true);
						setTimeout(step, 1000);
					} else if (data.phase === 'done') {
						setStatus(data.processed + '/' + data.processed + ' completed');
						setSpinner(false);
						setTimeout(function () { window.location.reload(); }, 1500);
					}
				})
				.fail(function () {
					setStatus('Request failed. Check the log for details.', true);
					setSpinner(false);
					$('#mes-start-sync').prop('disabled', false);
				});
		}

		step();
	}
})(jQuery);