// Starlight doesn't ship types for its virtual modules.
declare module 'virtual:starlight/user-config' {
	const config: import('@astrojs/starlight/types').StarlightConfig;
	export default config;
}

declare module 'virtual:starlight/user-images' {
	type ImageMetadata = import('astro').ImageMetadata;
	export const logos: { dark?: ImageMetadata; light?: ImageMetadata };
}
