/**
 * Tainacan Narrativas — admin (Vanilla JS + wp.apiFetch).
 *
 * All dynamic text goes through textContent; no innerHTML with data.
 *
 * @package TainacanNarrativas
 */
( function () {
	'use strict';

	var cfg = window.tnAdmin || {};
	var api = window.wp && window.wp.apiFetch;
	if ( ! api ) {
		return;
	}
	var I = cfg.i18n || {};
	var NS = '/' + ( cfg.restNamespace || 'tainacan-narrativas/v1' );

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( k === 'text' ) {
				node.textContent = attrs[ k ];
			} else if ( k === 'class' ) {
				node.className = attrs[ k ];
			} else {
				node.setAttribute( k, attrs[ k ] );
			}
		} );
		( children || [] ).forEach( function ( c ) {
			if ( c ) {
				node.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
			}
		} );
		return node;
	}

	function toast( msg, kind ) {
		var box = document.querySelector( '.tn-toasts' );
		if ( ! box ) {
			return;
		}
		var t = el( 'div', { 'class': 'tn-toast' + ( kind ? ' tn-toast--' + kind : '' ), role: 'status', text: msg } );
		box.appendChild( t );
		window.setTimeout( function () {
			t.remove();
		}, 6000 );
	}

	function errMsg( e ) {
		if ( e && e.message ) {
			return e.message;
		}
		if ( e && e.code ) {
			return e.code;
		}
		return I.error || 'Erro';
	}

	function output( container, content, kind ) {
		var out = container ? container.querySelector( '[data-tn-output]' ) || document.querySelector( '[data-tn-output]' ) : document.querySelector( '[data-tn-output]' );
		if ( ! out ) {
			return;
		}
		out.hidden = false;
		out.className = 'tn-panel-output' + ( kind ? ' is-' + kind : '' );
		while ( out.firstChild ) {
			out.removeChild( out.firstChild );
		}
		if ( typeof content === 'string' ) {
			out.appendChild( el( 'p', { text: content } ) );
		} else if ( content ) {
			out.appendChild( content );
		}
	}

	function fmtDuration( s ) {
		s = Math.round( s || 0 );
		if ( ! s ) {
			return '—';
		}
		var m = Math.floor( s / 60 );
		var r = s % 60;
		return m + ':' + ( r < 10 ? '0' : '' ) + r;
	}

	/* ------------------------------------------------------------------ */
	/* Provider panes                                                      */
	/* ------------------------------------------------------------------ */
	function initProviderSwitches() {
		document.querySelectorAll( '[data-tn-provider-switch]' ).forEach( function ( select ) {
			var group = select.getAttribute( 'data-tn-provider-switch' );
			var update = function () {
				document.querySelectorAll( '[data-tn-provider-pane^="' + group + ':"]' ).forEach( function ( pane ) {
					var visible = pane.getAttribute( 'data-tn-provider-pane' ) === group + ':' + select.value;
					pane.classList.toggle( 'is-visible', visible );
					// Hidden panes are disabled so duplicated field names (e.g. tn[ai_model]) are submitted once.
					pane.querySelectorAll( 'input, select, textarea' ).forEach( function ( f ) {
						if ( f.hasAttribute( 'data-tn-locked' ) ) {
							return;
						}
						var fieldsetDisabled = !! ( f.closest( 'fieldset' ) && f.closest( 'fieldset' ).disabled );
						f.disabled = ! visible || fieldsetDisabled;
					} );
				} );
			};
			select.addEventListener( 'change', update );
			update();
		} );
		// CSV → hidden array inputs (metadata order).
		document.querySelectorAll( '[data-tn-csv-target]' ).forEach( function ( input ) {
			var holder = document.getElementById( input.getAttribute( 'data-tn-csv-target' ) );
			if ( ! holder ) {
				return;
			}
			var name = holder.getAttribute( 'data-tn-csv-name' );
			var sync = function () {
				while ( holder.firstChild ) {
					holder.removeChild( holder.firstChild );
				}
				input.value.split( ',' ).map( function ( v ) { return parseInt( v.trim(), 10 ); } ).filter( function ( v ) { return v > 0; } ).forEach( function ( v ) {
					holder.appendChild( el( 'input', { type: 'hidden', name: name, value: String( v ) } ) );
				} );
			};
			input.addEventListener( 'input', sync );
			sync();
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Queue runner                                                        */
	/* ------------------------------------------------------------------ */
	var queueRunning = false;
	function setQueueStatus( text ) {
		document.querySelectorAll( '[data-tn-queue-status]' ).forEach( function ( n ) {
			n.textContent = text || '';
		} );
	}
	function runQueue( onDone, maxIterations ) {
		if ( queueRunning ) {
			return;
		}
		queueRunning = true;
		var totals = { done: 0, retry: 0, failed: 0 };
		var iterations = 0;
		maxIterations = maxIterations || 60;
		var step = function () {
			api( { path: NS + '/queue/run', method: 'POST', data: { limit: 1 } } ).then( function ( r ) {
				iterations += 1;
				totals.done += r.done || 0;
				totals.retry += r.retry || 0;
				totals.failed += r.failed || 0;
				var pending = ( r.counts && r.counts.queued ) || 0;
				setQueueStatus( ( I.working || 'Processando…' ) + ' ' + pending );
				if ( ( r.processed > 0 || pending > 0 ) && iterations < maxIterations && ! r.skipped ) {
					window.setTimeout( step, 400 );
					return;
				}
				queueRunning = false;
				var msg = ( I.queueRun || 'Fila: %1$d concluído(s), %2$d reagendado(s), %3$d falha(s).' ).replace( '%1$d', totals.done ).replace( '%2$d', totals.retry ).replace( '%3$d', totals.failed );
				setQueueStatus( msg );
				if ( totals.done + totals.retry + totals.failed === 0 ) {
					setQueueStatus( I.noJobs || 'Fila vazia.' );
				}
				if ( onDone ) {
					onDone( totals );
				}
			} ).catch( function ( e ) {
				queueRunning = false;
				setQueueStatus( errMsg( e ) );
				toast( errMsg( e ), 'error' );
				if ( onDone ) {
					onDone( totals );
				}
			} );
		};
		setQueueStatus( I.working || 'Processando…' );
		step();
	}

	/* ------------------------------------------------------------------ */
	/* Narratives table                                                    */
	/* ------------------------------------------------------------------ */
	var table = document.querySelector( '[data-tn-table]' );
	var state = { page: 1, per_page: 20 };

	function filters() {
		var f = {};
		document.querySelectorAll( '[data-tn-filter]' ).forEach( function ( n ) {
			f[ n.getAttribute( 'data-tn-filter' ) ] = n.value;
		} );
		return f;
	}

	function statusBadge( row ) {
		return el( 'span', { 'class': 'tn-status tn-status--' + row.status, text: row.status_label || row.status, title: row.last_error || '' } );
	}

	function actionsFor( row ) {
		var cell = el( 'td', { 'class': 'tn-actions-cell' } );
		var btn = function ( label, action, primary ) {
			var b = el( 'button', { type: 'button', 'class': 'button button-small' + ( primary ? ' button-primary' : '' ), 'data-tn-row-action': action, 'data-item': String( row.item_id ), text: label } );
			cell.appendChild( b );
			return b;
		};
		var can = cfg.can || {};
		btn( 'Detalhes', 'detail' );
		if ( can.generate ) {
			if ( row.status === 'stale' || row.status === 'error' || row.status === 'insufficient' || row.status === 'requires_ocr' ) {
				btn( 'Regenerar', 'regenerate', true );
			} else if ( row.status === 'ready' || row.status === 'review' ) {
				btn( 'Regenerar', 'regenerate' );
			} else {
				btn( 'Gerar', 'generate', true );
			}
		}
		if ( row.audio_url ) {
			var a = el( 'a', { 'class': 'button button-small', href: row.audio_url, target: '_blank', rel: 'noopener', text: 'Ouvir' } );
			cell.appendChild( a );
		}
		if ( row.status === 'review' && ( can.generate || can.manage ) ) {
			btn( 'Aprovar', 'approve', true );
		}
		if ( can.generate ) {
			btn( 'Verificar', 'check' );
		}
		if ( can.manage ) {
			if ( row.audio_attachment_id ) {
				btn( 'Excluir áudio', 'delete-audio' );
			}
			btn( 'Excluir', 'delete' );
		}
		return cell;
	}

	function renderRows( data ) {
		var tbody = table.querySelector( '[data-tn-rows]' );
		while ( tbody.firstChild ) {
			tbody.removeChild( tbody.firstChild );
		}
		if ( ! data.rows.length ) {
			tbody.appendChild( el( 'tr', {}, [ el( 'td', { colspan: '9', text: '—' } ) ] ) );
		}
		data.rows.forEach( function ( row ) {
			var tr = el( 'tr', { 'data-item-row': String( row.item_id ) } );
			var itemCell = el( 'td' );
			itemCell.appendChild( el( 'a', { href: row.item_url || '#', target: '_blank', rel: 'noopener', text: row.item_title || ( '#' + row.item_id ) } ) );
			itemCell.appendChild( el( 'span', { 'class': 'tn-muted', text: ' #' + row.item_id + ' · v' + row.version } ) );
			tr.appendChild( itemCell );
			tr.appendChild( el( 'td', { text: row.collection_name || String( row.collection_id ) } ) );
			var st = el( 'td' );
			st.appendChild( statusBadge( row ) );
			tr.appendChild( st );
			tr.appendChild( el( 'td', { text: row.mode_label || row.mode } ) );
			tr.appendChild( el( 'td', { text: fmtDuration( row.duration ) } ) );
			tr.appendChild( el( 'td', { text: row.generated_at || '—' } ) );
			tr.appendChild( el( 'td', { text: ( row.ai_provider || '—' ) + ( row.ai_model ? ' · ' + row.ai_model : '' ) } ) );
			tr.appendChild( el( 'td', { text: ( row.tts_provider || '—' ) + ( row.tts_voice ? ' · ' + row.tts_voice : '' ) } ) );
			tr.appendChild( actionsFor( row ) );
			tbody.appendChild( tr );
		} );
		var pag = document.querySelector( '[data-tn-pagination]' );
		while ( pag.firstChild ) {
			pag.removeChild( pag.firstChild );
		}
		var pages = Math.max( 1, Math.ceil( data.total / data.per_page ) );
		pag.appendChild( el( 'span', { text: data.total + ' · ' + data.page + '/' + pages } ) );
		if ( data.page > 1 ) {
			var prev = el( 'button', { type: 'button', 'class': 'button button-small', text: '‹' } );
			prev.addEventListener( 'click', function () { state.page -= 1; loadList(); } );
			pag.appendChild( prev );
		}
		if ( data.page < pages ) {
			var next = el( 'button', { type: 'button', 'class': 'button button-small', text: '›' } );
			next.addEventListener( 'click', function () { state.page += 1; loadList(); } );
			pag.appendChild( next );
		}
	}

	function loadList() {
		if ( ! table ) {
			return;
		}
		var f = filters();
		var qs = [ 'page=' + state.page, 'per_page=' + state.per_page ];
		Object.keys( f ).forEach( function ( k ) {
			if ( f[ k ] ) {
				qs.push( k + '=' + encodeURIComponent( f[ k ] ) );
			}
		} );
		api( { path: NS + '/narratives?' + qs.join( '&' ) } ).then( renderRows ).catch( function ( e ) {
			toast( errMsg( e ), 'error' );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Detail panel                                                        */
	/* ------------------------------------------------------------------ */
	var detail = document.querySelector( '[data-tn-detail]' );
	var currentItem = 0;
	var currentGenerated = '';

	function showPane( name ) {
		detail.querySelectorAll( '[data-tn-detail-tab]' ).forEach( function ( b ) {
			var on = b.getAttribute( 'data-tn-detail-tab' ) === name;
			b.classList.toggle( 'is-active', on );
			b.setAttribute( 'aria-selected', on ? 'true' : 'false' );
		} );
		detail.querySelectorAll( '[data-tn-detail-pane]' ).forEach( function ( p ) {
			p.hidden = p.getAttribute( 'data-tn-detail-pane' ) !== name;
		} );
	}

	function renderPreview( container, p ) {
		while ( container.firstChild ) {
			container.removeChild( container.firstChild );
		}
		var score = el( 'p' );
		score.appendChild( el( 'strong', { text: 'Conteúdo para narrativa: ' } ) );
		score.appendChild( el( 'span', { 'class': 'tn-score tn-score--' + p.score.level, text: p.score.label } ) );
		score.appendChild( el( 'span', { 'class': 'tn-muted', text: ' · ' + p.score.chars + ' caracteres · ' + p.score.sources + ' fontes · ~' + p.estimate.tokens + ' tokens estimados · ' + p.estimate.chunks + ' chunk(s)' } ) );
		container.appendChild( score );
		var ul = el( 'ul', { 'class': 'tn-health' } );
		( p.health.notes || [] ).forEach( function ( n ) {
			ul.appendChild( el( 'li', { 'class': 'is-' + n.level, text: n.text } ) );
		} );
		container.appendChild( ul );
		container.appendChild( el( 'h3', { text: 'Metadados (' + p.metadata.length + ')' } ) );
		var dl = el( 'dl', { 'class': 'tn-kv' } );
		p.metadata.forEach( function ( m ) {
			dl.appendChild( el( 'dt', { text: m.label + ' #' + m.id } ) );
			dl.appendChild( el( 'dd', { text: m.value } ) );
		} );
		container.appendChild( dl );
		if ( p.document ) {
			container.appendChild( el( 'h3', { text: 'Documento' } ) );
			container.appendChild( el( 'p', { text: ( p.document.filename || p.document.kind ) + ' · ' + p.document.status + ' · ' + p.document.chars + ' caracteres · ' + ( p.document.method || '' ) + ( p.document.message ? ' · ' + p.document.message : '' ) } ) );
			if ( p.document.excerpt ) {
				container.appendChild( el( 'pre', { 'class': 'tn-pre', text: p.document.excerpt + '…' } ) );
			}
		}
		if ( p.attachments && p.attachments.length ) {
			container.appendChild( el( 'h3', { text: 'Anexos (' + p.attachments.length + ')' } ) );
			p.attachments.forEach( function ( a ) {
				container.appendChild( el( 'p', { text: a.filename + ' · ' + a.status + ' · ' + a.chars + ' caracteres' + ( a.message ? ' · ' + a.message : '' ) } ) );
			} );
		}
		container.appendChild( el( 'h3', { text: 'IA' } ) );
		container.appendChild( el( 'p', { text: p.ai.provider === 'none' ? 'Sem IA: ' + ( p.ai.label || '' ) : p.ai.label + ' · ' + p.ai.model + ( p.ai.external ? ' · externa' : ' · local' ) } ) );
		container.appendChild( el( 'p', { 'class': 'tn-muted', text: 'source_hash ' + p.source_hash + ( p.is_current ? ' (igual à narrativa atual)' : '' ) } ) );
	}

	function renderInfo( container, row ) {
		while ( container.firstChild ) {
			container.removeChild( container.firstChild );
		}
		var dl = el( 'dl', { 'class': 'tn-kv' } );
		var add = function ( k, v ) {
			dl.appendChild( el( 'dt', { text: k } ) );
			dl.appendChild( el( 'dd', { text: v === null || v === undefined || v === '' ? '—' : String( v ) } ) );
		};
		add( 'Item', row.item_id );
		add( 'Coleção', row.collection_id );
		add( 'Versão', row.version );
		add( 'Status', row.status_label );
		add( 'Modo', row.mode_label );
		add( 'Idioma', row.language );
		add( 'source_hash', row.source_hash );
		add( 'script_hash', row.script_hash );
		add( 'audio_hash', row.audio_hash );
		add( 'IA', ( row.ai_provider || '' ) + ( row.ai_model ? ' · ' + row.ai_model : '' ) );
		add( 'TTS', ( row.tts_provider || '' ) + ( row.tts_voice ? ' · ' + row.tts_voice : '' ) );
		add( 'Áudio', row.audio_url );
		add( 'Duração (s)', row.duration );
		add( 'Gerada em', row.generated_at );
		add( 'Aprovada por / em', ( row.approved_by || '' ) + ' ' + ( row.approved_at || '' ) );
		add( 'Roteiro editado por / em', ( row.script_edited_by || '' ) + ' ' + ( row.script_edited_at || '' ) );
		add( 'Fontes', ( row.sources || [] ).join( ', ' ) );
		add( 'Estatísticas', JSON.stringify( row.stats || {} ) );
		add( 'Último erro', row.last_error );
		container.appendChild( dl );
	}

	function openDetail( itemId ) {
		if ( ! detail ) {
			return;
		}
		currentItem = itemId;
		detail.hidden = false;
		detail.querySelector( '[data-tn-detail-title]' ).textContent = 'Item #' + itemId;
		showPane( 'script' );
		var audioBox = detail.querySelector( '[data-tn-detail-audio]' );
		while ( audioBox.firstChild ) {
			audioBox.removeChild( audioBox.firstChild );
		}
		api( { path: NS + '/items/' + itemId } ).then( function ( s ) {
			var row = s.narrative;
			var ta = detail.querySelector( '[data-tn-script]' );
			ta.value = row ? ( row.final_script || '' ) : '';
			currentGenerated = row ? ( row.generated_script || '' ) : '';
			detail.querySelector( '[data-tn-script-meta]' ).textContent = row ? ( row.status_label + ' · v' + row.version + ( row.script_edited_at ? ' · editado em ' + row.script_edited_at : '' ) ) : 'Sem narrativa gerada.';
			if ( row && row.audio_url ) {
				var audio = el( 'audio', { controls: 'controls', preload: 'none', src: row.audio_url } );
				audioBox.appendChild( audio );
			}
			renderInfo( detail.querySelector( '[data-tn-detail-pane="info"]' ), row || { item_id: itemId } );
			detail.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		} ).catch( function ( e ) {
			toast( errMsg( e ), 'error' );
		} );
		api( { path: NS + '/items/' + itemId + '/preview' } ).then( function ( p ) {
			renderPreview( detail.querySelector( '[data-tn-detail-pane="sources"]' ), p );
		} ).catch( function ( e ) {
			var pane = detail.querySelector( '[data-tn-detail-pane="sources"]' );
			pane.textContent = errMsg( e );
		} );
	}

	function generate( itemId, force, then ) {
		toast( I.queued || 'Enfileirado.', 'info' );
		api( { path: NS + '/items/' + itemId + '/' + ( force ? 'regenerate' : 'generate' ), method: 'POST', data: {} } ).then( function () {
			runQueue( function () {
				loadList();
				if ( then ) {
					then();
				}
			} );
		} ).catch( function ( e ) {
			toast( errMsg( e ), 'error' );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Jobs                                                                */
	/* ------------------------------------------------------------------ */
	function loadJobs() {
		var tbody = document.querySelector( '[data-tn-job-rows]' );
		if ( ! tbody ) {
			return;
		}
		api( { path: NS + '/jobs' } ).then( function ( r ) {
			while ( tbody.firstChild ) {
				tbody.removeChild( tbody.firstChild );
			}
			if ( ! r.recent.length ) {
				tbody.appendChild( el( 'tr', {}, [ el( 'td', { colspan: '8', text: I.noJobs || 'Fila vazia.' } ) ] ) );
			}
			r.recent.forEach( function ( j ) {
				var tr = el( 'tr' );
				tr.appendChild( el( 'td', { text: '#' + j.id } ) );
				tr.appendChild( el( 'td', { text: String( j.item_id ) } ) );
				tr.appendChild( el( 'td', { text: ( j.payload && j.payload.action ) || 'generate' } ) );
				tr.appendChild( el( 'td', { text: j.stage } ) );
				var st = el( 'td' );
				st.appendChild( el( 'span', { 'class': 'tn-status tn-status--' + ( j.status === 'done' ? 'ready' : j.status === 'failed' ? 'error' : 'queued' ), text: j.status } ) );
				tr.appendChild( st );
				tr.appendChild( el( 'td', { text: String( j.attempts ) } ) );
				tr.appendChild( el( 'td', { text: j.updated_at } ) );
				tr.appendChild( el( 'td', { text: j.last_error || '' } ) );
				tbody.appendChild( tr );
			} );
		} ).catch( function ( e ) {
			toast( errMsg( e ), 'error' );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Global click delegation                                             */
	/* ------------------------------------------------------------------ */
	document.addEventListener( 'click', function ( ev ) {
		var target = ev.target.closest( '[data-tn-action], [data-tn-row-action], [data-tn-detail-tab]' );
		if ( ! target ) {
			return;
		}
		if ( target.hasAttribute( 'data-tn-detail-tab' ) ) {
			showPane( target.getAttribute( 'data-tn-detail-tab' ) );
			return;
		}
		var panel = target.closest( '.tn-panel' );
		var action = target.getAttribute( 'data-tn-action' ) || target.getAttribute( 'data-tn-row-action' );
		var itemId = parseInt( target.getAttribute( 'data-item' ) || '0', 10 );

		switch ( action ) {
			case 'reload':
				state.page = 1;
				loadList();
				break;
			case 'run-queue':
				runQueue( function () { loadList(); loadJobs(); } );
				break;
			case 'reload-jobs':
				loadJobs();
				break;
			case 'clear-failed':
				api( { path: NS + '/queue/clear-failed', method: 'POST' } ).then( function ( r ) {
					toast( ( I.done || 'Concluído.' ) + ' (' + r.cleared + ')', 'success' );
					loadJobs();
				} ).catch( function ( e ) { toast( errMsg( e ), 'error' ); } );
				break;
			case 'preview-quick':
			case 'generate-quick':
				var input = document.getElementById( 'tn-quick-item' );
				var id = parseInt( input && input.value, 10 );
				if ( ! id ) {
					input.focus();
					return;
				}
				if ( action === 'preview-quick' ) {
					output( panel, I.working || 'Processando…' );
					api( { path: NS + '/items/' + id + '/preview' } ).then( function ( p ) {
						var box = el( 'div' );
						renderPreview( box, p );
						output( panel, box, 'success' );
					} ).catch( function ( e ) { output( panel, errMsg( e ), 'error' ); } );
				} else {
					output( panel, I.queued || 'Enfileirado…' );
					api( { path: NS + '/items/' + id + '/generate', method: 'POST', data: { force: true } } ).then( function () {
						runQueue( function () {
							api( { path: NS + '/items/' + id } ).then( function ( s ) {
								var row = s.narrative;
								output( panel, row ? ( row.status_label + ( row.last_error ? ' — ' + row.last_error : '' ) ) : ( I.done || 'Concluído.' ), row && row.status === 'error' ? 'error' : 'success' );
							} );
						} );
					} ).catch( function ( e ) { output( panel, errMsg( e ), 'error' ); } );
				}
				break;
			case 'bulk-generate':
				var cid = parseInt( target.getAttribute( 'data-collection' ), 10 );
				output( panel, I.working || 'Processando…' );
				api( { path: NS + '/collections/' + cid + '/generate', method: 'POST', data: { only_pending: true } } ).then( function ( r ) {
					output( panel, r.queued + ' item(ns) enfileirado(s). Executando a fila…' );
					runQueue( function ( totals ) {
						output( panel, 'Concluído: ' + totals.done + ' ok, ' + totals.retry + ' reagendados, ' + totals.failed + ' falhas. Itens restantes continuam pelo WP-Cron.', 'success' );
					}, 200 );
				} ).catch( function ( e ) { output( panel, errMsg( e ), 'error' ); } );
				break;
			case 'generate-all':
				output( panel, I.working || 'Processando…' );
				api( { path: NS + '/collections/generate-all', method: 'POST', data: {} } ).then( function ( r ) {
					output( panel, r.queued + ' item(ns) enfileirado(s) em ' + r.collections + ' coleção(ões). Executando a fila…' );
					runQueue( function ( totals ) {
						output( panel, 'Concluído nesta sessão: ' + totals.done + ' ok, ' + totals.retry + ' reagendados, ' + totals.failed + ' falhas. Itens restantes continuam pelo WP-Cron; recarregue o painel para ver a cobertura atualizada.', 'success' );
					}, 400 );
				} ).catch( function ( e ) { output( panel, errMsg( e ), 'error' ); } );
				break;
			case 'approve-all':
				if ( ! window.confirm( I.confirmApproveAll || 'Aprovar todos os roteiros em revisão e gerar o áudio de cada um?' ) ) {
					return;
				}
				output( panel, I.working || 'Processando…' );
				api( { path: NS + '/narratives/approve-all', method: 'POST', data: {} } ).then( function ( r ) {
					output( panel, r.queued + ' aprovação(ões) enfileirada(s). Executando a fila…' );
					runQueue( function ( totals ) {
						output( panel, 'Concluído: ' + totals.done + ' ok, ' + totals.retry + ' reagendados, ' + totals.failed + ' falhas.', 'success' );
						loadList();
					}, 400 );
				} ).catch( function ( e ) { output( panel, errMsg( e ), 'error' ); } );
				break;
			case 'test-provider':
				output( panel, I.testing || 'Testando…' );
				api( { path: NS + '/providers/test', method: 'POST', data: { kind: target.getAttribute( 'data-kind' ) } } ).then( function ( r ) {
					var box = el( 'div' );
					box.appendChild( el( 'p', { text: r.message } ) );
					if ( r.details && Object.keys( r.details ).length ) {
						box.appendChild( el( 'pre', { text: JSON.stringify( r.details, null, 2 ) } ) );
					}
					output( panel, box, r.success ? 'success' : 'error' );
				} ).catch( function ( e ) { output( panel, errMsg( e ), 'error' ); } );
				break;
			case 'test-audio':
				output( panel, I.testing || 'Testando…' );
				api( { path: NS + '/diagnostics/test-audio', method: 'POST' } ).then( function ( r ) {
					var box = el( 'div' );
					box.appendChild( el( 'p', { text: r.message || ( r.voice ? 'Voz: ' + r.voice + ' · ' + fmtDuration( r.duration ) : '' ) } ) );
					if ( r.url ) {
						box.appendChild( el( 'audio', { controls: 'controls', src: r.url } ) );
					} else if ( r.browser ) {
						var section = el( 'section', { 'class': 'tn-player', 'data-tn-player': JSON.stringify( { playback: 'browser', duration: 4, lang: 'pt-BR', rate: 1 } ) } );
						section.appendChild( el( 'div', { 'data-tn-controls': '' } ) );
						var det = el( 'details', { 'data-tn-transcript': '', open: 'open' } );
						det.appendChild( el( 'summary', { text: 'Ver texto' } ) );
						var body = el( 'div', { 'data-tn-transcript-body': '' } );
						body.appendChild( el( 'p', { text: 'Este é um áudio de teste do Tainacan Narrativas, sintetizado pelo seu navegador.' } ) );
						det.appendChild( body );
						section.appendChild( det );
						box.appendChild( section );
						window.setTimeout( function () {
							if ( window.tnPlayerBoot ) {
								window.tnPlayerBoot();
							}
						}, 0 );
					}
					output( panel, box, 'success' );
				} ).catch( function ( e ) { output( panel, errMsg( e ), 'error' ); } );
				break;
			case 'test-write':
				output( panel, I.testing || 'Testando…' );
				api( { path: NS + '/diagnostics/test-write', method: 'POST' } ).then( function ( r ) {
					output( panel, r.message, 'success' );
				} ).catch( function ( e ) { output( panel, errMsg( e ), 'error' ); } );
				break;
			case 'detail':
				openDetail( itemId );
				break;
			case 'close-detail':
				detail.hidden = true;
				break;
			case 'generate':
				generate( itemId, false );
				break;
			case 'regenerate':
				generate( itemId, true );
				break;
			case 'approve':
				var approveId = itemId || currentItem;
				toast( I.working || 'Processando…' );
				api( { path: NS + '/items/' + approveId + '/approve', method: 'POST' } ).then( function ( r ) {
					toast( r.narrative.status_label + ( r.narrative.last_error ? ' — ' + r.narrative.last_error : '' ), r.narrative.status === 'error' ? 'error' : 'success' );
					loadList();
					if ( ! detail.hidden ) {
						openDetail( approveId );
					}
				} ).catch( function ( e ) { toast( errMsg( e ), 'error' ); } );
				break;
			case 'check':
				api( { path: NS + '/items/' + itemId + '/check', method: 'POST' } ).then( function ( r ) {
					toast( ( cfg.statusLabels && cfg.statusLabels[ r.status ] ) || r.status, 'success' );
					loadList();
				} ).catch( function ( e ) { toast( errMsg( e ), 'error' ); } );
				break;
			case 'delete-audio':
				if ( ! window.confirm( I.confirmAudio || 'Excluir o áudio?' ) ) {
					return;
				}
				api( { path: NS + '/items/' + itemId + '/audio', method: 'DELETE' } ).then( function () {
					toast( I.done || 'Concluído.', 'success' );
					loadList();
				} ).catch( function ( e ) { toast( errMsg( e ), 'error' ); } );
				break;
			case 'delete':
				if ( ! window.confirm( I.confirmDel || 'Excluir a narrativa?' ) ) {
					return;
				}
				api( { path: NS + '/items/' + itemId, method: 'DELETE' } ).then( function () {
					toast( I.done || 'Concluído.', 'success' );
					loadList();
					if ( currentItem === itemId ) {
						detail.hidden = true;
					}
				} ).catch( function ( e ) { toast( errMsg( e ), 'error' ); } );
				break;
			case 'save-script':
				var ta = detail.querySelector( '[data-tn-script]' );
				api( { path: NS + '/items/' + currentItem + '/script', method: 'PUT', data: { script: ta.value } } ).then( function () {
					toast( I.saved || 'Roteiro salvo.', 'success' );
					loadList();
				} ).catch( function ( e ) { toast( errMsg( e ), 'error' ); } );
				break;
			case 'restore-generated':
				detail.querySelector( '[data-tn-script]' ).value = currentGenerated;
				break;
		}
	} );

	document.querySelectorAll( '[data-tn-filter]' ).forEach( function ( n ) {
		n.addEventListener( 'change', function () {
			state.page = 1;
			loadList();
		} );
	} );

	initProviderSwitches();
	loadList();
	loadJobs();
	if ( cfg.runQueue ) {
		runQueue( function () { loadList(); } );
	}
}() );
