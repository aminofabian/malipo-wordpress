(function () {
	"use strict";

	var cfg = window.MALIPO || {};
	var strings = cfg.strings || {};

	function setStatus(el, kind, text) {
		if (!el) {
			return;
		}
		el.setAttribute("data-kind", kind);
		el.textContent = text;
	}

	function poll(el, url, tries) {
		if (tries > 80) {
			setStatus(el, "failed", strings.failed || "The payment did not complete.");
			return;
		}

		fetch(url, { headers: { Accept: "application/json" } })
			.then(function (res) {
				return res.json();
			})
			.then(function (data) {
				if (data && data.status === "settled") {
					var text = strings.paid || "Payment received.";
					if (data.receipt) {
						text += " " + data.receipt;
					}
					setStatus(el, "settled", text);
					return;
				}
				if (data && data.status === "failed") {
					setStatus(el, "failed", data.failure_message || strings.failed || "The payment did not complete.");
					return;
				}
				window.setTimeout(function () {
					poll(el, url, tries + 1);
				}, 3000);
			})
			.catch(function () {
				window.setTimeout(function () {
					poll(el, url, tries + 1);
				}, 3000);
			});
	}

	document.querySelectorAll("[data-malipo-form]").forEach(function (form) {
		var statusEl = form.querySelector("[data-malipo-status]");
		var button = form.querySelector("button[type='submit']");
		var submitting = false;

		form.addEventListener("submit", function (event) {
			event.preventDefault();
			if (submitting) {
				return;
			}
			submitting = true;
			if (button) {
				button.disabled = true;
			}

			setStatus(statusEl, "pending", strings.waiting || "…");

			var phoneEl = form.querySelector("[name='phone']");
			var amountEl = form.querySelector("[name='amount']");
			var referenceEl = form.querySelector("[name='reference']");

			var payload = {
				nonce: cfg.nonce || "",
				phone: phoneEl ? phoneEl.value : "",
				amount: amountEl ? amountEl.value : "",
				reference: referenceEl ? referenceEl.value : "",
			};

			fetch(cfg.payUrl || "/wp-json/malipo/v1/pay", {
				method: "POST",
				headers: { "Content-Type": "application/json", "X-WP-Nonce": cfg.nonce || "" },
				body: JSON.stringify(payload),
			})
				.then(function (res) {
					return res.json().then(function (data) {
						return { ok: res.ok, data: data };
					});
				})
				.then(function (result) {
					if (!result.ok || !result.data || !result.data.token) {
						setStatus(statusEl, "failed", (result.data && result.data.message) || strings.error || "Something went wrong.");
						submitting = false;
						if (button) {
							button.disabled = false;
						}
						return;
					}
					poll(statusEl, (cfg.statusUrl || "/wp-json/malipo/v1/status/") + result.data.token, 0);
				})
				.catch(function () {
					setStatus(statusEl, "failed", strings.error || "Something went wrong.");
					submitting = false;
					if (button) {
						button.disabled = false;
					}
				});
		});
	});

	document.querySelectorAll("[data-malipo-poll]").forEach(function (el) {
		var url = el.getAttribute("data-status-url");
		if (url) {
			poll(el, url, 0);
		}
	});
})();
