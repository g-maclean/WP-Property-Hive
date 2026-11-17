/* global jQuery, phosm */
jQuery( function ( $ ) {
	var $start = $( '#phosm-start' ),
		$stop = $( '#phosm-stop' ),
		$progress = $( '.phosm-progress' ),
		$bar = $( '.phosm-progress-bar span' ),
		$status = $( '#phosm-status' ),
		$log = $( '#phosm-live-log' ),
		state = null;

	if ( ! $start.length ) {
		return;
	}

	function fmt( n ) {
		return Number( n ).toLocaleString();
	}

	function updateCounts( counts ) {
		if ( ! counts ) {
			return;
		}
		$( '#phosm-total' ).text( fmt( counts.total ) );
		$( '#phosm-checked' ).text( fmt( counts.checked ) );
		$( '#phosm-remaining' ).text( fmt( counts.remaining ) );
	}

	function addLog( text, cls ) {
		var time = new Date().toLocaleTimeString();
		$( '<li>' ).addClass( cls || '' ).text( time + '  ' + text ).prependTo( $log );
		$log.children().slice( 200 ).remove();
	}

	function elapsed() {
		return ( Date.now() - state.startedAt ) / 1000;
	}

	function renderStatus() {
		var pctTime = Math.min( 100, ( 100 * elapsed() ) / state.seconds ),
			pctCount = Math.min( 100, ( 100 * state.processed ) / state.max ),
			pct = Math.max( pctTime, pctCount ),
			left = Math.max( 0, Math.round( state.seconds - elapsed() ) );
		$bar.css( 'width', pct + '%' );
		$status.text(
			( state.stopping ? phosm.i18n.stopping : phosm.i18n.running ) +
			'  ' + fmt( state.processed ) + ' processed this run, ' +
			Math.floor( left / 60 ) + 'm ' + ( left % 60 ) + 's left'
		);
	}

	function finish( reason ) {
		$start.prop( 'disabled', false );
		$stop.prop( 'disabled', true );
		$( '#phosm-minutes, #phosm-max' ).prop( 'disabled', false );
		$bar.css( 'width', '100%' );
		$status.text( phosm.i18n.finished + ': ' + reason + ' (' + fmt( state.processed ) + ' properties processed in ' + Math.round( elapsed() ) + 's)' );
		addLog( 'Run ended: ' + reason, 'phosm-live-info' );
		state = null;

		$( '#phosm-stats' ).css( 'opacity', 0.5 ).prepend( $( '<p>' ).text( phosm.i18n.stats ) );
		$.post( phosm.ajaxUrl, { action: 'phosm_stats', nonce: phosm.nonce, target: $( '#phosm-stats' ).data( 'target' ) } ).done( function ( res ) {
			if ( res && res.success ) {
				$( '#phosm-stats' ).html( res.data.html ).css( 'opacity', 1 );
				updateCounts( res.data.counts );
				if ( res.data.counts && 0 === res.data.counts.remaining ) {
					$start.prop( 'disabled', true );
				}
			}
		} );
	}

	function step() {
		if ( ! state ) {
			return;
		}
		if ( state.stopping ) {
			return finish( 'stopped' );
		}
		if ( elapsed() >= state.seconds ) {
			return finish( 'time limit reached' );
		}
		if ( state.processed >= state.max ) {
			return finish( 'property limit reached' );
		}
		renderStatus();

		$.ajax( {
			url: phosm.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			timeout: 60000,
			data: { action: 'phosm_step', nonce: phosm.nonce }
		} ).done( function ( res ) {
			if ( ! res || ! res.success ) {
				return fail( res && res.data && res.data.message ? res.data.message : 'Unexpected response' );
			}
			state.failures = 0;
			var d = res.data;
			updateCounts( d.counts );

			if ( d.busy ) {
				addLog( d.message, 'phosm-live-warning' );
				return setTimeout( step, 3000 );
			}
			if ( d.done ) {
				return finish( 'all properties checked' );
			}

			state.processed++;
			addLog( '#' + d.property_id + ' ' + d.title + ' — ' + d.message, 'phosm-live-' + d.outcome );

			if ( d.halt ) {
				return finish( 'OSM refused requests (' + d.message + '). Wait before running again.' );
			}
			step();
		} ).fail( function ( xhr, status ) {
			fail( 'Request failed (' + ( xhr.status || status ) + ')' );
		} );
	}

	function fail( message ) {
		state.failures++;
		addLog( message, 'phosm-live-error' );
		if ( state.failures >= 3 ) {
			return finish( 'stopped after repeated errors' );
		}
		setTimeout( step, 5000 * state.failures );
	}

	$start.on( 'click', function () {
		var minutes = Math.max( 1, parseInt( $( '#phosm-minutes' ).val(), 10 ) || 10 ),
			max = Math.max( 1, parseInt( $( '#phosm-max' ).val(), 10 ) || 500 );

		state = { startedAt: Date.now(), seconds: minutes * 60, max: max, processed: 0, failures: 0, stopping: false };
		$start.prop( 'disabled', true );
		$stop.prop( 'disabled', false );
		$( '#phosm-minutes, #phosm-max' ).prop( 'disabled', true );
		$progress.prop( 'hidden', false );
		$log.empty();
		addLog( 'Run started: up to ' + minutes + ' minute(s) / ' + max + ' properties', 'phosm-live-info' );
		step();
	} );

	$stop.on( 'click', function () {
		if ( state ) {
			state.stopping = true;
			$stop.prop( 'disabled', true );
			renderStatus();
		}
	} );

	$( window ).on( 'beforeunload', function () {
		if ( state ) {
			return 'A lookup is running.';
		}
	} );
} );
