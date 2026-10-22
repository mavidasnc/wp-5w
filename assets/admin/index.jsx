import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { TabPanel } from '@wordpress/components';
import OverviewPage from './pages/OverviewPage';
import PostsPage from './pages/PostsPage';
import AudiencePage from './pages/AudiencePage';
import OptionsPage from './pages/OptionsPage';
import StatusPage from './pages/StatusPage';
import UpdatesPage from './pages/UpdatesPage';
import './styles/admin.scss';

const TABS = [
	{ name: 'overview', title: __( 'Panoramica', 'wp-5w' ) },
	{ name: 'posts', title: __( 'Articoli', 'wp-5w' ) },
	{ name: 'audience', title: __( 'Visitatori', 'wp-5w' ) },
	{ name: 'options', title: __( 'Opzioni', 'wp-5w' ) },
	{ name: 'updates', title: __( 'Aggiornamenti', 'wp-5w' ) },
	{ name: 'status', title: __( 'Stato', 'wp-5w' ) },
];

function App() {
	// Scheda iniziale da ?tab=... (utile per i link diretti).
	const requested = new URLSearchParams( window.location.search ).get( 'tab' );
	const initial = TABS.some( ( tab ) => tab.name === requested ) ? requested : 'overview';

	return (
		<div className="wp5w-admin">
			<header className="wp5w-admin__header">
				<h1>{ __( '5W Analytics', 'wp-5w' ) }</h1>
				<p>
					{ __(
						'Traffico di WP Statistics e Search Console di Rank Math, incrociati per contenuto.',
						'wp-5w'
					) }
				</p>
			</header>
			<TabPanel tabs={ TABS } initialTabName={ initial }>
				{ ( tab ) => {
					const pages = {
						posts: PostsPage,
						audience: AudiencePage,
						options: OptionsPage,
						updates: UpdatesPage,
						status: StatusPage,
					};
					const Page = pages[ tab.name ] ?? OverviewPage;
					return <Page />;
				} }
			</TabPanel>
			<p className="wp5w-note wp5w-admin__credits">
				<a href="https://db-ip.com" target="_blank" rel="noopener noreferrer">
					{ __( 'IP Geolocation by DB-IP', 'wp-5w' ) }
				</a>
			</p>
		</div>
	);
}

const root = document.getElementById( 'wp5w-admin-root' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
