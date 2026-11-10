import { defineConfig } from 'void/config';

export default defineConfig({
	cloudflare: {
		compatibility_date: '2026-11-01',
		name: 'craft-lettermint',
		routes: [{ custom_domain: true, pattern: 'craft-lettermint.somehow-digital.dev' }],
	},
});
