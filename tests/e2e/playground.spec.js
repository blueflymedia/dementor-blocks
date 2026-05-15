const { test, expect } = require( '@playwright/test' );
const { runCLI } = require( '@wp-playground/cli' );

let cli;

test.beforeAll( async () => {
	cli = await runCLI( {
		command: 'server',
		php: '8.4',
		wp: 'latest',
		port: 9500,
		mount: [
			{
				hostPath: './',
				vfsPath: '/wordpress/wp-content/plugins/dementor-blocks',
			},
		],
		blueprint: {
			login: true,
			steps: [
				{
					step: 'runPHP',
					code: `<?php
require '/wordpress/wp-load.php';

update_option('active_plugins', ['dementor-blocks/dementor-blocks.php']);

$post_id = wp_insert_post([
	'post_type' => 'page',
	'post_status' => 'publish',
	'post_title' => 'Playground Elementor Fixture',
	'post_content' => '',
]);

update_post_meta($post_id, '_elementor_data', wp_json_encode([
	[
		'id' => 'section1',
		'elType' => 'section',
		'settings' => [],
		'elements' => [
			[
				'id' => 'column1',
				'elType' => 'column',
				'settings' => [],
				'elements' => [
					[
						'id' => 'heading1',
						'elType' => 'widget',
						'widgetType' => 'heading',
						'settings' => [
							'title' => 'Playground Migration',
							'header_size' => 'h2',
						],
					],
					[
						'id' => 'text1',
						'elType' => 'widget',
						'widgetType' => 'text-editor',
						'settings' => [
							'editor' => '<p>Seeded by WordPress Playground.</p>',
						],
					],
				],
			],
		],
	],
]));
`,
				},
			],
		},
	} );
} );

test.afterAll( async () => {
	if ( cli ) {
		if ( cli[ Symbol.asyncDispose ] ) {
			await cli[ Symbol.asyncDispose ]();
		} else if ( cli.server?.close ) {
			await cli.server.close();
		}
	}
} );

test( 'Dementor Blocks admin screen loads in WordPress Playground', async ( {
	page,
} ) => {
	await page.goto(
		`${ cli.serverUrl }/wp-admin/tools.php?page=dementor-blocks`
	);

	await expect(
		page.getByRole( 'heading', { name: 'Dementor Blocks' } )
	).toBeVisible();
	await expect(
		page.getByText( 'Playground Elementor Fixture' )
	).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'Audit selected' } )
	).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'Convert selected' } )
	).toBeVisible();
} );
