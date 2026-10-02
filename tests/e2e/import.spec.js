const path = require( 'path' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const FIXTURE = path.join(
	__dirname,
	'..',
	'fixtures',
	'redirection-export-sample.json'
);

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

test.describe( 'Import from Redirection', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await deleteAllRedirects( requestUtils );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await deleteAllRedirects( requestUtils );
	} );

	test( 'checks, previews and imports a Redirection export', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=adv-redirects' );
		await page.getByRole( 'tab', { name: 'Import' } ).click();
		// WordPress's a11y speak regions repeat notice text, so scope to the tab panel.
		const panel = page.getByRole( 'tabpanel', { name: 'Import' } );

		await page
			.getByLabel( 'Redirection export file' )
			.setInputFiles( FIXTURE );
		await expect(
			page.getByText( '21 (18 exact, 3 regex)' )
		).toBeVisible();

		await page.getByRole( 'button', { name: 'Preview import' } ).click();
		await expect( page.getByText( 'New (13)' ) ).toBeVisible();
		await expect( page.getByText( 'Skipped (7)' ) ).toBeVisible();
		await expect( page.getByText( 'Superseded (1)' ) ).toBeVisible();
		// The first enabled duplicate (#9) is the one Redirection served.
		await expect(
			panel.getByText( /Redirection used entry #9 for this source/ )
		).toBeAttached();
		// Preview tables carry (visually hidden) header cells.
		await expect(
			panel.getByRole( 'columnheader', { name: 'Source' } ).first()
		).toBeAttached();
		await expect(
			panel.getByRole( 'columnheader', { name: 'Result' } ).first()
		).toBeAttached();

		await page
			.getByRole( 'button', { name: 'Import 13 redirects' } )
			.click();
		await expect(
			panel.getByText(
				/Import complete: 13 created, 0 updated, 7 skipped, 1 superseded\./
			)
		).toBeVisible();

		await page.getByRole( 'button', { name: 'View redirects' } ).click();
		// The button hides with the Import pane, so focus moves to the tab it opened.
		await expect(
			page.locator( '#adv-redirects-tab-redirects' )
		).toBeFocused();
		// The source cell's accessible name also holds the imported title, and
		// /fx-old-page/ is another rule's target, so find the rule by its row.
		const row = page.getByRole( 'row' ).filter( {
			has: page.getByRole( 'checkbox', { name: 'Select /fx-old-page/' } ),
		} );
		await expect( row.locator( 'td code' ).first() ).toHaveText(
			'/fx-old-page/'
		);
	} );

	test( 'moves focus to the result and downloads the report', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=adv-redirects' );
		await page.getByRole( 'tab', { name: 'Import' } ).click();
		// WordPress's a11y speak regions repeat notice text, so scope to the tab panel.
		const panel = page.getByRole( 'tabpanel', { name: 'Import' } );
		await page
			.getByLabel( 'Redirection export file' )
			.setInputFiles( FIXTURE );
		await page.getByRole( 'button', { name: 'Preview import' } ).click();
		await page
			.getByRole( 'button', { name: 'Import 13 redirects' } )
			.click();

		const result = panel.getByText( /Import complete: 13 created/ );
		await expect( result ).toBeVisible();
		await expect( page.locator( ':focus' ) ).toContainText(
			'Import complete'
		);

		const downloadPromise = page.waitForEvent( 'download' );
		await page.getByRole( 'button', { name: 'Download report' } ).click();
		const download = await downloadPromise;
		expect( download.suggestedFilename() ).toBe(
			'wp-redirects-import-report.json'
		);

		// "Import another file" unmounts the focused button; focus goes to "Choose file".
		await page
			.getByRole( 'button', { name: 'Import another file' } )
			.click();
		await expect(
			page.getByRole( 'button', { name: 'Choose file' } )
		).toBeFocused();
	} );

	test( 'rejects a file that is not a Redirection export', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=adv-redirects' );
		await page.getByRole( 'tab', { name: 'Import' } ).click();
		// WordPress's a11y speak regions repeat notice text, so scope to the tab panel.
		const panel = page.getByRole( 'tabpanel', { name: 'Import' } );
		await page.getByLabel( 'Redirection export file' ).setInputFiles( {
			name: 'not-redirection.json',
			mimeType: 'application/json',
			buffer: Buffer.from( JSON.stringify( { hello: 'world' } ) ),
		} );
		await expect(
			panel.getByText( /not a Redirection export/ )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Preview import' } )
		).toHaveCount( 0 );
	} );
} );
