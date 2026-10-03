const { execFileSync } = require( 'child_process' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const fixture = require( '../fixtures/yoast-redirects-sample.json' );

const OPTIONS = [
	'wpseo-premium-redirects-base',
	'wpseo-premium-redirects-export-plain',
	'wpseo-premium-redirects-export-regex',
	'adv_redirects_yoast_backup',
];

// wp-cli on the tests site. wp-env prints status lines too; the command output is the last line.
function wp( ...args ) {
	const out = execFileSync(
		'npx',
		[ 'wp-env', 'run', 'tests-cli', 'wp', ...args ],
		{ encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] }
	);
	return out.trim().split( '\n' ).pop();
}

function clearYoast() {
	for ( const option of OPTIONS ) {
		try {
			wp( 'option', 'delete', option );
		} catch {
			// Not set.
		}
	}
}

function seedYoast() {
	wp(
		'option',
		'update',
		'wpseo-premium-redirects-base',
		JSON.stringify( fixture ),
		'--format=json'
	);
}

function yoastCount() {
	return JSON.parse(
		wp( 'option', 'get', 'wpseo-premium-redirects-base', '--format=json' )
	).length;
}

async function deleteAllRedirects( requestUtils ) {
	const rules = await requestUtils.rest( {
		path: '/adv-redirects/v1/redirects',
	} );
	for ( const rule of rules ) {
		await requestUtils.rest( {
			path: `/adv-redirects/v1/redirects/${ rule.id }`,
			method: 'DELETE',
		} );
	}
}

test.describe( 'Import from Yoast SEO Premium', () => {
	test.beforeEach( async ( { requestUtils, admin, page } ) => {
		await deleteAllRedirects( requestUtils );
		clearYoast();
		seedYoast();
		// Forget an earlier "Not now". Storage then persists across this test's navigations.
		await admin.visitAdminPage( 'admin.php', 'page=adv-redirects' );
		await page.evaluate( () => window.localStorage.clear() );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await deleteAllRedirects( requestUtils );
		clearYoast();
	} );

	test( 'imports, removes from Yoast and restores', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=adv-redirects' );
		await page.getByRole( 'tab', { name: 'Import' } ).click();
		// WordPress's a11y speak regions repeat notice text, so scope to the tab panel.
		const panel = page.getByRole( 'tabpanel', { name: 'Import' } );

		await expect(
			panel.getByText(
				'Yoast SEO Premium redirects found: 19 plain, 6 regex.'
			)
		).toBeVisible();
		await page
			.getByRole( 'button', { name: 'Preview Yoast import' } )
			.click();
		await expect(
			panel.getByRole( 'heading', { name: 'Preview: Yoast SEO Premium' } )
		).toBeVisible();
		await expect( page.getByText( 'New (16)' ) ).toBeVisible();
		await expect( page.getByText( 'Skipped (9)' ) ).toBeVisible();
		await expect( page.getByText( 'Superseded (1)' ) ).toBeVisible();
		await expect(
			panel.getByText( 'Yoast entry #2 already covers this source.' )
		).toBeAttached();

		await page
			.getByRole( 'button', { name: 'Import 16 redirects' } )
			.click();
		await expect(
			panel.getByText(
				/Import complete: 16 created, 0 updated, 9 skipped, 1 superseded\./
			)
		).toBeVisible();
		await expect(
			panel.getByText( /Remove 16 imported redirects from Yoast\?/ )
		).toBeVisible();

		await page.getByRole( 'button', { name: 'Remove from Yoast' } ).click();
		await expect(
			panel.getByText( 'Removed 16 redirects from Yoast.' )
		).toBeVisible();
		expect( yoastCount() ).toBe( 10 );
		await expect(
			panel.getByText( /16 redirects removed from Yoast, last on/ )
		).toBeVisible();

		await page.getByRole( 'button', { name: 'Restore to Yoast' } ).click();
		await page
			.getByRole( 'dialog' )
			.getByRole( 'button', { name: 'Restore to Yoast' } )
			.click();
		await expect(
			panel.getByText(
				'Restored 16 redirects to Yoast; 0 were already there.'
			)
		).toBeVisible();
		expect( yoastCount() ).toBe( 26 );
	} );

	test( '"Not now" hides the notice in this browser', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=adv-redirects' );
		await page.getByRole( 'tab', { name: 'Import' } ).click();
		const panel = page.getByRole( 'tabpanel', { name: 'Import' } );
		await page.getByRole( 'button', { name: 'Not now' } ).click();
		await expect(
			panel.getByText( /Yoast SEO Premium redirects found/ )
		).toHaveCount( 0 );

		// The notice renders nothing until the status request resolves, so wait for it.
		const isStatusResponse = ( response ) =>
			( response.url().includes( 'import/yoast' ) ||
				response.url().includes( 'import%2Fyoast' ) ) &&
			response.request().method() === 'GET';

		let statusLoaded = page.waitForResponse( isStatusResponse );
		await page.reload();
		await page.getByRole( 'tab', { name: 'Import' } ).click();
		await statusLoaded;
		await expect(
			page
				.getByRole( 'tabpanel', { name: 'Import' } )
				.getByText( /Yoast SEO Premium redirects found/ )
		).toHaveCount( 0 );

		// Control: without the stored choice the notice is back, so the check above can fail.
		await page.evaluate( () => window.localStorage.clear() );
		statusLoaded = page.waitForResponse( isStatusResponse );
		await page.reload();
		await page.getByRole( 'tab', { name: 'Import' } ).click();
		await statusLoaded;
		await expect(
			page
				.getByRole( 'tabpanel', { name: 'Import' } )
				.getByText( /Yoast SEO Premium redirects found/ )
		).toBeVisible();
	} );
} );
