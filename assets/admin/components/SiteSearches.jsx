import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';
import { api, formatDay, formatNumber } from '../api';
import Card from './Card';
import PeriodSwitch from './PeriodSwitch';
import ShowMore from './ShowMore';

// Valore salvato al posto di un termine privato (vedi Support\SearchTerm::FILTERED).
const FILTERED = '(filtrato)';

/**
 * Box «Ricerche interne» della scheda Articoli: termini cercati sul sito e quanti non hanno trovato risultati; carica da /admin/searches.
 */
export default function SiteSearches() {
	const [ days, setDays ] = useState( 30 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		setData( null );
		setError( null );
		api( `/admin/searches?days=${ days }` )
			.then( setData )
			.catch( ( e ) => setError( e.message ) );
	}, [ days ] );

	return (
		<Card
			id="posts-searches"
			title={
				<>
					{ __( 'Ricerche interne', 'wp-5w' ) }
					{ data && (
						<span className="wp5w-type">
							{ formatDay( data.range.from ) } – { formatDay( data.range.to ) }
						</span>
					) }
				</>
			}
			actions={ <PeriodSwitch days={ days } onChange={ setDays } /> }
		>
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! data && ! error && <Spinner /> }
			{ data && ( data.rows.length ? (
				<>
					<p>
						{ sprintf(
							/* translators: 1: numero di ricerche, 2: ricerche senza risultati. */
							_n( '%1$s ricerca, di cui %2$s senza risultati', '%1$s ricerche, di cui %2$s senza risultati', data.searches, 'wp-5w' ),
							formatNumber( data.searches ),
							formatNumber( data.empty )
						) }
					</p>
					<ShowMore rows={ data.rows } size={ 15 }>
						{ ( rows ) => (
							<table className="widefat striped wp5w-table">
								<thead>
									<tr>
										<th>{ __( 'Termine', 'wp-5w' ) }</th>
										<th className="num">{ __( 'Ricerche', 'wp-5w' ) }</th>
										<th className="num">{ __( 'Senza risultati', 'wp-5w' ) }</th>
									</tr>
								</thead>
								<tbody>
									{ rows.map( ( row ) => (
										<tr key={ row.term }>
											<td>{ row.term === FILTERED ? __( 'Termine nascosto (email o numero lungo)', 'wp-5w' ) : row.term }</td>
											<td className="num">{ formatNumber( row.searches ) }</td>
											<td className={ `num${ row.empty > 0 && row.empty === row.searches ? ' is-flagged' : '' }` }>{ formatNumber( row.empty ) }</td>
										</tr>
									) ) }
								</tbody>
							</table>
						) }
					</ShowMore>
				</>
			) : (
				<p>{ __( 'Nessuna ricerca nel periodo.', 'wp-5w' ) }</p>
			) ) }
			<p className="wp5w-note">{ __( 'Termini normalizzati (minuscole, al massimo 60 caratteri). Si conta solo la prima pagina dei risultati.', 'wp-5w' ) }</p>
		</Card>
	);
}
