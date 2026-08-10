/**
 * Polls the approval status on the "pending" login page.
 *
 * Configuration is provided by the enqueuing PHP via the global
 * GatalynPendingPoll object (see Gatalyn_Login::show_pending_page()):
 *   - checkUrl  {string} Endpoint returning the approval status as JSON.
 *   - requestId {string} Identifier of the approval request being polled.
 *   - strings   {object} Translated UI strings.
 */
(function () {
	'use strict';

	var config = window.GatalynPendingPoll || {};

	if (!config.checkUrl || !config.requestId) {
		return;
	}

	var statusNode = document.getElementById('gatalyn-pending-status');
	var strings = config.strings || {};
	var pollCount = 0;
	var maxPolls = 600; // 30 minutes limit
	var pendingTimeouts = [];
	var currentDelay = 3000; // Starting delay
	var maxDelay = 15000; // Maximum delay

	function debug(msg, data) {
		if (window.console && window.console.log) {
			console.log('[Gatalyn] ' + msg, data || '');
		}
	}

	function setStatus(text, color) {
		if (!statusNode) {
			return;
		}
		statusNode.textContent = text;
		statusNode.style.borderLeftColor = color;
		statusNode.style.color = color;
	}

	function schedulePoll(delay) {
		debug('Scheduling next poll in ' + (delay / 1000) + 's');
		pendingTimeouts.push(window.setTimeout(pollApproval, delay));
	}

	function handlePayload(payload) {
		if (!payload) {
			debug('Empty payload');
			schedulePoll(5000);
			return;
		}

		if (payload.success && payload.data) {
			debug('Status:', payload.data.status);

			if ('approved' === payload.data.status && payload.data.redirect_url) {
				debug('Approved! Redirecting to:', payload.data.redirect_url);
				window.location.href = payload.data.redirect_url;
				return;
			}

			if ('denied' === payload.data.status) {
				debug('Request denied');
				setStatus(payload.data.message || strings.denied, '#cc0000');
				return;
			}

			if ('pending' === payload.data.status) {
				schedulePoll(currentDelay);
				// Incremental backoff: increase delay for the next poll to reduce server load
				if (currentDelay < maxDelay) {
					currentDelay = Math.min(currentDelay + 2000, maxDelay);
				}
				return;
			}
		}

		debug('Unexpected payload:', payload);
		schedulePoll(currentDelay);
	}

	function pollApproval() {
		pollCount++;
		debug('Poll #' + pollCount + ' checking:', config.checkUrl);

		if (pollCount > maxPolls) {
			setStatus(strings.expired, '#ff9800');
			debug('Max polls reached (' + maxPolls + ')');
			return;
		}

		fetch(config.checkUrl, {
			method: 'GET',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: {
				'X-Requested-With': 'XMLHttpRequest'
			}
		})
			.then(function (response) {
				debug('Response received, status:', response.status);

				if (!response.ok) {
					throw new Error('HTTP error, status = ' + response.status);
				}
				return response.text();
			})
			.then(function (text) {
				try {
					var payload = JSON.parse(text);
					debug('JSON parsed:', payload);
					handlePayload(payload);
				} catch (e) {
					debug('JSON parse error:', e.message);
					debug('Response was:', text.substring(0, 200));
					schedulePoll(5000);
				}
			})
			.catch(function (err) {
				debug('Fetch error:', err.message);
				schedulePoll(5000);
			});
	}

	debug('Initialized polling for request_id:', config.requestId);
	schedulePoll(2000);

	window.addEventListener('beforeunload', function () {
		debug('Cleaning up ' + pendingTimeouts.length + ' timeouts');
		pendingTimeouts.forEach(function (timeout) {
			window.clearTimeout(timeout);
		});
	});
}());
