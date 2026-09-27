/**
 * Airworthy admin screen: start, progress and results views.
 *
 * Talks only to the plugin's REST API (/airworthy/v1) through wp.apiFetch, which adds the
 * logged-in user's REST nonce. Everything that comes from a scan (plugin names, file paths,
 * messages) is inserted with textContent, never as HTML.
 *
 * @package Airworthy
 */

( function () {
	'use strict';

	var apiFetch = window.wp.apiFetch;
	var __       = window.wp.i18n.__;
	var _n       = window.wp.i18n._n;
	var sprintf  = window.wp.i18n.sprintf;
	var config   = window.airworthyConfig || {};
	var REST     = config.rest || '/airworthy/v1';

	var POLL_MS        = 4000;         // How often the progress view asks for news.
	var NUDGE_AFTER_MS = 20000; // No progress for this long: run a batch from this page.

	var views = {
		start: document.getElementById( 'airworthy-start' ),
		progress: document.getElementById( 'airworthy-progress' ),
		results: document.getElementById( 'airworthy-results' ),
	};
	var state = {
		scan: null,
		pollTimer: null,
		wporgTimer: null,
		lastDone: -1,
		lastChange: Date.now(),
		nudging: false,
		blockersOnly: false,
		lastAnnounced: -1,
	};
	var live  = document.getElementById( 'airworthy-live' );

	/**
	 * Tells screen-reader users what happened, through one polite live region that isn't
	 * rebuilt with the view (a rebuilt region is either silent or chatty).
	 */
	function announce( text ) {
		live.textContent = '';
		window.setTimeout(
			function () {
				live.textContent = text;
			},
			50
		);
	}

	/**
	 * Moves keyboard focus to a view's heading when the view changes.
	 */
	function focusHeading( view ) {
		var heading = view.querySelector( 'h2' );
		if ( heading ) {
			heading.setAttribute( 'tabindex', '-1' );
			heading.focus();
		}
	}

	// ---------------------------------------------------------------------------------------
	// Wording. The line under each verdict is agreed copy; keep it calm and plain.
	// ---------------------------------------------------------------------------------------

	var VERDICTS = {
		blocker: {
			label: __( 'Blocker', 'airworthy' ),
			/* translators: %s: PHP version. */
			line: __( 'Needs attention before you upgrade: some of its code will not work on PHP %s.', 'airworthy' ),
		},
		unknown: {
			label: __( 'Unknown', 'airworthy' ),
			line: __( 'Some files could not be checked, so there is no verdict yet. Open the details to see which.', 'airworthy' ),
		},
		suppressed: {
			label: __( 'Suppressed by author', 'airworthy' ),
			line: __( "The plugin's authors marked this code as intentional. Usually safe, but not verified by Airworthy.", 'airworthy' ),
		},
		guarded: {
			label: __( 'Guarded legacy code', 'airworthy' ),
			line: __( 'Old code that never runs on this PHP version. Safe.', 'airworthy' ),
		},
		warnings: {
			label: __( 'Warnings', 'airworthy' ),
			line: __( 'Works on this PHP version. Some code will need updating before a future version.', 'airworthy' ),
		},
		ready: {
			label: __( 'Ready', 'airworthy' ),
			line: __( 'No problems found for this PHP version.', 'airworthy' ),
		},
	};
	var ORDER = [ 'blocker', 'unknown', 'suppressed', 'guarded', 'warnings', 'ready' ];

	var SEVERITY  = {
		scan: __( 'Not checked', 'airworthy' ),
		error: __( 'Error', 'airworthy' ),
		warning: __( 'Deprecated', 'airworthy' ),
		notice: __( 'Not a problem', 'airworthy' ),
	};
	var UNCHECKED = {
		'Airworthy.Scan.Unreadable': __( 'Could not be read (file permissions)', 'airworthy' ),
		'Airworthy.Scan.Crashed': __( 'Stopped the server process twice (usually a memory or time limit)', 'airworthy' ),
		'Airworthy.Scan.TooLarge': __( "Too large for this server's memory limit", 'airworthy' ),
		'Airworthy.Scan.EngineError': __( 'Could not be parsed', 'airworthy' ),
		'Airworthy.Scan.TooManyCrashes': __( 'Checking stopped after repeated failures in this plugin', 'airworthy' ),
	};
	var CONTEXT   = {
		guarded: __( 'guarded', 'airworthy' ),
		suppressed: __( 'suppressed by author', 'airworthy' ),
	};

	// ---------------------------------------------------------------------------------------
	// Small helpers.
	// ---------------------------------------------------------------------------------------

	/**
	 * Creates an element. Children that are strings become text nodes (never HTML).
	 */
	function el( tag, attrs ) {
		var node     = document.createElement( tag );
		var children = Array.prototype.slice.call( arguments, 2 );
		Object.keys( attrs || {} ).forEach(
			function ( key ) {
				var value = attrs[ key ];
				if ( null === value || undefined === value || false === value ) {
						return;
				}
				if ( 'class' === key ) {
					node.className = value;
				} else if ( 0 === key.indexOf( 'on' ) ) {
					node.addEventListener( key.slice( 2 ), value );
				} else if ( true === value ) {
					node.setAttribute( key, '' );
				} else {
					node.setAttribute( key, String( value ) );
				}
			}
		);
		children.forEach(
			function append( child ) {
			if ( Array.isArray( child ) ) {
				child.forEach( append );
			} else if ( null !== child && undefined !== child && false !== child ) {
				node.appendChild( 'string' === typeof child || 'number' === typeof child ? document.createTextNode( String( child ) ) : child );
			}
			}
		);
		return node;
	}

	/**
	 * Replaces a node's children, skipping empty slots (replaceChildren() would print "null").
	 */
	function fill( node ) {
		node.replaceChildren();
		Array.prototype.slice.call( arguments, 1 ).forEach(
			function add( child ) {
			if ( Array.isArray( child ) ) {
				child.forEach( add );
			} else if ( null !== child && undefined !== child && false !== child ) {
				node.appendChild( 'string' === typeof child ? document.createTextNode( child ) : child );
			}
			}
		);
		return node;
	}

	function isRunning( scan ) {
		return scan && ( 'queued' === scan.status || 'running' === scan.status );
	}

	function show( name ) {
		Object.keys( views ).forEach(
			function ( key ) {
				views[ key ].hidden = key !== name;
			}
		);
	}

	function formatDate( value ) {
		if ( ! value ) {
			return '';
		}
		var date = new Date( /^\d{4}-\d{2}-\d{2}$/.test( value ) ? value + 'T12:00:00Z' : value );
		return isNaN( date.getTime() ) ? value : date.toLocaleDateString( document.documentElement.lang || undefined, { day : 'numeric', month : 'short', year : 'numeric' } );
	}

	function formatDateTime( value ) {
		var date = new Date( value );
		return isNaN( date.getTime() ) ? '' : date.toLocaleString( document.documentElement.lang || undefined, { day : 'numeric', month : 'short', year : 'numeric', hour : '2-digit', minute : '2-digit' } );
	}

	function errorMessage( error ) {
		return ( error && error.message ) || __( 'Something went wrong. Please try again.', 'airworthy' );
	}

	/**
	 * A file path (and line) that wraps after slashes, never inside a name or the line number.
	 */
	function fileLocation( file, line ) {
		var code = el( 'code', { class: 'airworthy-loc' } );
		String( file ).split( '/' ).forEach(
			function ( part, index, parts ) {
				code.appendChild( document.createTextNode( part + ( index < parts.length - 1 ? '/' : '' ) ) );
				if ( index < parts.length - 1 ) {
						code.appendChild( document.createElement( 'wbr' ) );
				}
			}
		);
		if ( line ) {
			code.appendChild( el( 'span', { class: 'airworthy-line' }, ':' + line ) );
		}
		return code;
	}

	function verdictBadge( verdict, running ) {
		var v = VERDICTS[ verdict ];
		if ( v ) {
			return el( 'span', { class: 'airworthy-badge airworthy-verdict-' + verdict }, v.label );
		}
		return el( 'span', { class: 'airworthy-badge' }, running ? __( 'Checking…', 'airworthy' ) : __( 'Not checked', 'airworthy' ) );
	}

	// ---------------------------------------------------------------------------------------
	// Start view.
	// ---------------------------------------------------------------------------------------

	var form        = document.getElementById( 'airworthy-start-form' );
	var select      = document.getElementById( 'airworthy-target' );
	var endedNote   = document.getElementById( 'airworthy-ended-note' );
	var startError  = document.getElementById( 'airworthy-start-error' );
	var startButton = document.getElementById( 'airworthy-start-button' );
	var backButton  = document.getElementById( 'airworthy-start-cancel' );

	/**
	 * Shows the "unsupported version" note when the chosen target no longer gets security fixes.
	 */
	function updateEndedNote() {
		var option            = select.options[ select.selectedIndex ];
		var ended             = option && '0' === option.getAttribute( 'data-supported' );
		endedNote.hidden      = ! ended;
		endedNote.textContent = ended ? endedNote.getAttribute( 'data-template' ).replace( '%1$s', option.value ) : '';
	}

	function loadEstimate() {
		var target = document.getElementById( 'airworthy-estimate' );
		apiFetch( { path: REST + '/estimate' } ).then(
			function ( e ) {
				/* translators: %s: number of files. */
				var files          = sprintf( _n( '%s file', '%s files', e.files, 'airworthy' ), e.files.toLocaleString() );
				target.textContent = e.minutes_low === e.minutes_high ?
				/* translators: 1: minutes, 2: number of files, e.g. "120 files". */
				sprintf( _n( 'About %1$d minute to check %2$s.', 'About %1$d minutes to check %2$s.', e.minutes_high, 'airworthy' ), e.minutes_high, files ) :
				/* translators: 1: fewest minutes, 2: most minutes, 3: number of files, e.g. "120 files". */
				sprintf( __( 'About %1$d–%2$d minutes to check %3$s, depending on your server. It runs in the background: you can leave this page.', 'airworthy' ), e.minutes_low, e.minutes_high, files );
			}
		).catch(
			function () {
				target.textContent = __( 'A few minutes, depending on your server. It runs in the background.', 'airworthy' );
			}
		);
	}

	function showStart( canGoBack ) {
		clearTimeout( state.wporgTimer );
		backButton.hidden = ! canGoBack;
		startError.hidden = true;
		updateEndedNote();
		show( 'start' );
		loadEstimate();
		if ( canGoBack ) {
			select.focus();
		}
	}

	select.addEventListener( 'change', updateEndedNote );
	backButton.addEventListener(
		'click',
		function () {
			if ( state.scan ) {
				renderResults( state.scan, true );
			}
		}
	);
	form.addEventListener(
		'submit',
		function ( event ) {
			event.preventDefault();
			startButton.disabled = true;
			startError.hidden    = true;
			var wporg            = form.querySelector( 'input[name="wporg"]:checked' );
			apiFetch( { path: REST + '/scans', method: 'POST', data: { target: select.value, wporg: ! ! wporg && 'yes' === wporg.value } } ).then(
				function ( scan ) {
					state.scan = scan;
					/* translators: %s: PHP version. */
					announce( sprintf( __( 'Scan started for PHP %s.', 'airworthy' ), scan.target_php ) );
					startProgress();
				}
			).catch(
				function ( error ) {
					if ( error && 'airworthy_scan_running' === error.code ) {
						// Started elsewhere (another tab, WP-CLI): follow that scan instead.
						return apiFetch( { path: REST + '/scans/current' } ).then(
							function ( scan ) {
								state.scan = scan;
								announce( __( 'A scan is already running. Showing its progress.', 'airworthy' ) );
								startProgress();
							}
						);
					}
					startError.textContent = errorMessage( error );
					startError.hidden      = false;
					announce( startError.textContent );
				}
			).catch(
				function ( error ) {
					startError.textContent = errorMessage( error );
					startError.hidden      = false;
				}
			).then(
				function () {
					startButton.disabled = false;
				}
			);
		}
	);

	// ---------------------------------------------------------------------------------------
	// Progress view.
	// ---------------------------------------------------------------------------------------

	function startProgress() {
		clearTimeout( state.wporgTimer );
		state.lastDone = -1;
		// Announce 25% milestones from here on (a rescan may start at 99%).
		state.lastAnnounced = Math.floor( ( ( state.scan && state.scan.progress && state.scan.progress.percent ) || 0 ) / 25 );
		state.lastChange    = Date.now();
		state.progressParts = null;
		show( 'progress' );
		renderProgress( state.scan );
		focusHeading( views.progress );
		poll();
	}

	function poll() {
		clearTimeout( state.pollTimer );
		apiFetch( { path: REST + '/scans/current?seen=1' } ).then(
			function ( scan ) {
				state.scan = scan;
				if ( 'queued' === scan.status || 'running' === scan.status ) {
					if ( scan.progress.files_done !== state.lastDone ) {
						state.lastDone   = scan.progress.files_done;
						state.lastChange = Date.now();
					}
					renderProgress( scan );
					maybeNudge( scan );
					// Announce every 25%, not every poll.
					var quarter = Math.floor( scan.progress.percent / 25 );
					if ( quarter > 0 && quarter !== state.lastAnnounced ) {
						state.lastAnnounced = quarter;
						/* translators: %d: percent. */
						announce( sprintf( __( 'Scan %d%% done.', 'airworthy' ), scan.progress.percent ) );
					}
					state.pollTimer = setTimeout( poll, POLL_MS );
				} else {
					var attention = ( scan.components || [] ).filter(
						function ( c ) {
							return 'blocker' === c.verdict || 'unknown' === c.verdict;
						}
					).length;
					announce(
						attention ?
						/* translators: %d: number of plugins. */
						sprintf( _n( 'Scan finished. %d plugin or theme needs attention.', 'Scan finished. %d plugins or themes need attention.', attention, 'airworthy' ), attention ) :
						__( 'Scan finished. Nothing needs attention.', 'airworthy' )
					);
					renderResults( scan, true );
				}
			}
		).catch(
			function () {
				state.pollTimer = setTimeout( poll, POLL_MS * 2 );
			}
		);
	}

	/**
	 * Background jobs normally run through WP-Cron, which only fires when someone visits the
	 * site. If nothing has moved for a while (a quiet site, or WP-Cron switched off), run a
	 * batch from this page instead.
	 */
	function maybeNudge( scan ) {
		var stalled = Date.now() - state.lastChange > NUDGE_AFTER_MS;
		if ( state.nudging || ! ( stalled || config.cronDisabled ) ) {
			return;
		}
		state.nudging = true;
		renderProgress( scan );
		apiFetch( { path: REST + '/scans/' + scan.id + '/nudge', method: 'POST' } ).catch( function () {} ).then(
			function () {
				state.nudging    = false;
				state.lastChange = Date.now();
			}
		);
	}

	/**
	 * Builds the progress view once per scan; later polls only update its text, bar and list,
	 * so keyboard focus (on the heading or the Stop button) is never lost.
	 */
	function buildProgress( scan ) {
		var parts      = {
			heading: el( 'h2', null ),
			what: el( 'p', { class: 'airworthy-progress-what' } ),
			bar: el( 'span', null ),
			text: el( 'p', { class: 'airworthy-progress-text' } ),
			note: el( 'p', { class: 'description' } ),
			list: el( 'ul', { class: 'airworthy-progress-list' } ),
		};
		parts.barWrap  = el( 'div', { class: 'airworthy-bar', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-label': __( 'Scan progress', 'airworthy' ) }, parts.bar );
		parts.listCard = el( 'div', { class: 'airworthy-card', hidden: true }, el( 'h3', null, __( 'Plugins and themes', 'airworthy' ) ), parts.list );
		fill(
			views.progress,
			el(
				'div',
				{ class: 'airworthy-card' },
				parts.heading,
				parts.what,
				parts.barWrap,
				parts.text,
				parts.note,
				el(
					'p',
					null,
					el(
						'button',
						{ type: 'button', class: 'button', onclick: function () {
							if ( window.confirm( __( 'Stop this scan? Results so far are kept.', 'airworthy' ) ) ) {
								apiFetch( { path: REST + '/scans/' + state.scan.id + '/cancel', method: 'POST' } ).then( poll );
							}
						} },
						__( 'Stop scan', 'airworthy' )
					)
				)
			),
			checksCard( scan ),
			parts.listCard
		);
		parts.scanId        = scan.id;
		state.progressParts = parts;
		return parts;
	}

	/**
	 * What a scan looks for, in plain words, so the progress screen explains itself. The
	 * examples are real PHP changes; PHPCompatibility checks many more of each kind.
	 */
	function checksCard( scan ) {
		var item = function ( title, text ) {
			return el( 'li', null, el( 'strong', null, title ), ' ', text );
		};
		return el(
			'div',
			{ class: 'airworthy-card airworthy-checks' },
			el( 'h3', null, __( 'What each file is checked for', 'airworthy' ) ),
			el(
				'ul',
				null,
				item(
					__( 'Removed features.', 'airworthy' ),
					__( 'Functions, classes and settings PHP no longer has, such as create_function() and each(), both removed in PHP 8.0. Code that uses them stops the page with a fatal error.', 'airworthy' )
				),
				item(
					__( 'Stricter rules.', 'airworthy' ),
					__( 'Code that older PHP let slide with a warning but PHP 8 stops with an error, such as giving implode() its arguments in the wrong order.', 'airworthy' )
				),
				item(
					__( 'Features being phased out.', 'airworthy' ),
					__( 'They still work, but will be removed in a later version, such as adding undeclared properties to objects (deprecated in PHP 8.2). These show as Warnings, not Blockers.', 'airworthy' )
				),
				item(
					__( 'Safety checks in the code.', 'airworthy' ),
					/* translators: %s: PHP version. */
					sprintf( __( 'Whether old code only runs on old PHP versions, behind a version check. If it can never run on PHP %s, it is marked as guarded legacy code, not as a problem.', 'airworthy' ), scan.target_php )
				),
				'off' === scan.wporg ? null : item(
					__( 'WordPress.org details.', 'airworthy' ),
					/* translators: %s: PHP version. */
					sprintf( __( 'Whether each plugin was removed from WordPress.org, looks abandoned, is tested with recent WordPress, or needs a newer PHP version than PHP %s.', 'airworthy' ), scan.target_php )
				)
			),
			el( 'p', { class: 'airworthy-muted' }, __( 'Nothing is run or changed: Airworthy only reads the files.', 'airworthy' ) )
		);
	}

	function renderProgress( scan ) {
		var parts      = state.progressParts && state.progressParts.scanId === scan.id ? state.progressParts : buildProgress( scan );
		var p          = scan.progress || { files_done: 0, files_total: 0, percent: 0 };
		var components = scan.components || [];
		// The plugin being worked through now (not one whose large files wait for the end).
		var checking = components.filter(
			function ( c ) {
				return 'scanning' === c.status && 'retry' !== c.phase;
			}
		)[ 0 ];
		var slow     = state.nudging || config.cronDisabled || Date.now() - state.lastChange > NUDGE_AFTER_MS;

		/* translators: %s: PHP version. */
		parts.heading.textContent = sprintf( __( 'Checking your site for PHP %s', 'airworthy' ), scan.target_php );
		/* translators: %s: PHP version. */
		parts.what.textContent = sprintf( __( 'Airworthy is reading every PHP file in your plugins and theme, without running any of it, and checking each line against what changed up to PHP %s.', 'airworthy' ), scan.target_php );
		parts.bar.style.width  = Math.max( 2, p.percent ) + '%';
		parts.barWrap.setAttribute( 'aria-valuenow', String( p.percent ) );
		fill(
			parts.text,
			'queued' === scan.status ?
				__( 'Getting ready…', 'airworthy' ) :
				/* translators: 1: percent, 2: files checked, 3: total files. */
				sprintf( __( '%1$d%% · %2$s of %3$s files checked', 'airworthy' ), p.percent, p.files_done.toLocaleString(), p.files_total.toLocaleString() ),
			/* translators: 1: plugin or theme name, 2: its files checked, 3: its total files. */
			checking ? el( 'span', { class : 'airworthy-muted' }, ' · ' + sprintf( __( 'now: %1$s (%2$s of %3$s files)', 'airworthy' ), checking.name, checking.files.checked.toLocaleString(), checking.files.total.toLocaleString() ) ) : null
		);
		parts.note.textContent = slow ?
			__( 'Your site runs background tasks only when it has visitors, so keep this page open to speed things up.', 'airworthy' ) :
			__( 'This runs in the background. You can leave this page; you will see a notice when it is done.', 'airworthy' );

		parts.listCard.hidden = ! components.length;
		fill(
			parts.list,
			components.slice().sort(
				function ( a, b ) {
					return a.name.localeCompare( b.name );
				}
			).map(
				function ( c ) {
					var status;
					if ( 'done' === c.status ) {
							status = verdictBadge( c.verdict, true );
					} else if ( 'scanning' === c.status && 'retry' === c.phase ) {
						var left = Math.max( 1, c.files.total - c.files.checked - c.files.failed - c.files.skipped );
						/* translators: %d: number of files. */
						status = el( 'span', { class: 'airworthy-muted' }, sprintf( _n( '%d large file left for the end', '%d large files left for the end', left, 'airworthy' ), left ) );
					} else if ( 'scanning' === c.status ) {
						/* translators: 1: files checked, 2: total files. */
						status = el( 'span', { class: 'airworthy-muted' }, sprintf( __( 'checking %1$s / %2$s', 'airworthy' ), c.files.checked.toLocaleString(), c.files.total.toLocaleString() ) );
					} else {
						status = el( 'span', { class: 'airworthy-muted' }, __( 'waiting', 'airworthy' ) );
					}
					return el( 'li', null, el( 'span', null, c.name ), status );
				}
			)
		);
	}

	// ---------------------------------------------------------------------------------------
	// Results view.
	// ---------------------------------------------------------------------------------------

	function wporgCell( c, running ) {
		var w = c.wporg;
		if ( 'theme' === c.type && ! w ) {
			return el( 'span', { class: 'airworthy-muted' }, '—' );
		}
		if ( ! w ) {
			return el( 'span', { class: 'airworthy-muted' }, running ? __( 'Checking…', 'airworthy' ) : __( 'Not checked', 'airworthy' ) );
		}
		if ( 'closed' === w.status ) {
			return el(
				'div',
				null,
				el( 'span', { class: 'airworthy-badge airworthy-closed' }, __( 'Removed from WordPress.org', 'airworthy' ) ),
				el(
					'div',
					{ class: 'airworthy-small' },
					/* translators: 1: date, 2: reason. */
					w.closed_reason ? sprintf( __( 'Closed %1$s: %2$s. No more updates will come.', 'airworthy' ), formatDate( w.closed_date ), w.closed_reason ) :
						/* translators: %s: date. */
					sprintf( __( 'Closed %s. No more updates will come.', 'airworthy' ), formatDate( w.closed_date ) )
				)
			);
		}
		if ( 'not_listed' === w.status ) {
			var why = {
				external: __( 'Premium or custom (updated elsewhere).', 'airworthy' ),
				not_found: __( 'Premium or custom code.', 'airworthy' ),
				name_mismatch: __( 'Premium or custom code (a different plugin uses this name on WordPress.org).', 'airworthy' ),
			};
			return el( 'div', null, el( 'span', null, __( 'Not listed', 'airworthy' ) ), el( 'div', { class: 'airworthy-small airworthy-muted' }, why[ w.why ] || '' ) );
		}
		if ( 'unreachable' === w.status ) {
			return el( 'span', { class: 'airworthy-muted' }, __( 'Could not reach WordPress.org', 'airworthy' ) );
		}
		var flags = [];
		if ( w.abandoned ) {
			flags.push( el( 'span', { class: 'airworthy-badge airworthy-flag' }, __( 'Abandoned', 'airworthy' ) ) );
		}
		if ( w.requires_php_blocker ) {
			/* translators: %s: PHP version. */
			flags.push( el( 'span', { class: 'airworthy-badge airworthy-flag' }, sprintf( __( 'Requires PHP %s', 'airworthy' ), w.requires_php ) ) );
		}
		return el(
			'div',
			null,
			flags.length ? el( 'div', null, flags ) : null,
			el(
				'div',
				{ class: 'airworthy-small' },
				/* translators: %s: date. */
				w.last_updated ? sprintf( __( 'Updated %s', 'airworthy' ), formatDate( w.last_updated ) ) : null
			),
			w.tested ? el(
				'div',
				{ class : 'airworthy-small' + ( w.tested_warning ? ' airworthy-warn' : '' ) },
				w.tested_warning ?
					/* translators: 1: WordPress version, 2: number of releases. */
					sprintf( _n( 'Tested up to WordPress %1$s (%2$d release behind)', 'Tested up to WordPress %1$s (%2$d releases behind)', w.tested_behind, 'airworthy' ), w.tested, w.tested_behind ) :
					/* translators: %s: WordPress version. */
				sprintf( __( 'Tested up to WordPress %s', 'airworthy' ), w.tested )
			) : null,
			w.update ? el(
				'div',
				{ class : 'airworthy-small airworthy-update' },
				c.counts.errors || c.counts.warnings || c.counts.suppressed ?
					/* translators: %s: version. */
					sprintf( __( 'Update available: %s. Update it, then rescan: the update may fix these findings.', 'airworthy' ), w.update ) :
					/* translators: %s: version. */
				sprintf( __( 'Update available: %s.', 'airworthy' ), w.update )
			) : null
		);
	}

	function findingsSummary( c ) {
		var parts = [];
		if ( c.counts.errors ) {
			/* translators: %d: number. */
			parts.push( sprintf( _n( '%d error', '%d errors', c.counts.errors, 'airworthy' ), c.counts.errors ) );
		}
		if ( c.files.failed || c.files.skipped ) {
			/* translators: %d: number. */
			parts.push( sprintf( _n( '%d file not checked', '%d files not checked', c.files.failed + c.files.skipped, 'airworthy' ), c.files.failed + c.files.skipped ) );
		}
		if ( c.counts.suppressed ) {
			/* translators: %d: number. */
			parts.push( sprintf( _n( '%d suppressed', '%d suppressed', c.counts.suppressed, 'airworthy' ), c.counts.suppressed ) );
		}
		if ( c.counts.guarded ) {
			/* translators: %d: number. */
			parts.push( sprintf( _n( '%d guarded', '%d guarded', c.counts.guarded, 'airworthy' ), c.counts.guarded ) );
		}
		if ( c.counts.warnings ) {
			/* translators: %d: number. */
			parts.push( sprintf( _n( '%d deprecation', '%d deprecations', c.counts.warnings, 'airworthy' ), c.counts.warnings ) );
		}
		return parts.length ? parts.join( ' · ' ) : __( 'None', 'airworthy' );
	}

	/**
	 * Loads and shows one plugin's findings, a page at a time.
	 */
	function loadIssues( scan, c, container, page ) {
		var perPage = 100;
		apiFetch( { path: REST + '/scans/' + scan.id + '/components/' + c.id + '/issues?per_page=' + perPage + '&page=' + page, parse: false } ).then(
			function ( response ) {
				var total = parseInt( response.headers.get( 'X-WP-Total' ), 10 ) || 0;
				return response.json().then(
					function ( items ) {
						if ( 1 === page ) {
								fill( container );
							if ( ! items.length ) {
								container.appendChild( el( 'p', { class: 'airworthy-muted' }, __( 'No findings.', 'airworthy' ) ) );
								return;
							}
						}
						var list = container.querySelector( 'ul' ) || container.appendChild( el( 'ul', { class: 'airworthy-issues' } ) );
						items.forEach(
							function ( i ) {
								list.appendChild(
									el(
										'li',
										{ class: 'airworthy-issue airworthy-sev-' + i.severity + ( CONTEXT[ i.context ] ? ' airworthy-ctx-' + i.context : '' ) },
										el( 'span', { class: 'airworthy-sev' }, SEVERITY[ i.severity ] || i.severity, CONTEXT[ i.context ] ? ' (' + CONTEXT[ i.context ] + ')' : '' ),
										fileLocation( i.file, i.line ),
										el( 'span', { class: 'airworthy-msg' }, i.message )
									)
								);
							}
						);
						var shown = list.children.length;
						var more  = container.querySelector( '.airworthy-more' );
						if ( more ) {
							more.remove();
						}
						if ( shown < total ) {
								container.appendChild(
									el(
										'button',
										{ type: 'button', class: 'button-link airworthy-more', onclick: function () {
											loadIssues( scan, c, container, page + 1 );
										} },
										/* translators: 1: shown, 2: total. */
										sprintf( __( 'Show more (%1$d of %2$d shown)', 'airworthy' ), shown, total )
									)
								);
						}
					}
				);
			}
		).catch(
			function ( error ) {
				fill( container, el( 'p', { class: 'airworthy-note airworthy-note-error' }, errorMessage( error ) ) );
			}
		);
	}

	function componentRows( scan, c ) {
		var detailsId = 'airworthy-details-' + c.id;
		var details   = el( 'tr', { class: 'airworthy-details', id: detailsId, hidden: true }, el( 'td', { colspan: 5 }, el( 'div', { class: 'airworthy-issues-wrap' } ) ) );
		/* translators: %s: plugin or theme name. */
		var toggle = el(
			'button',
			{ type: 'button', class: 'button-link', 'aria-expanded': 'false', 'aria-controls': detailsId, 'aria-label': sprintf( /* translators: %s: plugin or theme name. */ __( 'Details for %s', 'airworthy' ), c.name ), onclick: function () {
				var open = 'true' !== toggle.getAttribute( 'aria-expanded' );
				toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				toggle.textContent = open ? __( 'Hide details', 'airworthy' ) : __( 'Details', 'airworthy' );
				details.hidden     = ! open;
				if ( open && ! details.getAttribute( 'data-loaded' ) ) {
					details.setAttribute( 'data-loaded', '1' );
					var wrap = details.querySelector( '.airworthy-issues-wrap' );
					fill( wrap, el( 'p', { class: 'airworthy-muted' }, __( 'Loading…', 'airworthy' ) ) );
					loadIssues( scan, c, wrap, 1 );
				}
			} },
			__( 'Details', 'airworthy' )
		);
		/* translators: %s: plugin or theme name. */
		var rescan = el(
			'button',
			{ type: 'button', class: 'button-link', 'aria-label': sprintf( /* translators: %s: plugin or theme name. */ __( 'Rescan %s', 'airworthy' ), c.name ), onclick: function () {
				rescan.disabled = true;
				apiFetch( { path: REST + '/scans/' + scan.id + '/components/' + c.id + '/rescan', method: 'POST' } ).then(
					function ( s ) {
						state.scan = s;
						/* translators: %s: plugin or theme name. */
						announce( sprintf( __( 'Checking %s again.', 'airworthy' ), c.name ) );
						startProgress();
					}
				).catch(
					function ( error ) {
						rescan.disabled = false;
						window.alert( errorMessage( error ) );
					}
				);
			} },
			__( 'Rescan', 'airworthy' )
		);
		var v      = VERDICTS[ c.verdict ];

		var row = el(
			'tr',
			{ class: 'airworthy-row airworthy-row-' + ( c.verdict || 'pending' ) },
			el(
				'td',
				{ 'data-label': __( 'Plugin or theme', 'airworthy' ) },
				el( 'strong', null, c.name ),
				el(
					'div',
					{ class: 'airworthy-small airworthy-muted' },
					c.version ? sprintf( /* translators: %s: plugin or theme version number. */ __( 'Version %s', 'airworthy' ), c.version ) : '',
					'theme' === c.type ? ' · ' + __( 'theme', 'airworthy' ) : ( c.active ? '' : ' · ' + __( 'inactive', 'airworthy' ) )
				)
			),
			el(
				'td',
				{ 'data-label': __( 'Verdict', 'airworthy' ) },
				verdictBadge( c.verdict, isRunning( scan ) ),
				v ? el( 'div', { class : 'airworthy-small' }, 'blocker' === c.verdict ? sprintf( v.line, scan.target_php ) : v.line ) : null
			),
			el( 'td', { 'data-label': __( 'Findings', 'airworthy' ) }, el( 'span', { class: 'airworthy-small' }, findingsSummary( c ) ) ),
			el( 'td', { 'data-label': __( 'WordPress.org', 'airworthy' ) }, wporgCell( c, 'off' !== scan.wporg && ( isRunning( scan ) || wporgPending( scan ) ) ) ),
			el( 'td', { class: 'airworthy-actions' }, toggle, el( 'span', { class: 'airworthy-sep' }, ' · ' ), rescan )
		);
		return [ row, details ];
	}

	/**
	 * Lists every file that couldn't be checked, with its plugin and the reason.
	 */
	function loadUnchecked( scan, card ) {
		apiFetch( { path: REST + '/scans/' + scan.id + '/unchecked' } ).then(
			function ( rows ) {
				if ( ! rows.length ) {
						card.hidden = true;
						return;
				}
				fill(
					card,
					el(
						'h3',
						null,
						/* translators: %d: number of files. */
						sprintf( _n( '%d file could not be checked', '%d files could not be checked', rows.length, 'airworthy' ), rows.length )
					),
					el( 'p', null, __( 'Airworthy gives these plugins an Unknown verdict unless it already found a Blocker. Raising your server’s PHP memory limit usually lets the large ones be checked.', 'airworthy' ) ),
					el(
						'table',
						{ class: 'widefat striped airworthy-unchecked-table' },
						el( 'caption', { class: 'screen-reader-text' }, __( 'Files that could not be checked', 'airworthy' ) ),
						el(
							'thead',
							null,
							el(
								'tr',
								null,
								el( 'th', { scope: 'col' }, __( 'Plugin or theme', 'airworthy' ) ),
								el( 'th', { scope: 'col' }, __( 'File', 'airworthy' ) ),
								el( 'th', { scope: 'col' }, __( 'Reason', 'airworthy' ) )
							)
						),
						el(
							'tbody',
							null,
							rows.map(
								function ( r ) {
									return el(
										'tr',
										null,
										el( 'td', { 'data-label': __( 'Plugin or theme', 'airworthy' ) }, r.component ),
										el( 'td', { 'data-label': __( 'File', 'airworthy' ) }, fileLocation( r.file, 0 ) ),
										el( 'td', { 'data-label': __( 'Reason', 'airworthy' ) }, UNCHECKED[ r.reason ] || r.message )
									);
								}
							)
						)
					)
				);
				card.hidden = false;
			}
		).catch(
			function () {
				card.hidden = true;
			}
		);
	}

	function renderResults( scan, moveFocus ) {
		clearTimeout( state.pollTimer );
		clearTimeout( state.wporgTimer );
		state.scan     = scan;
		var components = scan.components || [];
		var visible    = state.blockersOnly ? components.filter(
			function ( c ) {
				return 'blocker' === c.verdict || 'unknown' === c.verdict;
			}
		) : components;
		var closed     = components.filter(
			function ( c ) {
				return c.wporg && 'closed' === c.wporg.status;
			}
		);

		// Counted here from the rows, so they're right for stopped scans and rescans too.
		var counts = {};
		components.forEach(
			function ( c ) {
				counts[ c.verdict ] = ( counts[ c.verdict ] || 0 ) + 1;
			}
		);
		var summary = el(
			'ul',
			{ class: 'airworthy-summary' },
			ORDER.map(
				function ( key ) {
					return el(
						'li',
						{ class: 'airworthy-summary-' + key },
						el( 'span', { class: 'airworthy-summary-count' }, String( counts[ key ] || 0 ) ),
						el( 'span', { class: 'airworthy-summary-label' }, VERDICTS[ key ].label )
					);
				}
			)
		);

		var status = null;
		if ( 'cancelled' === scan.status ) {
			status = el( 'p', { class: 'airworthy-note airworthy-note-warning' }, __( 'This scan was stopped before it finished. The results below are incomplete.', 'airworthy' ) );
		} else if ( 'failed' === scan.status ) {
			status = el( 'p', { class: 'airworthy-note airworthy-note-error' }, scan.error || __( 'The scan could not be completed.', 'airworthy' ) );
		}
		var wporgNote = null;
		if ( 'off' === scan.wporg ) {
			wporgNote = el( 'p', { class: 'airworthy-note' }, __( 'WordPress.org checks were off for this scan, so closed or abandoned plugins aren\'t flagged. You can turn them on when you start the next scan.', 'airworthy' ) );
		} else if ( 'offline' === scan.wporg || 'partial' === scan.wporg ) {
			wporgNote = el( 'p', { class: 'airworthy-note' }, __( 'WordPress.org could not be reached for some plugins, so their update and maintenance details are missing. Run the scan again later to fill them in.', 'airworthy' ) );
		}

		var uncheckedCard = el( 'div', { class: 'airworthy-card airworthy-unchecked-card', hidden: true } );

		fill(
			views.results,
			el(
				'div',
				{ class: 'airworthy-card' },
				el(
					'div',
					{ class: 'airworthy-results-head' },
					el(
						'div',
						null,
						el(
							'h2',
							null,
							/* translators: %s: PHP version. */
							sprintf( __( 'Results for PHP %s', 'airworthy' ), scan.target_php )
						),
						el(
							'p',
							{ class: 'airworthy-muted' },
							scan.finished_at ?
								sprintf(
									'cancelled' === scan.status ?
										/* translators: 1: date and time, 2: server PHP version. */
										__( 'Stopped %1$s · server runs PHP %2$s', 'airworthy' ) :
										/* translators: 1: date and time, 2: server PHP version. */
										__( 'Checked %1$s · server runs PHP %2$s', 'airworthy' ),
									formatDateTime( scan.finished_at ),
									scan.host_php
								) :
								/* translators: %s: server PHP version. */
							sprintf( __( 'Server runs PHP %s', 'airworthy' ), scan.host_php )
						)
					),
					el(
						'div',
						{ class: 'airworthy-head-actions' },
						el( 'a', { class: 'button', href: scan.export_url }, __( 'Export CSV', 'airworthy' ) ),
						el(
							'button',
							{ type: 'button', class: 'button button-primary', onclick: function () {
								showStart( true );
							} },
							__( 'New scan', 'airworthy' )
						)
					)
				),
				summary,
				status,
				el( 'p', { class: 'airworthy-honest' }, __( 'Airworthy reads your plugins’ code without running it. A check like this can’t catch everything: some problems only show up when code runs, and a future version will add runtime checking to confirm these results.', 'airworthy' ) )
			),
			closed.length ? el(
				'div',
				{ class : 'airworthy-card airworthy-closed-card' },
				el(
					'h3',
					null,
					/* translators: %d: number of plugins. */
					sprintf( _n( '%d plugin has been removed from WordPress.org', '%d plugins have been removed from WordPress.org', closed.length, 'airworthy' ), closed.length )
				),
				el( 'p', null, __( 'No more updates, including security fixes, will come for these. Look for a replacement.', 'airworthy' ) ),
				el(
					'ul',
					null,
					closed.map(
						function ( c ) {
							return el(
								'li',
								null,
								el( 'strong', null, c.name ),
								' · ',
								c.wporg.closed_reason ?
								/* translators: 1: date, 2: reason. */
								sprintf( __( 'closed %1$s: %2$s', 'airworthy' ), formatDate( c.wporg.closed_date ), c.wporg.closed_reason ) :
								/* translators: %s: date. */
								sprintf( __( 'closed %s', 'airworthy' ), formatDate( c.wporg.closed_date ) )
							);
						}
					)
				)
			) : null,
			wporgNote,
			uncheckedCard,
			el(
				'div',
				{ class: 'airworthy-toolbar' },
				el(
					'label',
					null,
					el(
						'input',
						{ type: 'checkbox', id: 'airworthy-attention-only', checked: state.blockersOnly, onchange: function ( e ) {
							state.blockersOnly = e.target.checked;
							renderResults( state.scan );
							document.getElementById( 'airworthy-attention-only' ).focus(); // The table was redrawn.
						} }
					),
					' ',
					__( 'Show only plugins that need attention (Blocker or Unknown)', 'airworthy' )
				),
				el(
					'span',
					{ class: 'airworthy-muted' },
					/* translators: 1: shown, 2: total. */
					sprintf( __( 'Showing %1$d of %2$d', 'airworthy' ), visible.length, components.length )
				)
			),
			el(
				'table',
				{ class: 'widefat airworthy-table' },
				el( 'caption', { class: 'screen-reader-text' }, __( 'Results by plugin or theme, most serious first', 'airworthy' ) ),
				el(
					'thead',
					null,
					el(
						'tr',
						null,
						el( 'th', { scope: 'col' }, __( 'Plugin or theme', 'airworthy' ) ),
						el( 'th', { scope: 'col' }, __( 'Verdict', 'airworthy' ) ),
						el( 'th', { scope: 'col' }, __( 'Findings', 'airworthy' ) ),
						el( 'th', { scope: 'col' }, __( 'WordPress.org', 'airworthy' ) ),
						el( 'th', { scope: 'col' }, el( 'span', { class: 'screen-reader-text' }, __( 'Actions', 'airworthy' ) ) )
					)
				),
				el(
					'tbody',
					null,
					visible.length ? visible.map(
						function ( c ) {
							return componentRows( scan, c );
						}
					) : el(
						'tr',
						null,
						el( 'td', { colspan: 5 }, __( 'Nothing needs attention.', 'airworthy' ) )
					)
				)
			)
		);
		show( 'results' );
		if ( components.some(
			function ( c ) {
				return c.files.failed || c.files.skipped;
			}
		) ) {
			loadUnchecked( scan, uncheckedCard );
		}
		if ( moveFocus ) {
			focusHeading( views.results );
		}
		watchWporg( scan );
	}

	function wporgPending( scan ) {
		return ! ! scan && 'complete' === scan.status && 'pending' === scan.wporg;
	}

	/**
	 * WordPress.org lookups can still be running when the file scan finishes. Keep asking
	 * until they're done (running them from this page if nothing moves, like maybeNudge),
	 * then redraw: verdicts can change, e.g. a plugin whose "Requires PHP" is above the target
	 * becomes a Blocker.
	 */
	function watchWporg( scan ) {
		if ( ! wporgPending( scan ) ) {
			return;
		}
		var since        = Date.now();
		var check        = function () {
			var stalled = Date.now() - since > NUDGE_AFTER_MS;
			var nudge   = stalled ? apiFetch( { path : REST + '/scans/' + scan.id + '/nudge', method : 'POST' } ) : Promise.resolve();
			nudge.catch( function () {} ).then(
				function () {
					if ( stalled ) {
						since = Date.now();
					}
					return apiFetch( { path: REST + '/scans/' + scan.id } );
				}
			).then(
				function ( fresh ) {
					if ( views.results.hidden || ! state.scan || state.scan.id !== fresh.id ) {
						return; // The user moved on.
					}
					if ( wporgPending( fresh ) ) {
						state.wporgTimer = setTimeout( check, POLL_MS );
						return;
					}
					renderResults( fresh, views.results.contains( document.activeElement ) );
					announce( __( 'WordPress.org details added.', 'airworthy' ) );
				}
			).catch(
				function () {
					state.wporgTimer = setTimeout( check, POLL_MS * 2 );
				}
			);
		};
		state.wporgTimer = setTimeout( check, POLL_MS );
	}

	// ---------------------------------------------------------------------------------------
	// Boot: show whatever state the site is in.
	// ---------------------------------------------------------------------------------------

	apiFetch( { path: REST + '/scans/current?seen=1' } ).then(
		function ( scan ) {
			if ( ! scan ) {
				showStart( false ); // No scan yet.
				return;
			}
			state.scan = scan;
			if ( 'queued' === scan.status || 'running' === scan.status ) {
					startProgress();
			} else {
				renderResults( scan );
			}
		}
	).catch(
		function () {
			showStart( false ); // No scan yet.
		}
	);
}() );
