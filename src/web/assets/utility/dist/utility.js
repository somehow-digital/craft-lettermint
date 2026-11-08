/**
 * Loads the utility and widget data asynchronously.
 *
 * Returned parts replace the elements with the same `data-lettermint-part`. GET loads stale data; POST (refresh)
 * always reloads.
 */
(() => {
	const request = (method, action) => Craft.sendActionRequest(method, action).then(({data}) => {
		for (const [part, html] of Object.entries(data)) {
			const element = document.querySelector(`[data-lettermint-part="${part}"]`);

			if (element) {
				element.outerHTML = html;
			}
		}
	});

	/**
	 * Replaces skeletons with the error, and keeps any shown data.
	 */
	const fail = () => {
		for (const element of document.querySelectorAll('[data-lettermint-part]')) {
			element.removeAttribute('aria-busy');

			if (element.hasAttribute('data-lettermint-placeholder')) {
				const error = element.querySelector('template[data-lettermint-error]');

				element.classList.remove('lettermint-skeleton');
				element.replaceChildren(...(error ? [error.content.cloneNode(true)] : []));
			}
		}
	};

	window.LettermintUtility = {
		load(part, action) {
			if (document.querySelector(`[data-lettermint-part="${part}"]`)) {
				request('GET', action).catch(fail);
			}
		},
	};

	document.addEventListener('click', (event) => {
		const button = event.target.closest('[data-lettermint-refresh]');

		if (!button || button.disabled) {
			return;
		}

		button.disabled = true;
		button.setAttribute('aria-busy', 'true');

		request('POST', button.dataset.lettermintRefresh)
			.catch(() => Craft.cp.displayError(Craft.t('lettermint', 'This could not be loaded from Lettermint.')))
			.finally(() => {
				button.disabled = false;
				button.removeAttribute('aria-busy');
			});
	});
})();
