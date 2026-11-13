import type { APIRoute, GetStaticPaths } from 'astro';
import medium from '@fontsource/gabarito/files/gabarito-latin-500-normal.woff?inline';
import semibold from '@fontsource/gabarito/files/gabarito-latin-600-normal.woff?inline';
import bold from '@fontsource/gabarito/files/gabarito-latin-700-normal.woff?inline';
import { Resvg } from '@resvg/resvg-js';
import { Buffer } from 'node:buffer';
import { readFile } from 'node:fs/promises';
import satori from 'satori';

const cards: { name: string; description: string; badge?: string }[] = [
	{ name: 'intro', description: 'Send emails with Lettermint.' },
	{ name: 'settings', description: 'Configure everything via email settings.' },
	{ name: 'utility', description: 'See everything at a glance on the utility page.', badge: 'Pro' },
	{ name: 'widget', description: 'Keep an eye on the status from the dashboard.', badge: 'Pro' },
];

// The bundled build moves `import.meta.url` into `dist/`, so resolve from the docs root instead.
const asset = (file: string) => `src/assets/${file}`;
const decode = (uri: string) => Buffer.from(uri.split(',')[1], 'base64');

export const getStaticPaths = (() =>
	cards.map(card => ({ params: { name: card.name }, props: card }))) satisfies GetStaticPaths;

const dataUri = (file: Buffer, type: string) => `data:${type};base64,${file.toString('base64')}`;

export const GET: APIRoute<(typeof cards)[number]> = async ({ props }) => {
	const logo = await readFile(asset('logo.svg'));
	const render = async (tree: object) => {
		const svg = await satori(tree as Parameters<typeof satori>[0], {
			fonts: [
				{ data: decode(medium), name: 'Gabarito', weight: 500 },
				{ data: decode(semibold), name: 'Gabarito', weight: 600 },
				{ data: decode(bold), name: 'Gabarito', weight: 700 },
			],
			height: 800,
			width: 1280,
		});

		return new Response(new Uint8Array(new Resvg(svg).render().asPng()), { headers: { 'Content-Type': 'image/png' } });
	};

	if (props.name === 'intro') {
		return render({
			type: 'div',
			props: {
				style: {
					alignItems: 'center',
					background: '#2d6a4f',
					color: '#f4f7f5',
					display: 'flex',
					flexDirection: 'column',
					height: '100%',
					justifyContent: 'center',
					textAlign: 'center',
					width: '100%',
				},
				children: [
					{
						type: 'div',
						props: {
							style: { alignItems: 'center', display: 'flex', gap: 40 },
							children: [
								{ type: 'img', props: { height: 160, src: dataUri(logo, 'image/svg+xml'), width: 160 } },
								{
									type: 'div',
									props: {
										style: { alignItems: 'flex-start', display: 'flex', flexDirection: 'column', fontSize: 72, fontWeight: 500, lineHeight: 1.1 },
										children: [
											{ type: 'div', props: { style: { fontWeight: 700 }, children: 'Lettermint' } },
											{ type: 'div', props: { children: 'for Craft CMS' } },
										],
									},
								},
							],
						},
					},
					{ type: 'div', props: { style: { fontSize: 44, fontWeight: 600, lineHeight: 1.15, marginTop: 48, whiteSpace: 'pre-line', width: 960 }, children: props.description } },
				],
			},
		});
	}

	const screenshot = await readFile(asset(`${props.name}.png`));

	// PNG header: width and height are big-endian uint32 at byte 16 and 20.
	const ratio = screenshot.readUInt32BE(16) / screenshot.readUInt32BE(20);
	const shotWidth = 1152;

	const dot = { background: '#c4cdc8', borderRadius: 999, height: 12, width: 12 };

	return render({
		type: 'div',
		props: {
			style: {
				alignItems: 'center',
				background: 'linear-gradient(180deg, #2d6a4f, #245a42)',
				color: '#f4f7f5',
				display: 'flex',
				flexDirection: 'column',
				height: '100%',
				paddingTop: 40,
				width: '100%',
			},
			children: [
				{
					type: 'div',
					props: {
						style: { alignItems: 'center', display: 'flex', fontSize: 24, gap: 16, justifyContent: 'center', width: shotWidth },
						children: [
							{ type: 'img', props: { height: 40, src: dataUri(logo, 'image/svg+xml'), width: 40 } },
							{
								type: 'div',
								props: {
									style: { display: 'flex', fontWeight: 500, gap: 7 },
									children: [
										{ type: 'div', props: { style: { fontWeight: 700 }, children: 'Lettermint' } },
										{ type: 'div', props: { children: 'for Craft CMS' } },
									],
								},
							},
						],
					},
				},
				{
					type: 'div',
					props: {
						style: { alignItems: 'center', display: 'flex', fontSize: 44, fontWeight: 600, gap: 20, lineHeight: 1.15, marginTop: 28 },
						children: [
							props.badge && {
								type: 'div',
								props: {
									style: {
										background: '#fff',
										borderRadius: 999,
										color: '#2d6a4f',
										fontSize: 24,
										fontWeight: 600,
										padding: '4px 18px',
									},
									children: props.badge,
								},
							},
							{ type: 'div', props: { children: props.description } },
						].filter(Boolean),
					},
				},
				{
					// A browser-like frame; tall screenshots are clipped at the bottom edge of the card.
					type: 'div',
					props: {
						style: {
							borderRadius: '14px 14px 0 0',
							display: 'flex',
							flex: 1,
							flexDirection: 'column',
							marginTop: 36,
							overflow: 'hidden',
							width: shotWidth,
						},
						children: [
							{
								type: 'div',
								props: {
									style: { alignItems: 'center', background: '#e8ece9', display: 'flex', flexShrink: 0, gap: 8, height: 36, paddingLeft: 16 },
									children: [dot, dot, dot].map(style => ({ type: 'div', props: { style } })),
								},
							},
							{
								type: 'img',
								props: {
									height: Math.round(shotWidth / ratio),
									src: dataUri(screenshot, 'image/png'),
									style: { flexShrink: 0 },
									width: shotWidth,
								},
							},
						],
					},
				},
			].filter(Boolean),
		},
	});
};
