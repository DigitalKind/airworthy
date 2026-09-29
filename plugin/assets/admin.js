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
	if ( ! views.start ) {
		autosaveSettings( document.querySelector( 'form.airworthy-settings[data-autosave]' ) );
		return; // The Settings tab: nothing else for this script to do.
	}

	/**
	 * The Settings tab saves each change as it's made (the form still works without script,
	 * through its Save button): posts the form to admin-post.php in the background.
	 */
	function autosaveSettings( form ) {
		if ( ! form || ! window.fetch || ! window.FormData ) {
			return;
		}
		var status = form.querySelector( '.airworthy-save-status' );
		var note   = form.querySelector( '.airworthy-autosave-note' );
		var row    = form.querySelector( '.airworthy-save-row' );
		if ( ! status || ! note || ! row ) {
			return;
		}
		var timer   = null;
		var saving  = null;
		var again   = false;
		var say     = function ( text, kind ) {
			status.textContent = text;
			status.className   = 'airworthy-save-status' + ( kind ? ' airworthy-save-' + kind : '' );
		};
		var save    = function () {
			if ( saving ) {
				again = true; // Save once more when this one is done, with the latest choices.
				return;
			}
			say( __( 'Saving…', 'airworthy' ) );
			// getAttribute: form.action would be the hidden input named "action".
			saving = window.fetch( form.getAttribute( 'action' ), { method: 'POST', body: new window.FormData( form ), credentials: 'same-origin' } ).then(
				function ( response ) {
					// Saved means admin-post.php redirected back to the Settings tab.
					if ( ! response.ok || -1 === response.url.indexOf( 'updated=1' ) ) {
						throw new Error( String( response.status ) );
					}
					say( __( '✓ Saved', 'airworthy' ), 'ok' );
				}
			).catch(
				function () {
					say( __( 'Not saved. Check your connection and click Save settings.', 'airworthy' ), 'error' );
					row.hidden = false;
				}
			).then(
				function () {
					saving = null;
					if ( again ) {
						again = false;
						save();
					}
				}
			);
		};
		note.hidden = false;
		row.hidden  = true;
		// Leaving within the moment a change takes to save: ask first.
		window.addEventListener(
			'beforeunload',
			function ( event ) {
				if ( timer || saving ) {
					event.preventDefault();
					event.returnValue = '';
				}
			}
		);
		form.addEventListener(
			'change',
			function () {
				clearTimeout( timer );
				timer = setTimeout(
					function () {
						timer = null;
						save();
					},
					300
				);
			}
		);
	}
	var state = {
		scan: null,
		pollTimer: null,
		wporgTimer: null,
		lastDone: -1,
		lastChange: Date.now(),
		nudging: false,
		groupOpen: {}, // Results groups the user opened or closed, by key.
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
			line: __( 'Some of its files could not be checked, so there is no verdict. Click Details on its row in the results to see which files, and why.', 'airworthy' ),
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

	/**
	 * Whether the scan compares from an older PHP version than its target (the usual case;
	 * the "between X and Y" wording only makes sense then).
	 */
	function isUpgrade( scan ) {
		return ! ! scan.from_php && 'undefined' !== typeof scan.target_php && parseFloat( scan.from_php ) < parseFloat( scan.target_php );
	}

	/**
	 * A key to the verdict badges: each badge with its one-line meaning (the same wording as
	 * the results table).
	 */
	function verdictLegend( scan ) {
		return el(
			'dl',
			{ class: 'airworthy-legend' },
			ORDER.map(
				function ( key ) {
					return el(
						'div',
						null,
						el( 'dt', null, el( 'span', { class: 'airworthy-badge airworthy-verdict-' + key }, VERDICTS[ key ].label ) ),
						el( 'dd', null, 'blocker' === key ? sprintf( VERDICTS[ key ].line, scan.target_php ) : VERDICTS[ key ].line )
					);
				}
			)
		);
	}

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
		existing: __( 'already so before this upgrade', 'airworthy' ),
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
			var from             = document.getElementById( 'airworthy-from' );
			apiFetch( { path: REST + '/scans', method: 'POST', data: { target: select.value, wporg: ! ! wporg && 'yes' === wporg.value, from: from ? from.value : '' } } ).then(
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
		state.rateStart = null;
		state.lastDone  = -1;
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
		var parts        = {
			heading: el( 'h2', null ),
			what: el( 'p', { class: 'airworthy-progress-what' } ),
			percent: el( 'div', { class: 'airworthy-big-percent', 'aria-hidden': 'true' } ),
			files: el( 'div', { class: 'airworthy-big-sub' } ),
			plugins: el( 'strong', null ),
			timeLeft: el( 'strong', null ),
			now: el( 'strong', null ),
			nowBar: el( 'span', null ),
			bar: el( 'span', null ),
			tally: el( 'div', { class: 'airworthy-tally' } ),
			note: el( 'p', { class: 'description' } ),
			list: el( 'ul', { class: 'airworthy-progress-list' } ),
		};
		var stat         = function ( label, value, extra ) {
			return el( 'div', { class: 'airworthy-stat' }, el( 'span', { class: 'airworthy-stat-label' }, label ), value, extra || null );
		};
		parts.barWrap    = el( 'div', { class: 'airworthy-bar airworthy-bar-live', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-label': __( 'Scan progress', 'airworthy' ) }, parts.bar );
		parts.hero       = el(
			'div',
			{ class: 'airworthy-progress-hero' },
			el( 'div', { class: 'airworthy-progress-number' }, parts.percent, parts.files ),
			el(
				'div',
				{ class: 'airworthy-stats' },
				stat( __( 'Plugins and themes done', 'airworthy' ), parts.plugins ),
				stat( __( 'Time left', 'airworthy' ), parts.timeLeft ),
				stat( __( 'Checking now', 'airworthy' ), parts.now, el( 'div', { class: 'airworthy-minibar' }, parts.nowBar ) )
			)
		);
		parts.legendCard = el(
			'div',
			{ class: 'airworthy-card', hidden: true },
			el( 'h3', null, __( 'What the verdicts mean', 'airworthy' ) ),
			verdictLegend( scan )
		);
		parts.listCard   = el(
			'div',
			{ class: 'airworthy-card', hidden: true },
			el( 'h3', null, __( 'Plugins and themes', 'airworthy' ) ),
			el( 'p', { class: 'airworthy-muted' }, __( 'Each plugin and theme gets a verdict as soon as its files are checked.', 'airworthy' ) ),
			parts.list
		);
		fill(
			views.progress,
			el(
				'div',
				{ class: 'airworthy-card' },
				parts.heading,
				parts.what,
				parts.hero,
				parts.barWrap,
				parts.tally,
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
			parts.legendCard,
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
				isUpgrade( scan ) ? item(
					__( 'What is new with this upgrade.', 'airworthy' ),
					/* translators: 1: PHP version the site runs, 2: target PHP version. */
					sprintf( __( 'Only changes after PHP %1$s, the version your site runs now, can be Blockers: code hit by older changes already fails or never runs today, and PHP %2$s doesn\'t change that. Those are still listed, marked as already on your PHP.', 'airworthy' ), scan.from_php, scan.target_php )
				) : null,
				'off' === scan.wporg ? null : item(
					__( 'WordPress.org details.', 'airworthy' ),
					/* translators: %s: PHP version. */
					sprintf( __( 'Whether each plugin was removed from WordPress.org, looks abandoned, is tested with recent WordPress, or needs a newer PHP version than PHP %s.', 'airworthy' ), scan.target_php )
				)
			),
			el( 'p', { class: 'airworthy-muted' }, __( 'Nothing is run or changed: Airworthy only reads the files.', 'airworthy' ) )
		);
	}

	/**
	 * Time left, from the speed seen since this page started watching the scan.
	 */
	function timeLeft( p ) {
		var now = Date.now();
		if ( ! state.rateStart || p.files_done < state.rateStart.done ) {
			state.rateStart = { t: now, done: p.files_done };
		}
		var seconds = ( now - state.rateStart.t ) / 1000;
		var checked = p.files_done - state.rateStart.done;
		if ( seconds < 15 || checked <= 0 ) {
			return __( 'Working it out…', 'airworthy' );
		}
		var left = ( p.files_total - p.files_done ) / ( checked / seconds );
		if ( left < 60 ) {
			return __( 'Less than a minute', 'airworthy' );
		}
		var minutes = Math.ceil( left / 60 );
		/* translators: %d: minutes. */
		return sprintf( _n( 'About %d minute', 'About %d minutes', minutes, 'airworthy' ), minutes );
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
		parts.what.textContent = isUpgrade( scan ) ?
			/* translators: 1: PHP version the site runs, 2: target PHP version. */
			sprintf( __( 'Reading every PHP file in your plugins and theme (without running any of it) and checking each line against what changes between PHP %1$s and PHP %2$s.', 'airworthy' ), scan.from_php, scan.target_php ) :
			/* translators: %s: PHP version. */
			sprintf( __( 'Reading every PHP file in your plugins and theme (without running any of it) and checking each line against what changed up to PHP %s.', 'airworthy' ), scan.target_php );
		parts.bar.style.width = Math.max( 2, p.percent ) + '%';
		parts.barWrap.setAttribute( 'aria-valuenow', String( p.percent ) );
		parts.percent.textContent = 'queued' === scan.status ? '…' : p.percent + '%';
		parts.files.textContent   = 'queued' === scan.status ?
			__( 'Getting ready…', 'airworthy' ) :
			/* translators: 1: files checked, 2: total files. */
			sprintf( __( '%1$s of %2$s files checked', 'airworthy' ), p.files_done.toLocaleString(), p.files_total.toLocaleString() );

		var done = components.filter(
			function ( c ) {
				return 'done' === c.status;
			}
		);
		/* translators: 1: plugins and themes done, 2: total. */
		parts.plugins.textContent  = sprintf( __( '%1$d of %2$d', 'airworthy' ), done.length, components.length );
		parts.timeLeft.textContent = timeLeft( p );
		parts.now.textContent      = checking ? checking.name : ( components.length && done.length < components.length ? __( 'Large files, one at a time', 'airworthy' ) : '—' );
		parts.nowBar.style.width   = checking && checking.files.total ? Math.round( 100 * checking.files.checked / checking.files.total ) + '%' : '0';

		// Verdicts so far, as badges.
		var tally = {};
		done.forEach(
			function ( c ) {
				tally[ c.verdict ] = ( tally[ c.verdict ] || 0 ) + 1;
			}
		);
		fill(
			parts.tally,
			done.length ? el( 'span', { class : 'airworthy-muted' }, __( 'So far:', 'airworthy' ) ) : null,
			ORDER.filter(
				function ( key ) {
					return tally[ key ];
				}
			).map(
				function ( key ) {
					return el( 'span', { class: 'airworthy-badge airworthy-verdict-' + key }, tally[ key ] + ' ' + VERDICTS[ key ].label );
				}
			)
		);
		parts.note.textContent = slow ?
			__( 'Your site runs background tasks only when it has visitors, so keep this page open to speed things up.', 'airworthy' ) :
			__( 'This runs in the background. You can leave this page; you will see a notice when it is done.', 'airworthy' );

		parts.listCard.hidden   = ! components.length;
		parts.legendCard.hidden = ! components.length;
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

	/**
	 * WordPress.org's closure reason in plain words ("Author Request" -> "the author withdrew
	 * it"); an unknown reason is shown as WordPress.org gave it.
	 */
	function closedReason( reason ) {
		var key   = String( reason || '' ).toLowerCase();
		var plain = {
			'security issue': __( 'because of a security problem', 'airworthy' ),
			'author request': __( 'the author withdrew it', 'airworthy' ),
			'guideline violation': __( 'for breaking WordPress.org\'s rules', 'airworthy' ),
			'licensing/trademark violation': __( 'over a licence or trademark problem', 'airworthy' ),
			'merged into core': __( 'its features are now part of WordPress itself', 'airworthy' ),
			unused: __( 'because it was no longer used', 'airworthy' ),
		};
		return plain[ key ] || reason || __( 'no reason given', 'airworthy' );
	}

	/**
	 * What to do about a closed plugin, depending on whether it is active on this site.
	 */
	function closedAdvice( c ) {
		var key = String( ( c.wporg && c.wporg.closed_reason ) || '' ).toLowerCase();
		if ( ! c.active ) {
			return __( 'It isn\'t active on your site, so the simplest fix is to delete it. Inactive plugins still leave their files on the server.', 'airworthy' );
		}
		if ( 'security issue' === key ) {
			return __( 'It is active on your site: replace it as soon as you can.', 'airworthy' );
		}
		if ( 'merged into core' === key ) {
			return __( 'It is active on your site, but WordPress now does this itself: you can probably remove it.', 'airworthy' );
		}
		return __( 'It is active on your site: find a maintained replacement before you upgrade PHP.', 'airworthy' );
	}

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
					/* translators: 1: date, 2: reason in plain words, e.g. "the author withdrew it". */
					w.closed_reason ? sprintf( __( 'Closed %1$s: %2$s. No more updates will come.', 'airworthy' ), formatDate( w.closed_date ), closedReason( w.closed_reason ) ) :
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
			w.update && ! c.update ? el(
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

	/**
	 * "Update available" under a plugin's name, with a one-click update link for users who
	 * can update. Airworthy checks the plugin again by itself once WordPress has updated it.
	 */
	function updateLine( c ) {
		if ( ! c.update ) {
			return null;
		}
		var fixable = c.counts.errors || c.counts.warnings || c.counts.suppressed;
		return el(
			'div',
			{ class: 'airworthy-update-line' },
			el(
				'span',
				{ class: 'airworthy-update-pill' },
				/* translators: %s: version number. */
				sprintf( __( 'Update available: %s', 'airworthy' ), c.update.version )
			),
			c.update.url ? el(
				'a',
				{ href: c.update.url, 'aria-label': sprintf( /* translators: %s: plugin or theme name. */ __( 'Update %s now', 'airworthy' ), c.name ) },
				__( 'Update now', 'airworthy' )
			) : null,
			fixable ? el( 'span', { class : 'airworthy-muted' }, __( 'The update may clear these findings; Airworthy checks again after you update.', 'airworthy' ) ) : null
		);
	}

	/**
	 * Results groups, by what to do next. Components without a verdict yet (a stopped scan)
	 * get their own group.
	 */
	function resultGroups( scan, components ) {
		var groups = [
			{
				key: 'fix',
				verdicts: [ 'blocker', 'unknown' ],
				title: __( 'Fix before you upgrade', 'airworthy' ),
				/* translators: %s: PHP version. */
				note: sprintf( __( 'These will not work on PHP %s, or could not be fully checked.', 'airworthy' ), scan.target_php ),
				open: true,
		},
			{
				key: 'watch',
				verdicts: [ 'suppressed', 'warnings' ],
				title: __( 'Keep an eye on', 'airworthy' ),
				/* translators: %s: PHP version. */
				note: sprintf( __( 'These work on PHP %s. Keep them updated so they keep working on later versions.', 'airworthy' ), scan.target_php ),
				open: true,
		},
			{
				key: 'good',
				verdicts: [ 'guarded', 'ready' ],
				title: __( 'All good', 'airworthy' ),
				note: __( 'Nothing to do for these.', 'airworthy' ),
				open: false,
		},
			{
				key: 'pending',
				verdicts: [ null ],
				title: __( 'Not checked yet', 'airworthy' ),
				note: __( 'The scan stopped before it reached these.', 'airworthy' ),
				open: false,
		},
		];
		return groups.map(
			function ( g ) {
				g.items = components.filter(
					function ( c ) {
						return g.verdicts.indexOf( c.verdict || null ) !== -1;
					}
				);
				if ( undefined !== state.groupOpen[ g.key ] ) {
					g.open = state.groupOpen[ g.key ];
				}
				return g;
			}
		).filter(
			function ( g ) {
				return g.items.length;
			}
		);
	}

	function groupBody( scan, g ) {
		var body   = el( 'tbody', { class: 'airworthy-group airworthy-group-' + g.key + ( g.open ? '' : ' airworthy-group-closed' ) } );
		var toggle = el(
			'button',
			{ type: 'button', class: 'airworthy-group-toggle', 'aria-expanded': g.open ? 'true' : 'false', onclick : function () {
				var open = 'true' !== toggle.getAttribute( 'aria-expanded' );
				toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				body.classList.toggle( 'airworthy-group-closed', ! open );
				state.groupOpen[ g.key ] = open;
			} },
			el( 'span', { class: 'airworthy-group-arrow', 'aria-hidden': 'true' } ),
			el( 'span', { class: 'airworthy-group-title' }, g.title ),
			el( 'span', { class: 'airworthy-group-count' }, String( g.items.length ) )
		);
		body.appendChild( el( 'tr', { class: 'airworthy-group-head' }, el( 'th', { colspan: 5, scope: 'rowgroup' }, toggle, el( 'span', { class: 'airworthy-group-note' }, g.note ) ) ) );
		g.items.forEach(
			function ( c ) {
				componentRows( scan, c ).forEach(
					function ( tr ) {
						body.appendChild( tr );
					}
				);
			}
		);
		return body;
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
		if ( c.counts.existing ) {
			/* translators: %d: number. */
			parts.push( sprintf( _n( '%d already on your PHP', '%d already on your PHP', c.counts.existing, 'airworthy' ), c.counts.existing ) );
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
				),
				updateLine( c )
			),
			el(
				'td',
				{ 'data-label': __( 'Verdict', 'airworthy' ) },
				verdictBadge( c.verdict, isRunning( scan ) ),
				v ? el(
					'div',
					{ class : 'airworthy-small' },
					'blocker' === c.verdict ? sprintf( v.line, scan.target_php ) :
					( c.counts.existing && ( 'ready' === c.verdict || 'warnings' === c.verdict ) ?
						/* translators: %s: PHP version. */
						sprintf( __( 'Nothing new breaks on PHP %s. Some old code already can\'t run on your current PHP: see the details.', 'airworthy' ), scan.target_php ) :
						v.line )
				) : null
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
						{ class: 'airworthy-summary-' + key + ( counts[ key ] ? ' airworthy-has-items' : '' ) },
						el( 'span', { class: 'airworthy-summary-count' }, String( counts[ key ] || 0 ) ),
						el( 'span', { class: 'airworthy-summary-label' }, VERDICTS[ key ].label )
					);
				}
			)
		);

		// One coloured sentence for the whole site, above the tiles (finished scans only).
		var headline = null;
		if ( 'complete' === scan.status ) {
			var needs = counts.blocker || 0;
			var kind  = needs ? 'bad' : ( counts.unknown ? 'unknown' : 'good' );
			var title = needs ?
				/* translators: 1: number of plugins/themes, 2: PHP version. */
				sprintf( _n( '%1$d plugin or theme needs attention before you upgrade to PHP %2$s.', '%1$d plugins or themes need attention before you upgrade to PHP %2$s.', needs, 'airworthy' ), needs, scan.target_php ) :
				( counts.unknown ?
					/* translators: %s: PHP version. */
					sprintf( __( 'Nothing found that would break on PHP %s, but some files could not be checked.', 'airworthy' ), scan.target_php ) :
					/* translators: %s: PHP version. */
					sprintf( __( 'Good news: nothing Airworthy found will break when you upgrade to PHP %s.', 'airworthy' ), scan.target_php ) );
			var sub = needs ?
				__( 'Open their details below to see what would break, and whether an update fixes it.', 'airworthy' ) :
				( counts.unknown ?
					/* translators: %d: number of plugins/themes. */
					sprintf( _n( '%d plugin or theme has no verdict yet: the files that could not be checked are listed below.', '%d plugins or themes have no verdict yet: the files that could not be checked are listed below.', counts.unknown, 'airworthy' ), counts.unknown ) :
					( counts.warnings ?
						sprintf(
							/* translators: 1: number of plugins/themes, 2: PHP version. */
							_n( '%1$d plugin or theme uses PHP features that still work on PHP %2$s but will be removed in a later PHP version. Your site won\'t break because of them now, and keeping it updated usually fixes them before that happens.', '%1$d plugins or themes use PHP features that still work on PHP %2$s but will be removed in a later PHP version. Your site won\'t break because of them now, and keeping them updated usually fixes them before that happens.', counts.warnings, 'airworthy' ),
							counts.warnings,
							scan.target_php
						) :
						__( 'Test on a staging copy before you switch all the same: some problems only show up when code runs.', 'airworthy' ) ) );
			headline = el(
				'div',
				{ class: 'airworthy-headline airworthy-headline-' + kind },
				el( 'span', { class: 'airworthy-headline-icon', 'aria-hidden': 'true' }, 'good' === kind ? '✓' : ( 'bad' === kind ? '!' : '?' ) ),
				el( 'div', null, el( 'strong', null, title ), el( 'p', null, sub ) )
			);
		}

		// Updates waiting for plugins with findings: often the quickest fix.
		var withUpdates = components.filter(
			function ( c ) {
				return c.update && ( c.counts.errors || c.counts.warnings || c.counts.suppressed || 'unknown' === c.verdict );
			}
		).length;
		var updatesNote = withUpdates ? el(
			'p',
			{ class: 'airworthy-note airworthy-updates-note' },
			sprintf(
				/* translators: %d: number of plugins/themes. */
				_n( '%d plugin or theme with findings has an update waiting. Updating often clears findings, and Airworthy checks each one again by itself after it is updated.', '%d plugins or themes with findings have updates waiting. Updating often clears findings, and Airworthy checks each one again by itself after it is updated.', withUpdates, 'airworthy' ),
				withUpdates
			),
			scan.updates_url ? [ ' ', el( 'a', { href : scan.updates_url }, __( 'Go to Updates', 'airworthy' ) ) ] : null
		) : null;

		// A stopped scan: a banner where the headline goes, with Continue scan right in it.
		var newScanClass = scan.resumable ? 'button' : 'button button-primary';
		var status       = null;
		if ( 'cancelled' === scan.status ) {
			var checked = components.filter(
				function ( c ) {
					return 'done' === c.status;
				}
			).length;
			var total   = ( scan.progress && scan.progress.components ) || components.length;
			var resume  = null;
			if ( scan.resumable ) {
				resume = el(
					'button',
					{ type: 'button', class: 'button button-primary airworthy-headline-action', onclick: function ( event ) {
						event.target.disabled = true;
						apiFetch( { path: REST + '/scans/' + scan.id + '/resume', method: 'POST' } ).then(
							function ( s ) {
								state.scan = s;
								announce( __( 'Continuing the scan.', 'airworthy' ) );
								startProgress(); // Fresh progress screen and time estimate.
							}
						).catch(
							function ( error ) {
								event.target.disabled = false;
								window.alert( errorMessage( error ) );
							}
						);
					} },
					__( 'Continue scan', 'airworthy' )
				);
			}
			headline = el(
				'div',
				{ class: 'airworthy-headline airworthy-headline-stopped' },
				el( 'span', { class: 'airworthy-headline-icon', 'aria-hidden': 'true' }, '❚❚' ),
				el(
					'div',
					{ class: 'airworthy-headline-text' },
					el(
						'strong',
						null,
						total ?
							/* translators: 1: percent, 2: plugins/themes checked, 3: total. */
							sprintf( __( 'Scan stopped at %1$d%%: %2$d of %3$d plugins and themes checked.', 'airworthy' ), ( scan.progress && scan.progress.percent ) || 0, checked, total ) :
							__( 'Scan stopped before it started checking.', 'airworthy' )
					),
					el(
						'p',
						null,
						scan.resumable ?
						__( 'The results below are incomplete. Continue scan picks up where it stopped, and what has been checked keeps its results.', 'airworthy' ) :
						__( 'The results below are incomplete. Start a new scan to check everything.', 'airworthy' )
					)
				),
				resume
			);
		} else if ( 'failed' === scan.status ) {
			status = el( 'p', { class: 'airworthy-note airworthy-note-error' }, scan.error || __( 'The scan could not be completed.', 'airworthy' ) );
		}
		// Problems that already apply on the PHP version the site runs now: shown, not Blockers.
		var existingTotal = components.reduce(
			function ( sum, c ) {
				return sum + ( c.counts.existing || 0 );
			},
			0
		);
		// Whether the version compared from is this server's own (it can be another, for a copied site).
		var fromIsServer = scan.from_php && scan.host_php && 0 === String( scan.host_php ).indexOf( scan.from_php + '.' );
		var existingNote = existingTotal && scan.from_php ? el(
			'p',
			{ class: 'airworthy-note' },
			sprintf(
				fromIsServer ?
					/* translators: 1: number of problems, 2: PHP version. */
					_n( '%1$d problem already applies on PHP %2$s, the version your site runs now, so it isn\'t caused by this upgrade: that code either never runs on your site today or is already failing. It isn\'t counted as a Blocker, and is listed in its plugin\'s details.', '%1$d problems already apply on PHP %2$s, the version your site runs now, so they aren\'t caused by this upgrade: that code either never runs on your site today or is already failing. They aren\'t counted as Blockers, and are listed in each plugin\'s details.', existingTotal, 'airworthy' ) :
					/* translators: 1: number of problems, 2: PHP version. */
					_n( '%1$d problem already applies on PHP %2$s, the version you are comparing from, so it isn\'t caused by this upgrade. It isn\'t counted as a Blocker, and is listed in its plugin\'s details.', '%1$d problems already apply on PHP %2$s, the version you are comparing from, so they aren\'t caused by this upgrade. They aren\'t counted as Blockers, and are listed in each plugin\'s details.', existingTotal, 'airworthy' ),
				existingTotal,
				scan.from_php
			)
		) : null;
		// What the site's settings left out of this scan (inactive plugins, the ignore list).
		var skipped     = scan.skipped || { inactive: 0, ignored: 0 };
		var skippedNote = skipped.inactive || skipped.ignored ? el(
			'p',
			{ class: 'airworthy-note' },
			[
				skipped.inactive ?
					/* translators: %d: number of plugins. */
					sprintf( _n( '%d inactive plugin was not checked.', '%d inactive plugins were not checked.', skipped.inactive, 'airworthy' ), skipped.inactive ) : '',
				skipped.ignored ?
					/* translators: %d: number of plugins and themes. */
					sprintf( _n( '%d plugin or theme on your ignore list was not checked.', '%d plugins and themes on your ignore list were not checked.', skipped.ignored, 'airworthy' ), skipped.ignored ) : '',
			].filter( Boolean ).join( ' ' ) + ' ',
			el( 'a', { href: scan.settings_url }, __( 'Change this in Settings', 'airworthy' ) )
		) : null;
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
								) + ( scan.from_php ? ' · ' + sprintf( /* translators: %s: PHP version. */ __( 'compared from PHP %s', 'airworthy' ), scan.from_php ) : '' ) :
								/* translators: %s: server PHP version. */
							sprintf( __( 'Server runs PHP %s', 'airworthy' ), scan.host_php )
						),
						scan.host_support ? el( 'p', { class : 'airworthy-support airworthy-support-' + scan.host_support.level }, scan.host_support.text ) : null
					),
					el(
						'div',
						{ class: 'airworthy-head-actions' },
						el( 'a', { class: 'button', href: scan.export_url }, __( 'Export CSV', 'airworthy' ) ),
						( config.resultsActions || [] ).map(
							function ( a ) {
								return el( 'a', { class: 'button', href: a.url.replace( '__SCAN__', String( scan.id ) ), target: '_blank', rel: 'noopener' }, a.label );
							}
						),
						el(
							'button',
							{ type: 'button', class: newScanClass, onclick: function () {
								showStart( true );
							} },
							__( 'New scan', 'airworthy' )
						)
					)
				),
				headline,
				summary,
				updatesNote,
				el(
					'section',
					{ class: 'airworthy-legend-box' },
					el( 'h3', null, __( 'What the verdicts mean', 'airworthy' ) ),
					verdictLegend( scan )
				),
				status,
				el(
					'div',
					{ class: 'airworthy-before' },
					el( 'strong', null, __( 'Before you change your PHP version', 'airworthy' ) ),
					el(
						'ul',
						null,
						el( 'li', null, __( 'Back up your whole site first: files and database.', 'airworthy' ) ),
						el( 'li', null, __( 'Try the new PHP version on a staging copy of your site before your live site, and click through what matters: checkout, forms, logins, bookings.', 'airworthy' ) ),
						el( 'li', null, __( 'Know how to switch back: most hosts let you change the PHP version back in their control panel.', 'airworthy' ) )
					),
					el( 'p', null, __( 'Airworthy reads your plugins’ code without running it, so it can’t catch everything: some problems only show up when code runs, or come from your server’s set-up. Use the results to plan your upgrade, not as a guarantee that your site will work.', 'airworthy' ) )
				)
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
				el( 'p', null, __( 'Nobody can download these from WordPress.org any more, and no more updates will come for them, including security fixes.', 'airworthy' ) ),
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
								/* translators: 1: date, 2: reason in plain words, e.g. "the author withdrew it". */
								sprintf( __( 'closed %1$s: %2$s.', 'airworthy' ), formatDate( c.wporg.closed_date ), closedReason( c.wporg.closed_reason ) ),
								el( 'div', { class: 'airworthy-closed-advice' }, closedAdvice( c ) )
							);
						}
					)
				)
			) : null,
			existingNote,
			skippedNote,
			wporgNote,
			uncheckedCard,
			el(
				'table',
				{ class: 'widefat airworthy-table' },
				el( 'caption', { class: 'screen-reader-text' }, __( 'Results by plugin or theme, grouped by what to do next', 'airworthy' ) ),
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
				components.length ? resultGroups( scan, components ).map(
					function ( g ) {
						return groupBody( scan, g );
					}
				) : el( 'tbody', null, el( 'tr', null, el( 'td', { colspan: 5 }, __( 'No plugins or themes were checked in this scan.', 'airworthy' ) ) ) )
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
