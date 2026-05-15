import { readinessClass } from '../src-js/index';

describe( 'readinessClass', () => {
	it( 'maps readiness levels to UI classes', () => {
		expect( readinessClass( 'Ready' ) ).toBe( 'ready' );
		expect( readinessClass( 'Review Needed' ) ).toBe( 'review' );
		expect( readinessClass( 'Manual Rebuild' ) ).toBe( 'manual' );
	} );
} );
