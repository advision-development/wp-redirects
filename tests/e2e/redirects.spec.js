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

async function seedRules( requestUtils, type, count, prefix ) {
	for ( let i = 1; i <= count; i++ ) {
		const number = String( i ).padStart( 2, '0' );
		await requestUtils.rest( {
			path: '/adv-redirects/v1/redirects',
			method: 'POST',
			data: {
				type,
				source:
					type === 'regex'
						? `^/${ prefix }-${ number }$`
						: `/${ prefix }-${ number }`,
				target: '/e2e-dest',
				status_code: 301,
			},
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
		await seedRules( requestUtils, 'exact', 30, 'e2e-page' );

		await admin.visitAdminPage( 'admin.php', 'page=adv-redirects' );
		const rows = page.locator( '.adv-redirects-table tbody tr' );
		const range = page.locator( '.adv-redirects-pager__range' );
		const next = page.getByRole( 'button', { name: 'Next page' } );
		const previous = page.getByRole( 'button', { name: 'Previous page' } );
		const size = page.getByLabel( 'Rows per page' );

		await expect( range ).toHaveText( '1–25 of 30' );
		await expect( rows ).toHaveCount( 25 );

		await next.click();
		await expect( range ).toHaveText( '26–30 of 30' );
		await expect( rows ).toHaveCount( 5 );
		await expect( next ).toBeDisabled();
		await expect( previous ).toBeFocused();

		await size.selectOption( { label: 'All' } );
		await expect( range ).toHaveText( '30 redirects' );
		await expect( rows ).toHaveCount( 30 );

		// Back to 25: page 1 again, so the matched rule below is off-screen.
		await size.selectOption( { label: '25' } );
		await expect( range ).toHaveText( '1–25 of 30' );

		await page.getByLabel( 'Test a URL' ).fill( '/e2e-page-30' );
		await page.getByRole( 'button', { name: 'Test', exact: true } ).click();
		await expect(
			page.getByRole( 'button', { name: 'Show rule' } )
		).toBeVisible();
		// Testing alone does not move the table.
		await expect( range ).toHaveText( '1–25 of 30' );

		const edit = page.getByRole( 'button', { name: 'Edit /e2e-page-30' } );
		await page.getByRole( 'button', { name: 'Show rule' } ).click();
		await expect( range ).toHaveText( '26–30 of 30' );
		await expect( edit ).toBeFocused();

		// Page away and ask again: the rule is brought back, not reported hidden.
		await previous.click();
		await expect( range ).toHaveText( '1–25 of 30' );
		await page.getByRole( 'button', { name: 'Show rule' } ).click();
		await expect( range ).toHaveText( '26–30 of 30' );
		await expect( edit ).toBeFocused();
		await expect( page.getByText( 'It is hidden by' ) ).toHaveCount( 0 );
	} );

	test( 'remembers the page size and drops selection when the page changes', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await seedRules( requestUtils, 'exact', 30, 'e2e-page' );

		await admin.visitAdminPage( 'admin.php', 'page=adv-redirects' );
		const range = page.locator( '.adv-redirects-pager__range' );
		const size = page.getByLabel( 'Rows per page' );

		await page.getByLabel( 'Select /e2e-page-01' ).check();
		await expect( page.getByText( '1 redirect selected' ) ).toBeVisible();
		await page.getByRole( 'button', { name: 'Next page' } ).click();
		await expect( range ).toHaveText( '26–30 of 30' );
		await expect( page.getByText( '1 redirect selected' ) ).toHaveCount(
			0
		);

		await size.selectOption( { label: '50' } );
		await expect( range ).toHaveText( '1–30 of 30' );
		await page.reload();
		await expect( size ).toHaveValue( '50' );
		await expect( range ).toHaveText( '1–30 of 30' );

		// Leave the default for any later run in the same browser profile.
		await size.selectOption( { label: '25' } );
		await expect( range ).toHaveText( '1–25 of 30' );
	} );

	test( 'keeps focus on an Edit button when deleting the last row of the last page', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await seedRules( requestUtils, 'exact', 30, 'e2e-page' );

		await admin.visitAdminPage( 'admin.php', 'page=adv-redirects' );
		const rows = page.locator( '.adv-redirects-table tbody tr' );
		await page.getByRole( 'button', { name: 'Next page' } ).click();
		await expect( rows ).toHaveCount( 5 );

		// 30 down to 26: the last row of the last page each time.
		for ( let i = 30; i >= 26; i-- ) {
			await page
				.getByRole( 'button', { name: `Delete /e2e-page-${ i }` } )
				.click();
			await expect(
				page.getByRole( 'button', {
					name: `Edit /e2e-page-${ i - 1 }`,
				} )
			).toBeFocused();
		}

		// Page 2 is gone, the page clamped back and the pager with it.
		await expect( rows ).toHaveCount( 25 );
		await expect(
			page.getByRole( 'group', { name: 'Exact redirects pagination' } )
		).toHaveCount( 0 );
	} );

	test( 'moves a regex rule across a page boundary and keeps focus on it', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await seedRules( requestUtils, 'regex', 26, 'e2e-rx' );

		await admin.visitAdminPage( 'admin.php', 'page=adv-redirects' );
		const rows = page.locator( '.adv-redirects-table tbody tr' );
		const range = page.locator( '.adv-redirects-pager__range' );

		await expect( range ).toHaveText( '1–25 of 26' );
		await page.getByRole( 'button', { name: 'Next page' } ).click();
		await expect( range ).toHaveText( '26–26 of 26' );
		const position = page
			.getByRole( 'row' )
			.filter( { hasText: '^/e2e-rx-26$' } )
			.locator( '.adv-redirects-position' );
		await expect( position ).toHaveText( '26' );

		await page
			.getByRole( 'button', { name: 'Move ^/e2e-rx-26$ up' } )
			.click();
		await expect( range ).toHaveText( '1–25 of 26' );
		await expect( rows ).toHaveCount( 25 );
		await expect( position ).toHaveText( '25' );
		await expect(
			page.getByRole( 'button', { name: 'Move ^/e2e-rx-26$ up' } )
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
