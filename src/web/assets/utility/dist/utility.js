/**
 * Loads the data of the Lettermint utility and widget, so their pages do not wait for Lettermint.
 *
 * The pages show the data they have right away, or placeholder data as a skeleton. The actions return the parts of the
 * page by name, which replace the elements of the same `data-lettermint-part`. A GET request loads the data unless it is
 * fresh, and a POST request from a refresh button loads it again in any case.
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
	 * Shows the error in place of the skeletons, or keeps the data that was shown.
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
