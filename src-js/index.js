import apiFetch from '@wordpress/api-fetch';
import { createRoot, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';

import './admin.css';

const bootstrap = window.DementorBlocksBootstrap || {};
const namespace = bootstrap.namespace || 'dementor-blocks/v1';

if ( bootstrap.restNonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( bootstrap.restNonce ) );
}
if ( bootstrap.restRoot ) {
	apiFetch.use( apiFetch.createRootURLMiddleware( bootstrap.restRoot ) );
}

const destinationOptions = [
	{
		label: __( 'Create block draft', 'dementor-blocks' ),
		value: 'duplicate',
	},
	{
		label: __( 'Replace original page', 'dementor-blocks' ),
		value: 'replace',
	},
];

const styleOptions = [
	{ label: __( 'Inline block styles', 'dementor-blocks' ), value: 'inline' },
	{ label: __( 'No style migration', 'dementor-blocks' ), value: 'none' },
	{ label: __( 'Generated CSS', 'dementor-blocks' ), value: 'css' },
];

export function readinessClass( readiness ) {
	if ( readiness === 'Ready' ) {
		return 'ready';
	}
	if ( readiness === 'Review Needed' ) {
		return 'review';
	}
	return 'manual';
}

function App() {
	const [ pages, setPages ] = useState( [] );
	const [ selected, setSelected ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ working, setWorking ] = useState( false );
	const [ message, setMessage ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ destination, setDestination ] = useState( 'duplicate' );
	const [ styleMode, setStyleMode ] = useState( 'inline' );

	const selectedPages = useMemo(
		() => pages.filter( ( page ) => selected.includes( page.id ) ),
		[ pages, selected ]
	);

	const loadPages = async () => {
		setLoading( true );
		setError( '' );
		try {
			const response = await apiFetch( {
				path: `/${ namespace }/pages`,
			} );
			setPages( response.pages || [] );
		} catch ( caught ) {
			setError(
				caught.message ||
					__( 'Could not load pages.', 'dementor-blocks' )
			);
		} finally {
			setLoading( false );
		}
	};

	useEffect( () => {
		loadPages();
	}, [] );

	const toggleSelected = ( id ) => {
		setSelected( ( current ) =>
			current.includes( id )
				? current.filter( ( item ) => item !== id )
				: [ ...current, id ]
		);
	};

	const setAllSelected = ( checked ) => {
		setSelected( checked ? pages.map( ( page ) => page.id ) : [] );
	};

	const auditSelected = async () => {
		if ( selected.length === 0 ) {
			return;
		}
		setWorking( true );
		setError( '' );
		setMessage( __( 'Auditing selected pages…', 'dementor-blocks' ) );
		try {
			await apiFetch( {
				path: `/${ namespace }/audit-batch`,
				method: 'POST',
				data: { post_ids: selected },
			} );
			setMessage( __( 'Audit complete.', 'dementor-blocks' ) );
			await loadPages();
		} catch ( caught ) {
			setError(
				caught.message || __( 'Audit failed.', 'dementor-blocks' )
			);
		} finally {
			setWorking( false );
		}
	};

	const convertSelected = async () => {
		if ( selected.length === 0 ) {
			return;
		}
		setWorking( true );
		setError( '' );
		setMessage( __( 'Converting selected pages…', 'dementor-blocks' ) );
		try {
			await apiFetch( {
				path: `/${ namespace }/convert-batch`,
				method: 'POST',
				data: {
					post_ids: selected,
					destination,
					style_mode: styleMode,
				},
			} );
			setMessage( __( 'Conversion complete.', 'dementor-blocks' ) );
			await loadPages();
		} catch ( caught ) {
			setError(
				caught.message || __( 'Conversion failed.', 'dementor-blocks' )
			);
		} finally {
			setWorking( false );
		}
	};

	return (
		<div className="db-admin">
			<div className="db-header">
				<div>
					<h1>{ __( 'Dementor Blocks', 'dementor-blocks' ) }</h1>
					<p>
						{ __(
							'Audit Elementor pages and convert supported content into native WordPress blocks.',
							'dementor-blocks'
						) }
					</p>
				</div>
				<Button
					variant="secondary"
					onClick={ loadPages }
					disabled={ loading || working }
				>
					{ __( 'Refresh', 'dementor-blocks' ) }
				</Button>
			</div>

			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ message && (
				<Notice status="info" onRemove={ () => setMessage( '' ) }>
					{ message }
				</Notice>
			) }

			<Card className="db-controls">
				<CardBody>
					<div className="db-control-grid">
						<SelectControl
							label={ __( 'Destination', 'dementor-blocks' ) }
							value={ destination }
							options={ destinationOptions }
							onChange={ setDestination }
							__nextHasNoMarginBottom
						/>
						<SelectControl
							label={ __( 'Style migration', 'dementor-blocks' ) }
							value={ styleMode }
							options={ styleOptions }
							onChange={ setStyleMode }
							__nextHasNoMarginBottom
						/>
						<div className="db-actions">
							<Button
								variant="secondary"
								onClick={ auditSelected }
								disabled={ working || selected.length === 0 }
							>
								{ __( 'Audit selected', 'dementor-blocks' ) }
							</Button>
							<Button
								variant="primary"
								onClick={ convertSelected }
								disabled={ working || selected.length === 0 }
								isDestructive={ destination === 'replace' }
							>
								{ __( 'Convert selected', 'dementor-blocks' ) }
							</Button>
						</div>
					</div>
					<div className="db-selected">
						{ sprintf(
							/* translators: %d: Selected page count. */
							__( '%d selected', 'dementor-blocks' ),
							selectedPages.length
						) }
						{ working && <Spinner /> }
					</div>
				</CardBody>
			</Card>

			<div className="db-table-wrap">
				{ loading ? (
					<div className="db-loading">
						<Spinner />
					</div>
				) : (
					<table className="widefat striped db-table">
						<thead>
							<tr>
								<td className="check-column">
									<CheckboxControl
										checked={
											pages.length > 0 &&
											selected.length === pages.length
										}
										onChange={ setAllSelected }
										aria-label={ __(
											'Select all pages',
											'dementor-blocks'
										) }
										__nextHasNoMarginBottom
									/>
								</td>
								<th>{ __( 'Page', 'dementor-blocks' ) }</th>
								<th>
									{ __( 'Readiness', 'dementor-blocks' ) }
								</th>
								<th>{ __( 'Widgets', 'dementor-blocks' ) }</th>
								<th>{ __( 'Warnings', 'dementor-blocks' ) }</th>
								<th>
									{ __( 'Conversion', 'dementor-blocks' ) }
								</th>
								<th>{ __( 'Modified', 'dementor-blocks' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ pages.length === 0 ? (
								<tr>
									<td colSpan="7">
										{ __(
											'No Elementor pages found.',
											'dementor-blocks'
										) }
									</td>
								</tr>
							) : (
								pages.map( ( page ) => (
									<PageRow
										key={ page.id }
										page={ page }
										selected={ selected.includes(
											page.id
										) }
										onToggle={ toggleSelected }
									/>
								) )
							) }
						</tbody>
					</table>
				) }
			</div>
		</div>
	);
}

function PageRow( { page, selected, onToggle } ) {
	const audit = page.audit || {};
	const conversion = page.conversion || {};
	const readiness = audit.readiness || __( 'Not audited', 'dementor-blocks' );
	const warnings = [
		...( audit.warnings || [] ),
		...( conversion.errors || [] ),
	];

	return (
		<tr>
			<td className="check-column">
				<CheckboxControl
					checked={ selected }
					onChange={ () => onToggle( page.id ) }
					aria-label={ sprintf(
						/* translators: %s: Page title. */
						__( 'Select %s', 'dementor-blocks' ),
						page.title
					) }
					__nextHasNoMarginBottom
				/>
			</td>
			<td>
				<a href={ page.edit_url }>
					{ page.title || __( '(no title)', 'dementor-blocks' ) }
				</a>
				<div className="db-row-meta">{ page.status }</div>
			</td>
			<td>
				<span
					className={ `db-pill db-pill--${ readinessClass(
						readiness
					) }` }
				>
					{ readiness }
				</span>
				<div className="db-score">{ audit.score ?? '—' }</div>
			</td>
			<td>
				{ audit.widget_counts
					? `${ audit.widget_counts.supported }/${ audit.widget_counts.total }`
					: '—' }
			</td>
			<td>
				{ warnings.length > 0 ? (
					<ul className="db-warning-list">
						{ warnings.slice( 0, 3 ).map( ( warning ) => (
							<li key={ warning }>{ warning }</li>
						) ) }
					</ul>
				) : (
					<span className="db-muted">
						{ __( 'None', 'dementor-blocks' ) }
					</span>
				) }
			</td>
			<td>
				{ conversion.status ? (
					<div>
						<strong>{ conversion.status }</strong>
						{ conversion.target_post_id > 0 && (
							<div className="db-row-meta">
								{ sprintf(
									/* translators: %d: Target post ID. */
									__( 'Target #%d', 'dementor-blocks' ),
									conversion.target_post_id
								) }
							</div>
						) }
					</div>
				) : (
					<span className="db-muted">
						{ __( 'Not converted', 'dementor-blocks' ) }
					</span>
				) }
			</td>
			<td>
				{ page.modified
					? new Date( page.modified ).toLocaleDateString()
					: '—' }
			</td>
		</tr>
	);
}

const root = document.getElementById( 'dementor-blocks-root' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
