import { defineConfig } from 'vite-plus';

export default defineConfig({
	run: {
		tasks: {
			deploy: {
				cache: false,
				command: 'void deploy --dir dist --platform cloudflare',
				dependsOn: ['build'],
			},
		},
	},
});
