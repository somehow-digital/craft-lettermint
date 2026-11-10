import { defineConfig } from 'vite-plus';

export default defineConfig({
	staged: {
		'docs/**/*.*': [
			'vp exec --filter docs eslint',
			'vp exec --filter docs cspell --no-progress --no-summary --no-must-find-files',
		],
	},
});
