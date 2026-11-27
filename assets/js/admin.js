/**
 * iWorks.ie Migrate admin screen (vanilla JS, no build step).
 */
(function () {
	'use strict';

	if (typeof window.iwiemgAdmin === 'undefined') {
		return;
	}

	var cfg = window.iwiemgAdmin;
	var s = cfg.strings || {};
	var running = false;
	var cancelRequested = false;

	function post(action, data, nonce) {
		var body = new window.FormData();
		body.append('action', action);
		body.append('nonce', nonce || cfg.nonce);
		Object.keys(data || {}).forEach(function (key) {
			var value = data[key];
			body.append(key, value !== null && typeof value === 'object' ? JSON.stringify(value) : value);
		});
		return window
			.fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (res) {
				return res.json().catch(function () {
					return { success: false, data: { message: s.failedRequest } };
				});
			});
	}

	function $(sel, root) {
		return (root || document).querySelector(sel);
	}

	function show(el, visible) {
		if (el) {
			el.hidden = !visible;
		}
	}

	function wait(ms) {
		return new Promise(function (resolve) {
			window.setTimeout(resolve, ms);
		});
	}

	function notice(message) {
		var box = document.getElementById('iwt-notices');
		if (!box) {
			return;
		}
		box.innerHTML = '';
		var div = document.createElement('div');
		div.className = 'notice notice-error iwt-notice';
		var p = document.createElement('p');
		p.textContent = message;
		div.appendChild(p);
		box.appendChild(div);
	}

	function setProgress(progress, message) {
		var root = $('[data-iwt-progress="iwiemg-pull"]');
		if (root) {
			root.classList.remove('is-idle');
		}
		if (window.iwtCoreUi && window.iwtCoreUi.setProgress) {
			window.iwtCoreUi.setProgress('iwiemg-pull', progress, message);
		}
	}

	/* ------------------------------------------------------------------ */
	/* Receiving side                                                      */
	/* ------------------------------------------------------------------ */

	function bindPreflight() {
		var btn = $('[data-iwiemg-preflight]');
		if (!btn) {
			return;
		}
		btn.addEventListener('click', function () {
			var field = document.getElementById('iwiemg-key');
			var key = field ? field.value.trim() : '';
			var out = $('[data-iwiemg-preflight-results]');
			if (!key) {
				notice(s.pasteKey);
				if (field) {
					field.focus();
				}
				return;
			}
			btn.disabled = true;
			btn.textContent = s.checking;
			post('iwiemg_preflight', { key: key })
				.then(function (json) {
					if (!json || !json.success) {
						notice((json && json.data && json.data.message) || s.failedRequest);
						return;
					}
					// Server-rendered, escaped result cards from core UI.
					out.innerHTML = json.data.html;
					if (window.iwtCoreUi && window.iwtCoreUi.bindAccordions) {
						window.iwtCoreUi.bindAccordions(out);
					}
					show($('[data-iwiemg-step2]'), !!json.data.ok);
					if (json.data.ok) {
						setToolbar('idle');
					}
				})
				.catch(function () {
					notice(s.failedRequest);
				})
				.finally(function () {
					btn.disabled = false;
					btn.textContent = s.check;
				});
		});

		var forget = $('[data-iwiemg-forget]');
		if (forget) {
			forget.addEventListener('click', function () {
				post('iwiemg_forget_key', {}).then(function (json) {
					if (!json || !json.success) {
						notice((json && json.data && json.data.message) || s.failedRequest);
						return;
					}
					show($('[data-iwiemg-connected]'), false);
					show($('[data-iwiemg-key-form]'), true);
					show($('[data-iwiemg-step2]'), false);
					var field = document.getElementById('iwiemg-key');
					if (field) {
						field.focus();
					}
				});
			});
		}
	}

	function setToolbar(mode) {
		var bar = $('[data-iwiemg-toolbar]');
		if (!bar) {
			return;
		}
		bar.setAttribute('data-mode', mode);
		show($('[data-iwiemg-start]'), mode === 'idle');
		show($('[data-iwiemg-resume]'), mode === 'resume');
		show($('[data-iwiemg-cancel]'), mode === 'running' || mode === 'resume');
		var opts = $('[data-iwiemg-options]');
		if (opts) {
			opts.disabled = mode !== 'idle';
		}
	}

	function options() {
		var out = {};
		document.querySelectorAll('[data-iwiemg-options] input[type=checkbox]').forEach(function (el) {
			out[el.name] = el.checked ? '1' : '0';
		});
		return out;
	}

	function updateStats(status) {
		var dl = $('[data-iwiemg-stats]');
		if (!dl || !status || !status.stats) {
			return;
		}
		dl.hidden = false;
		Object.keys(status.stats).forEach(function (key) {
			var dd = dl.querySelector('[data-stat="' + key + '"]');
			if (dd) {
				dd.textContent = status.stats[key];
			}
		});
	}

	function showOutcome(status) {
		var box = $('[data-iwiemg-outcome]');
		if (!box || !status || !status.headline) {
			return;
		}
		box.innerHTML = '';
		var wrap = document.createElement('div');
		wrap.className = 'iwiemg-outcome__box iwiemg-outcome__box--' + status.status;
		var strong = document.createElement('strong');
		strong.textContent = status.headline;
		wrap.appendChild(strong);
		if (status.failed) {
			var p = document.createElement('p');
			p.textContent = status.failed;
			wrap.appendChild(p);
		}
		var p2 = document.createElement('p');
		var a = document.createElement('a');
		a.className = 'button';
		a.href = window.location.pathname + '?page=iworksie-migrate&tab=report';
		a.textContent = s.viewReport;
		p2.appendChild(a);
		wrap.appendChild(p2);
		box.appendChild(wrap);
	}

	function finish(status) {
		running = false;
		updateStats(status);
		if (status.cancelled) {
			setProgress(status.progress || 0, s.cancelled);
			post('iwiemg_pull_cleanup', {});
			setToolbar('idle');
			return;
		}
		if (status.paused) {
			setProgress(status.progress || 0, s.paused);
			setToolbar('resume');
			return;
		}
		setProgress(100, status.message || '');
		showOutcome(status);
		setToolbar('idle');
	}

	function loop() {
		if (cancelRequested) {
			return post('iwiemg_job_cancel', { job_id: cfg.jobId }, cfg.jobsNonce).then(function () {
				return post('iwiemg_pull_status', {}).then(function (json) {
					finish((json && json.data) || { cancelled: true });
				});
			});
		}
		return post('iwiemg_job_step', { job_id: cfg.jobId }, cfg.jobsNonce)
			.then(function (json) {
				if (!json || !json.success) {
					throw new Error('step');
				}
				var state = json.data || {};
				setProgress(state.progress || 0, state.message || '');
				return post('iwiemg_pull_status', {}).then(function (st) {
					var status = (st && st.data) || {};
					updateStats(status);
					if (status.done || status.cancelled || status.paused) {
						finish(status);
						return null;
					}
					var delay = status.net_failures ? Math.min(30000, 2000 * Math.pow(2, status.net_failures - 1)) : 0;
					return wait(delay).then(loop);
				});
			})
			.catch(function () {
				// This site (not the other one) failed to answer: treat as a pause.
				running = false;
				setProgress(0, s.failedRequest);
				setToolbar('resume');
			});
	}

	function bindJob() {
		var start = $('[data-iwiemg-start]');
		var resume = $('[data-iwiemg-resume]');
		var cancel = $('[data-iwiemg-cancel]');

		if (start) {
			start.addEventListener('click', function () {
				if (running) {
					return;
				}
				running = true;
				cancelRequested = false;
				$('[data-iwiemg-outcome]').innerHTML = '';
				setToolbar('running');
				setProgress(0, s.starting);
				post('iwiemg_job_start', { job_id: cfg.jobId, params: options() }, cfg.jobsNonce)
					.then(function (json) {
						if (!json || !json.success) {
							throw new Error('start');
						}
						var state = json.data || {};
						setProgress(state.progress || 0, state.message || '');
						if (state.done) {
							return post('iwiemg_pull_status', {}).then(function (st) {
								finish((st && st.data) || {});
							});
						}
						return loop();
					})
					.catch(function () {
						running = false;
						notice(s.failedRequest);
						setToolbar('idle');
					});
			});
		}

		if (resume) {
			resume.addEventListener('click', function () {
				if (running) {
					return;
				}
				running = true;
				cancelRequested = false;
				setToolbar('running');
				loop();
			});
		}

		if (cancel) {
			cancel.addEventListener('click', function () {
				if (!window.confirm(s.confirmCancel)) {
					return;
				}
				if (running) {
					cancelRequested = true;
					return;
				}
				post('iwiemg_job_cancel', { job_id: cfg.jobId }, cfg.jobsNonce).then(function () {
					finish({ cancelled: true });
				});
			});
		}
	}

	/* ------------------------------------------------------------------ */
	/* Giving side                                                         */
	/* ------------------------------------------------------------------ */

	function bindGive() {
		var create = $('[data-iwiemg-key-create]');
		var revoke = $('[data-iwiemg-key-revoke]');
		var output = $('[data-iwiemg-key-output]');
		var status = $('[data-iwiemg-key-status]');

		if (create) {
			create.addEventListener('click', function () {
				var lifetime = document.getElementById('iwiemg-lifetime');
				create.disabled = true;
				create.textContent = s.creating;
				post('iwiemg_key_create', { lifetime: lifetime ? lifetime.value : '24h' })
					.then(function (json) {
						if (!json || !json.success) {
							notice((json && json.data && json.data.message) || s.failedRequest);
							return;
						}
						var pre = document.getElementById('iwiemg-key-value');
						pre.textContent = json.data.key;
						show(output, true);
						show(revoke, true);
						status.textContent = s.keyActive + ' ' + json.data.expires + '.';
						pre.focus();
						var range = document.createRange();
						range.selectNodeContents(pre);
						var sel = window.getSelection();
						sel.removeAllRanges();
						sel.addRange(range);
					})
					.catch(function () {
						notice(s.failedRequest);
					})
					.finally(function () {
						create.disabled = false;
						create.textContent = s.replaceKey;
					});
			});
		}

		if (revoke) {
			revoke.addEventListener('click', function () {
				if (!window.confirm(s.confirmRevoke)) {
					return;
				}
				post('iwiemg_key_revoke', {}).then(function (json) {
					if (!json || !json.success) {
						notice(s.failedRequest);
						return;
					}
					show(output, false);
					show(revoke, false);
					document.getElementById('iwiemg-key-value').textContent = '';
					status.textContent = s.keyRevoked;
					if (create) {
						create.textContent = s.createKey;
					}
				});
			});
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		bindPreflight();
		bindJob();
		bindGive();
	});
})();
