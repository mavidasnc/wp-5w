import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice, SelectControl, Spinner } from '@wordpress/components';
import { api } from '../api';
import TopPostsTable from './TopPostsTable';

const TYPE_OPTIONS = [
	{ value: 'all', label: __( 'Tutti', 'wp-5w' ) },
	{ value: 'post', label: __( 'Articoli', 'wp-5w' ) },
	{ value: 'page', label: __( 'Pagine', 'wp-5w' ) },
	{ value: 'attachment', label: __( 'Allegati', 'wp-5w' ) },
];

const LIMIT_OPTIONS = [ 20, 50, 100 ].map( ( value ) => ( { value: String( value ), label: String( value ) } ) );

/**
 * Card «Contenuti più visti» con filtro per tipo e numero di righe; carica da /admin/top.
 *
 * @param {Object}   props
 * @param {Object}   props.range  { from, to } della Panoramica.
 * @param {Function} props.onOpen Apertura del dettaglio di un contenuto.
 */
export default function TopContents( { range, onOpen } ) {
	const [ type, setType ] = useState( 'all' );
	const [ limit, setLimit ] = useState( '20' );
	const [ rows, setRows ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		setRows( null );
		setError( null );
		api( `/admin/top?from=${ range.from }&to=${ range.to }&type=${ type }&limit=${ limit }` )
			.then( setRows )
			.catch( ( e ) => setError( e.message ) );
	}, [ range.from, range.to, type, limit ] );

	return (
		<section className="wp5w-card">
			<div className="wp5w-card__head">
				<h2>{ __( 'Contenuti più visti', 'wp-5w' ) }</h2>
				<div className="wp5w-filters">
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Tipo', 'wp-5w' ) }
						hideLabelFromVision
						value={ type }
						options={ TYPE_OPTIONS }
						onChange={ setType }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Righe', 'wp-5w' ) }
						hideLabelFromVision
						value={ limit }
						options={ LIMIT_OPTIONS }
						onChange={ setLimit }
					/>
				</div>
			</div>
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! rows && ! error && <Spinner /> }
			{ rows && <TopPostsTable rows={ rows } onOpen={ onOpen } /> }
		</section>
	);
}
