const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

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

test.describe( 'Redirects admin', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await deleteAllRedirects( requestUtils );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await deleteAllRedirects( requestUtils );
	} );

	test( 'adds an exact redirect, tests it, and the site redirects', async ( {
		admin,
		page,
		request,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=adv-redirects' );

		await page.getByLabel( 'Source', { exact: true } ).fill( '/e2e-old' );
		await page.getByLabel( 'Target', { exact: true } ).fill( '/e2e-new' );
		await page.getByRole( 'button', { name: 'Add redirect' } ).click();

		await expect(
			page.locator( 'td code', { hasText: /^\/e2e-old$/ } )
		).toBeVisible();
		await expect(
			page
				.getByRole( 'row' )
				.filter( { hasText: '/e2e-old' } )
				.getByText( /By: admin ·/ )
		).toBeVisible();

		await page.getByLabel( 'Test a URL' ).fill( '/e2e-old' );
		await page.getByRole( 'button', { name: 'Test', exact: true } ).click();
		await expect(
			page.locator( '.adv-redirects-test__result' )
		).toContainText( '/e2e-new' );

		const response = await request.get( '/e2e-old?utm_source=e2e', {
			maxRedirects: 0,
		} );
		expect( response.status() ).toBe( 301 );
		expect( response.headers().location ).toMatch(
			/\/e2e-new\?utm_source=e2e$/
		);
		expect( response.headers()[ 'x-redirect-by' ] ).toBe( 'WP Redirects' );
	} );

	test( 'shows an inline error when a redirect would loop', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await requestUtils.rest( {
			path: '/adv-redirects/v1/redirects',
			method: 'POST',
			data: {
				type: 'exact',
				source: '/loop-a',
				target: '/loop-b',
				status_code: 301,
			},
		} );

		await admin.visitAdminPage( 'admin.php', 'page=adv-redirects' );
		await page.getByLabel( 'Source', { exact: true } ).fill( '/loop-b' );
		await page.getByLabel( 'Target', { exact: true } ).fill( '/loop-a' );
		await page.getByRole( 'button', { name: 'Add redirect' } ).click();

		await expect(
			page.getByRole( 'alert' ).filter( { hasText: 'Creates a loop' } )
		).toBeVisible();
	} );

	test( 'pages a long rules table and shows a matched rule on its page', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		for ( let i = 1; i <= 30; i++ ) {
			await requestUtils.rest( {
				path: '/adv-redirects/v1/redirects',
				method: 'POST',
				data: {
					type: 'exact',
					source: `/e2e-page-${ String( i ).padStart( 2, '0' ) }`,
					target: '/e2e-dest',
					status_code: 301,
				},
			} );
		}

		await admin.visitAdminPage( 'admin.php', 'page=adv-redirects' );
		// A size picked in an earlier run would otherwise carry over.
		await page.evaluate( () =>
			window.localStorage.removeItem( 'adv_redirects_page_size' )
		);
		await page.reload();

		const rows = page.locator( '.adv-redirects-table tbody tr' );
		await expect( page.getByText( '1–25 of 30' ) ).toBeVisible();
		await expect( rows ).toHaveCount( 25 );

		await page.getByRole( 'button', { name: 'Next page' } ).click();
		await expect( page.getByText( '26–30 of 30' ) ).toBeVisible();
		await expect( rows ).toHaveCount( 5 );
		await expect(
			page.getByRole( 'button', { name: 'Next page' } )
		).toBeDisabled();
		await expect(
			page.getByRole( 'button', { name: 'Previous page' } )
		).toBeFocused();

		await page
			.getByLabel( 'Rows per page' )
			.selectOption( { label: 'All' } );
		await expect(
			page.getByText( '30 redirects', { exact: true } )
		).toBeVisible();
		await expect( rows ).toHaveCount( 30 );

		// Back to 25: page 1 again, so the matched rule below is off-screen.
		await page
			.getByLabel( 'Rows per page' )
			.selectOption( { label: '25' } );
		await expect( page.getByText( '1–25 of 30' ) ).toBeVisible();

		await page.getByLabel( 'Test a URL' ).fill( '/e2e-page-30' );
		await page.getByRole( 'button', { name: 'Test', exact: true } ).click();
		await page.getByRole( 'button', { name: 'Show rule' } ).click();
		await expect( page.getByText( '26–30 of 30' ) ).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Edit /e2e-page-30' } )
		).toBeFocused();
	} );

	test( 'a 410 rule returns 410 Gone', async ( {
		requestUtils,
		request,
	} ) => {
		await requestUtils.rest( {
			path: '/adv-redirects/v1/redirects',
			method: 'POST',
			data: {
				type: 'exact',
				source: '/e2e-gone',
				target: null,
				status_code: 410,
			},
		} );
		const response = await request.get( '/e2e-gone', { maxRedirects: 0 } );
		expect( response.status() ).toBe( 410 );
	} );
} );
