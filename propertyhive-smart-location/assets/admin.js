/* global jQuery, phsl */
jQuery( function ( $ ) {
	var state = null;

	function fmt( n ) { return Number( n ).toLocaleString(); }

	function addLog( text, cls ) {
		$( '<li>' ).addClass( cls || '' ).text( new Date().toLocaleTimeString() + '  ' + text ).prependTo( '#phsl-live' );
		$( '#phsl-live' ).children().slice( 200 ).remove();
	}

	function elapsed() { return ( Date.now() - state.started ) / 1000; }

	function finish( reason ) {
		addLog( 'Run ended: ' + reason, 'phsl-live-info' );
		$( '#phsl-status' ).text( 'Finished: ' + reason + ' (' + fmt( state.done ) + ' processed). Reload the page to refresh the totals.' );
		$( '.phsl-bar span' ).css( 'width', '100%' );
		$( '#phsl-start' ).prop( 'disabled', false );
		$( '#phsl-stop' ).prop( 'disabled', true );
		state = null;
	}

	function step() {
		if ( ! state ) { return; }
		if ( state.stop ) { return finish( 'stopped' ); }
		if ( elapsed() >= state.seconds ) { return finish( 'time limit reached' ); }
		if ( state.done >= state.max ) { return finish( 'property limit reached' ); }

		var pct = Math.max( 100 * elapsed() / state.seconds, 100 * state.done / state.max );
		$( '.phsl-bar span' ).css( 'width', Math.min( 100, pct ) + '%' );
		$( '#phsl-status' ).text( 'Running… ' + fmt( state.done ) + ' processed' );

		$.ajax( { url: phsl.ajaxUrl, method: 'POST', dataType: 'json', timeout: 150000, data: { action: 'phsl_step', nonce: phsl.nonce } } )
			.done( function ( res ) {
				if ( ! res || ! res.success ) { return fail( res && res.data && res.data.message ? res.data.message : 'Unexpected response' ); }
				state.failures = 0;
				var d = res.data;
				if ( d.counts ) { $( '#phsl-to-process' ).text( fmt( d.counts.to_process ) ); }
				if ( d.busy ) { addLog( d.message, 'phsl-live-warning' ); return setTimeout( step, 5000 ); }
				if ( d.done ) { return finish( 'nothing left to process' ); }
				state.done++;
				addLog( '#' + d.property_id + ' ' + d.title + ': ' + d.message, 'phsl-live-' + d.status );
				if ( d.halt ) { return finish( 'stopped: ' + d.message ); }
				step();
			} )
			.fail( function ( xhr, status ) { fail( 'Request failed (' + ( xhr.status || status ) + ')' ); } );
	}

	function fail( msg ) {
		state.failures++;
		addLog( msg, 'phsl-live-error' );
		if ( state.failures >= 3 ) { return finish( 'stopped after repeated errors' ); }
		setTimeout( step, 5000 * state.failures );
	}

	$( '#phsl-start' ).on( 'click', function () {
		state = {
			started: Date.now(),
			seconds: Math.max( 1, parseInt( $( '#phsl-minutes' ).val(), 10 ) || 10 ) * 60,
			max: Math.max( 1, parseInt( $( '#phsl-max' ).val(), 10 ) || 100 ),
			done: 0, failures: 0, stop: false
		};
		$( this ).prop( 'disabled', true );
		$( '#phsl-stop' ).prop( 'disabled', false );
		$( '.phsl-progress' ).prop( 'hidden', false );
		$( '#phsl-live' ).empty();
		step();
	} );

	$( '#phsl-stop' ).on( 'click', function () {
		if ( state ) { state.stop = true; $( this ).prop( 'disabled', true ); $( '#phsl-status' ).text( 'Stopping after the current property…' ); }
	} );

	$( window ).on( 'beforeunload', function () { if ( state ) { return 'A run is in progress.'; } } );

	$( '#phsl-test' ).on( 'click', function () {
		var $btn = $( this ), $out = $( '#phsl-test-output' );
		$btn.prop( 'disabled', true );
		$out.prop( 'hidden', false ).text( 'Working…' );
		$.post( phsl.ajaxUrl, { action: 'phsl_test', nonce: phsl.nonce, property_id: $( '#phsl-test-id' ).val() } )
			.done( function ( res ) { $out.text( JSON.stringify( res && res.data ? res.data : res, null, 2 ) ); } )
			.fail( function ( xhr ) { $out.text( 'Request failed: HTTP ' + xhr.status ); } )
			.always( function () { $btn.prop( 'disabled', false ); } );
	} );
	// Organise Locations
	$( '#phsl-org-generate' ).on( 'click', function () {
		var $btn = $( this ), $status = $( '#phsl-org-status' );
		$btn.prop( 'disabled', true );
		$status.text( 'Asking Claude… this can take a minute or two. Please keep this page open.' );
		$.ajax( {
			url: phsl.ajaxUrl, method: 'POST', dataType: 'json', timeout: 700000,
			data: {
				action: 'phsl_organise_generate', nonce: phsl.nonce,
				mode: $( 'input[name=phsl_org_mode]:checked' ).val(),
				allow_new: $( '#phsl-org-allow-new' ).is( ':checked' ) ? 1 : 0,
				instructions: $( '#phsl-org-instructions' ).val() || ''
			}
		} ).done( function ( res ) {
			if ( res && res.success ) {
				$status.text( 'Done – loading the proposal…' );
				window.location.reload();
			} else {
				$status.text( 'Failed: ' + ( res && res.data && res.data.message ? res.data.message : 'unexpected response' ) );
				$btn.prop( 'disabled', false );
			}
		} ).fail( function ( xhr, status ) {
			$status.text( 'Request failed (' + ( xhr.status || status ) + '). If the server timed out, try “Place unplaced Locations”, or see the Log.' );
			$btn.prop( 'disabled', false );
		} );
	} );

	$( '#phsl-org-all' ).on( 'change', function () {
		$( '.phsl-org-moves input[name="move[]"]' ).prop( 'checked', $( this ).is( ':checked' ) );
	} );
} );
