export default {
	dictionaries: ['project'],
	dictionaryDefinitions: [
		{
			addWords: true,
			name: 'project',
			path: './dictionary.txt',
		},
	],
	ignorePaths: ['.astro/', '.void/', 'dist/'],
	import: ['@somehow-digital/cspell-dictionary'],
	language: 'en',
	version: '0.2',
};
