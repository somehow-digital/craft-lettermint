export default {
	dictionaries: ['project'],
	dictionaryDefinitions: [
		{
			addWords: true,
			name: 'project',
			path: './docs/dictionary.txt',
		},
	],
	ignorePaths: ['docs/.astro/', 'docs/.void/', 'docs/dist/'],
	import: ['@somehow-digital/cspell-dictionary'],
	language: 'en',
	version: '0.2',
};
