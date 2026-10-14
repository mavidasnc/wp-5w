<?php
/**
 * Dati sintetici per provare l'ETL in wp-env.
 *
 * Uso: npx wp-env run cli wp eval-file wp-content/plugins/wp-5w/tests/fixtures/seed.php
 *
 * Riempie le tabelle di WP Statistics e Rank Math con lo stesso schema della produzione:
 * - totali giornalieri dal 2023, con un buco a zero (come il blog tra 2017 e 2022);
 * - 60 giorni di dettaglio (pagine e visitatori con pagina d'ingresso e canale);
 * - Search Console con ogni riga triplicata, come dopo un import di Rank Math rilanciato;
 * - una pagina GSC che Rank Math non ha in analytics_objects (va associata con url_to_postid);
 * - 6 visitatori di 5 giorni fa da assistenti AI e app (fase 4: canale «ai» e referrer delle app).
 *
 * @package Mavida\FiveW
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

global $wpdb;

$p = $wpdb->prefix;

// Permalink come sul blog, così le pagine GSC hanno la stessa forma (/aaaa/mm/gg/slug/).
global $wp_rewrite;
$wp_rewrite->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );
$wp_rewrite->flush_rules( true );

// Tabelle con lo schema della produzione (create solo se i plugin non le hanno già create).
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}statistics_summary_totals ( ID bigint unsigned NOT NULL AUTO_INCREMENT, date date NOT NULL, visitors bigint unsigned NOT NULL, views bigint unsigned NOT NULL, PRIMARY KEY (ID) )" );
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}statistics_pages ( page_id bigint NOT NULL AUTO_INCREMENT, uri varchar(190) NOT NULL, date date NOT NULL, count int NOT NULL, id int NOT NULL, type varchar(180) NOT NULL, PRIMARY KEY (page_id) )" );
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}statistics_visitor ( ID bigint NOT NULL AUTO_INCREMENT, last_counter date NOT NULL, referred mediumtext NOT NULL, agent varchar(180) NOT NULL, platform varchar(180), version varchar(180), UAString varchar(190), ip varchar(60) NOT NULL, location varchar(10), hits int, honeypot int, device varchar(180), model varchar(180), user_id bigint NOT NULL DEFAULT 0, city varchar(100), region varchar(100), continent varchar(50), source_channel varchar(50), source_name varchar(100), first_page bigint unsigned, first_view datetime, last_page bigint unsigned, last_view datetime, PRIMARY KEY (ID) )" );
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}statistics_visitor_relationships ( ID bigint NOT NULL AUTO_INCREMENT, visitor_id bigint NOT NULL, page_id bigint NOT NULL, date datetime NOT NULL, PRIMARY KEY (ID) )" );
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}rank_math_analytics_gsc ( id bigint unsigned NOT NULL AUTO_INCREMENT, created timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), query varchar(1000) NOT NULL, page varchar(500) NOT NULL, clicks mediumint NOT NULL, impressions mediumint NOT NULL, position double NOT NULL, ctr double NOT NULL, PRIMARY KEY (id) )" );
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}rank_math_analytics_objects ( id bigint unsigned NOT NULL AUTO_INCREMENT, created timestamp NOT NULL DEFAULT current_timestamp(), title text NOT NULL, page varchar(500) NOT NULL, object_type varchar(100) NOT NULL, object_subtype varchar(100) NOT NULL, object_id bigint unsigned NOT NULL, primary_key varchar(255) NOT NULL DEFAULT '', PRIMARY KEY (id) )" );

foreach ( array( 'statistics_summary_totals', 'statistics_pages', 'statistics_visitor', 'statistics_visitor_relationships', 'rank_math_analytics_gsc', 'rank_math_analytics_objects' ) as $wp5w_t ) {
	$wpdb->query( "TRUNCATE TABLE {$p}{$wp5w_t}" );
}

// Tre articoli con slug stabili.
$wp5w_posts = array();
// L'ultimo è pubblicato oggi: deve comparire primo tra gli ultimi articoli.
foreach ( array( 'calendario-2006', 'blocksy-tema', 'php-solutions', 'uscito-oggi' ) as $wp5w_slug ) {
	$wp5w_existing = get_page_by_path( $wp5w_slug, OBJECT, 'post' );
	$wp5w_posts[]  = $wp5w_existing ? $wp5w_existing->ID : wp_insert_post(
		array(
			'post_title'  => ucwords( str_replace( '-', ' ', $wp5w_slug ) ),
			'post_name'   => $wp5w_slug,
			'post_status' => 'publish',
		)
	);
}

// I primi tre post sono di un anno fa (il totale dalla pubblicazione parte dal primo giorno con dati).
foreach ( $wp5w_posts as $wp5w_n => $wp5w_post ) {
	$wp5w_date = 3 === $wp5w_n ? current_time( 'mysql' ) : wp_date( 'Y-m-d 09:00:00', strtotime( '-1 year' ) );
	wp_update_post(
		array(
			'ID'            => $wp5w_post,
			'post_date'     => $wp5w_date,
			'post_date_gmt' => get_gmt_from_date( $wp5w_date ),
		)
	);
}

$wp5w_today = new DateTimeImmutable( wp_date( 'Y-m-d' ) );

// Totali giornalieri dal 2023 (con 30 giorni a zero nel 2023, da non importare).
for ( $wp5w_d = new DateTimeImmutable( '2023-01-01' ); $wp5w_d < $wp5w_today->modify( '-60 days' ); $wp5w_d = $wp5w_d->modify( '+1 day' ) ) {
	$wp5w_zero = $wp5w_d >= new DateTimeImmutable( '2023-03-01' ) && $wp5w_d < new DateTimeImmutable( '2023-03-31' );
	$wpdb->insert(
		"{$p}statistics_summary_totals",
		array(
			'date'     => $wp5w_d->format( 'Y-m-d' ),
			'visitors' => $wp5w_zero ? 0 : 20 + (int) $wp5w_d->format( 'j' ),
			'views'    => $wp5w_zero ? 0 : 40 + (int) $wp5w_d->format( 'j' ),
		)
	);
}

// Ultimi 60 giorni: dettaglio pagine e visitatori.
$wp5w_channels = array( 'search', 'direct', 'social', '' );
for ( $wp5w_i = 59; $wp5w_i >= 0; $wp5w_i-- ) {
	$wp5w_day = $wp5w_today->modify( "-{$wp5w_i} days" )->format( 'Y-m-d' );

	$wp5w_page_ids = array();
	$wpdb->insert( "{$p}statistics_pages", array( 'uri' => '/', 'date' => $wp5w_day, 'count' => 10, 'id' => 4, 'type' => 'home' ) );
	$wp5w_page_ids[] = $wpdb->insert_id;
	foreach ( $wp5w_posts as $wp5w_n => $wp5w_post ) {
		$wpdb->insert(
			"{$p}statistics_pages",
			array(
				'uri'   => wp_make_link_relative( get_permalink( $wp5w_post ) ),
				'date'  => $wp5w_day,
				'count' => 5 * ( $wp5w_n + 1 ),
				'id'    => $wp5w_post,
				'type'  => 'post',
			)
		);
		$wp5w_page_ids[] = $wpdb->insert_id;
	}

	// Quattro visitatori al giorno, uno per canale (l'ultimo senza canale), con referrer per search e social.
	$wp5w_referrers = array( 'google.com', '', 'lm.facebook.com', '' );
	foreach ( $wp5w_channels as $wp5w_c => $wp5w_channel ) {
		$wpdb->insert(
			"{$p}statistics_visitor",
			array(
				'last_counter'   => $wp5w_day,
				'referred'       => $wp5w_referrers[ $wp5w_c ],
				'agent'          => 'Chrome',
				'platform'       => 'Android',
				'ip'             => '#hash#' . md5( $wp5w_day . $wp5w_c ),
				'location'       => 0 === $wp5w_c ? 'IT' : 'US',
				'device'         => 'smartphone',
				'source_channel' => '' === $wp5w_channel ? null : $wp5w_channel,
				'source_name'    => 'search' === $wp5w_channel ? 'Google' : null,
				'first_page'     => $wp5w_page_ids[ $wp5w_c ],
			)
		);

		// Ogni visitatore vede la pagina d'ingresso due volte (va contato una volta) e il primo articolo.
		$wp5w_visitor = $wpdb->insert_id;
		foreach ( array( $wp5w_page_ids[ $wp5w_c ], $wp5w_page_ids[ $wp5w_c ], $wp5w_page_ids[1] ) as $wp5w_page_id ) {
			$wpdb->insert(
				"{$p}statistics_visitor_relationships",
				array(
					'visitor_id' => $wp5w_visitor,
					'page_id'    => $wp5w_page_id,
					'date'       => "{$wp5w_day} 10:00:00",
				)
			);
		}
	}

	// Fase 4: 6 visitatori in più, 5 giorni fa (dentro gli ultimi 7 della scheda Visitatori), entrati dal primo articolo. Cinque arrivano da un assistente AI (referrer,
	// source_name senza referrer e pacchetto Android) e uno da un'app che non lo è: il canale «ai» li sposta, i visitatori restano.
	if ( 5 === $wp5w_i ) {
		$wp5w_ai_visitors = array(
			array( 'chatgpt.com', 'search', 'ChatGPT' ),
			array( '', 'search', 'ChatGPT' ),
			array( 'perplexity.ai', 'search', 'Perplexity' ),
			array( 'claude.ai', null, null ),
			array( 'android-app://com.openai.chatgpt/', null, null ),
			array( 'android-app://com.linkedin.android/', null, null ),
		);
		foreach ( $wp5w_ai_visitors as $wp5w_a => $wp5w_ai ) {
			$wpdb->insert(
				"{$p}statistics_visitor",
				array(
					'last_counter'   => $wp5w_day,
					'referred'       => $wp5w_ai[0],
					'agent'          => 'Chrome',
					'platform'       => 'Android',
					'ip'             => '#hash#' . md5( $wp5w_day . 'ai' . $wp5w_a ),
					'location'       => 'IT',
					'device'         => 'smartphone',
					'source_channel' => $wp5w_ai[1],
					'source_name'    => $wp5w_ai[2],
					'first_page'     => $wp5w_page_ids[1],
				)
			);
			$wpdb->insert(
				"{$p}statistics_visitor_relationships",
				array(
					'visitor_id' => $wpdb->insert_id,
					'page_id'    => $wp5w_page_ids[1],
					'date'       => "{$wp5w_day} 11:00:00",
				)
			);
		}
	}

	// Totali riassunti solo fino a ieri: oggi deve arrivare dal dettaglio.
	if ( $wp5w_i > 0 ) {
		$wpdb->insert( "{$p}statistics_summary_totals", array( 'date' => $wp5w_day, 'visitors' => 4, 'views' => 40 ) );
	}

	// Search Console dagli ultimi 50 giorni, con un ritardo di 2 giorni, ogni riga tre volte.
	if ( $wp5w_i >= 2 && $wp5w_i < 50 ) {
		foreach ( array( 0, 1 ) as $wp5w_n ) {
			$wp5w_page = wp_make_link_relative( get_permalink( $wp5w_posts[ $wp5w_n ] ) );
			for ( $wp5w_copy = 0; $wp5w_copy < 3; $wp5w_copy++ ) {
				$wpdb->insert(
					"{$p}rank_math_analytics_gsc",
					array(
						'created'     => "{$wp5w_day} 00:00:00",
						'query'       => "query {$wp5w_n}",
						'page'        => $wp5w_page,
						'clicks'      => 2,
						'impressions' => 20,
						'position'    => 3 + $wp5w_n,
						'ctr'         => 0.1,
					)
				);
			}
		}
	}
}

// Rank Math conosce solo il primo articolo: il secondo va associato con url_to_postid.
$wpdb->insert(
	"{$p}rank_math_analytics_objects",
	array(
		'title'          => 'Calendario',
		'page'           => wp_make_link_relative( get_permalink( $wp5w_posts[0] ) ),
		'object_type'    => 'post',
		'object_subtype' => 'post',
		'object_id'      => $wp5w_posts[0],
	)
);

WP_CLI::success( 'Dati sintetici creati per i post ' . implode( ', ', $wp5w_posts ) . '.' );
