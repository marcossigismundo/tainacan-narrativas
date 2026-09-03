/**
 * Tainacan Narrativas — public player (Vanilla JS, no dependencies).
 *
 * Two engines behind one accessible UI:
 *  - AudioEngine: pre-generated audio file (<audio>), Media Session API.
 *  - SpeechEngine: Web Speech API over the stored transcript (sentence by
 *    sentence, so pause/seek/rate work reliably across browsers).
 *
 * Nothing is fetched from a CDN and nothing is generated on page view.
 *
 * @package TainacanNarrativas
 */
( function () {
	'use strict';

	var I18N = window.tnPlayerI18n || {};
	var t = function ( key, fallback ) {
		return I18N[ key ] || fallback;
	};

	var ICONS = {
		play: '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8 5v14l11-7z"/></svg>',
		pause: '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path fill="currentColor" d="M6 5h4v14H6zm8 0h4v14h-4z"/></svg>',
		back: '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 5V1L7 6l5 5V7a6 6 0 1 1-6 6H4a8 8 0 1 0 8-8z"/><text x="8.2" y="16.5" font-size="7" font-family="system-ui, sans-serif" fill="currentColor">10</text></svg>',
		forward: '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 5V1l5 5-5 5V7a6 6 0 1 0 6 6h2a8 8 0 1 1-8-8z"/><text x="8.2" y="16.5" font-size="7" font-family="system-ui, sans-serif" fill="currentColor">10</text></svg>',
		volume: '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M3 9v6h4l5 5V4L7 9H3zm13.5 3A4.5 4.5 0 0 0 14 7.97v8.05A4.5 4.5 0 0 0 16.5 12z"/></svg>'
	};

	var RATES = [ 0.75, 1, 1.25, 1.5, 1.75, 2 ];

	function fmt( seconds ) {
		seconds = Math.max( 0, Math.round( seconds || 0 ) );
		var h = Math.floor( seconds / 3600 );
		var m = Math.floor( ( seconds % 3600 ) / 60 );
		var s = seconds % 60;
		var mm = ( h > 0 && m < 10 ? '0' : '' ) + m;
		var ss = ( s < 10 ? '0' : '' ) + s;
		return h > 0 ? h + ':' + mm + ':' + ss : mm + ':' + ss;
	}

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( k === 'text' ) {
				node.textContent = attrs[ k ];
			} else if ( k === 'html' ) {
				node.innerHTML = attrs[ k ]; // Static SVG markup only.
			} else {
				node.setAttribute( k, attrs[ k ] );
			}
		} );
		( children || [] ).forEach( function ( c ) {
			node.appendChild( c );
		} );
		return node;
	}

	/* ---------------------------------------------------------------- */
	/* Tiny event emitter                                                */
	/* ---------------------------------------------------------------- */
	function Emitter() {
		this.handlers = {};
	}
	Emitter.prototype.on = function ( ev, fn ) {
		( this.handlers[ ev ] = this.handlers[ ev ] || [] ).push( fn );
	};
	Emitter.prototype.emit = function ( ev, data ) {
		( this.handlers[ ev ] || [] ).forEach( function ( fn ) {
			fn( data );
		} );
	};

	/* ---------------------------------------------------------------- */
	/* Audio engine                                                      */
	/* ---------------------------------------------------------------- */
	function AudioEngine( root, cfg ) {
		Emitter.call( this );
		var self = this;
		this.audio = root.querySelector( '[data-tn-audio]' );
		this.supported = !! this.audio;
		this.duration = cfg.duration || 0;
		if ( ! this.supported ) {
			return;
		}
		this.audio.controls = false;
		this.audio.addEventListener( 'loadedmetadata', function () {
			if ( isFinite( self.audio.duration ) && self.audio.duration > 0 ) {
				self.duration = self.audio.duration;
			}
			self.emit( 'ready' );
		} );
		this.audio.addEventListener( 'timeupdate', function () {
			self.emit( 'time', self.audio.currentTime );
		} );
		this.audio.addEventListener( 'play', function () {
			self.emit( 'play' );
		} );
		this.audio.addEventListener( 'pause', function () {
			self.emit( 'pause' );
		} );
		this.audio.addEventListener( 'ended', function () {
			self.emit( 'end' );
		} );
		this.audio.addEventListener( 'error', function () {
			self.emit( 'error' );
		} );
		if ( 'mediaSession' in navigator && window.MediaMetadata ) {
			try {
				navigator.mediaSession.metadata = new window.MediaMetadata( { title: cfg.title || document.title } );
				navigator.mediaSession.setActionHandler( 'play', function () { self.play(); } );
				navigator.mediaSession.setActionHandler( 'pause', function () { self.pause(); } );
				navigator.mediaSession.setActionHandler( 'seekbackward', function () { self.skip( -10 ); } );
				navigator.mediaSession.setActionHandler( 'seekforward', function () { self.skip( 10 ); } );
			} catch ( e ) { /* progressive enhancement */ }
		}
	}
	AudioEngine.prototype = Object.create( Emitter.prototype );
	AudioEngine.prototype.play = function () {
		var p = this.audio.play();
		if ( p && p.catch ) {
			p.catch( function () {} );
		}
	};
	AudioEngine.prototype.pause = function () { this.audio.pause(); };
	AudioEngine.prototype.isPlaying = function () { return ! this.audio.paused && ! this.audio.ended; };
	AudioEngine.prototype.currentTime = function () { return this.audio.currentTime; };
	AudioEngine.prototype.seek = function ( s ) { this.audio.currentTime = Math.max( 0, Math.min( this.duration || s, s ) ); };
	AudioEngine.prototype.skip = function ( d ) { this.seek( this.audio.currentTime + d ); };
	AudioEngine.prototype.setRate = function ( r ) { this.audio.playbackRate = r; };
	AudioEngine.prototype.setVolume = function ( v ) { this.audio.volume = v; };
	AudioEngine.prototype.hasVolume = true;

	/* ---------------------------------------------------------------- */
	/* Speech engine (Web Speech API)                                    */
	/* ---------------------------------------------------------------- */
	function SpeechEngine( root, cfg, sentences ) {
		Emitter.call( this );
		this.supported = 'speechSynthesis' in window && 'SpeechSynthesisUtterance' in window && sentences.length > 0;
		this.cfg = cfg;
		this.sentences = sentences; // [{text, node, start, chars}]
		this.totalChars = sentences.reduce( function ( a, s ) { return a + s.chars; }, 0 );
		this.duration = cfg.duration > 0 ? cfg.duration : this.totalChars / 15;
		this.cps = this.totalChars / Math.max( 1, this.duration ); // chars per second at rate 1
		this.index = 0;
		this.playing = false;
		this.rate = cfg.rate || 1;
		this.volume = 1;
		this.voice = null;
		this.startedAt = 0;
		this.timer = null;
		this.utterance = null;
		if ( this.supported ) {
			this.pickVoice();
			if ( typeof window.speechSynthesis.onvoiceschanged !== 'undefined' ) {
				var self = this;
				window.speechSynthesis.addEventListener( 'voiceschanged', function () { self.pickVoice(); } );
			}
			this.emit( 'ready' );
		}
	}
	SpeechEngine.prototype = Object.create( Emitter.prototype );
	SpeechEngine.prototype.hasVolume = true;
	SpeechEngine.prototype.pickVoice = function () {
		var voices = window.speechSynthesis.getVoices() || [];
		if ( ! voices.length ) {
			return;
		}
		var lang = ( this.cfg.lang || 'pt-BR' ).toLowerCase();
		var base = lang.split( '-' )[ 0 ];
		var hint = ( this.cfg.voiceHint || '' ).toLowerCase();
		var exact = voices.filter( function ( v ) { return v.lang && v.lang.toLowerCase().replace( '_', '-' ) === lang; } );
		var family = voices.filter( function ( v ) { return v.lang && v.lang.toLowerCase().indexOf( base ) === 0; } );
		var pool = exact.length ? exact : ( family.length ? family : voices );
		var chosen = null;
		if ( hint ) {
			chosen = pool.filter( function ( v ) { return v.name.toLowerCase().indexOf( hint ) !== -1; } )[ 0 ] || null;
		}
		if ( ! chosen ) {
			chosen = pool.filter( function ( v ) { return v.localService; } )[ 0 ] || pool[ 0 ] || null;
		}
		this.voice = chosen;
	};
	SpeechEngine.prototype.timeAt = function ( index, extra ) {
		var chars = 0;
		for ( var i = 0; i < index && i < this.sentences.length; i++ ) {
			chars += this.sentences[ i ].chars;
		}
		return ( chars + ( extra || 0 ) ) / this.cps;
	};
	SpeechEngine.prototype.currentTime = function () {
		var elapsed = this.playing && this.startedAt ? ( Date.now() - this.startedAt ) / 1000 * this.rate * this.cps : 0;
		var cur = this.sentences[ this.index ];
		if ( cur ) {
			elapsed = Math.min( elapsed, cur.chars );
		}
		return Math.min( this.duration, this.timeAt( this.index, elapsed ) );
	};
	SpeechEngine.prototype.isPlaying = function () { return this.playing; };
	SpeechEngine.prototype.highlight = function ( index ) {
		this.sentences.forEach( function ( s, i ) {
			if ( s.node ) {
				s.node.classList.toggle( 'tn-current', i === index );
			}
		} );
	};
	SpeechEngine.prototype.speakCurrent = function () {
		var self = this;
		if ( this.index >= this.sentences.length ) {
			this.stopTimer();
			this.playing = false;
			this.index = 0;
			this.highlight( -1 );
			this.emit( 'end' );
			return;
		}
		var s = this.sentences[ this.index ];
		var u = new window.SpeechSynthesisUtterance( s.text );
		u.lang = this.cfg.lang || 'pt-BR';
		if ( this.voice ) {
			u.voice = this.voice;
		}
		u.rate = this.rate;
		u.volume = this.volume;
		u.onend = function () {
			if ( self.utterance !== u || ! self.playing ) {
				return;
			}
			self.index += 1;
			self.startedAt = Date.now();
			self.speakCurrent();
		};
		u.onerror = function ( ev ) {
			if ( ev && ( ev.error === 'interrupted' || ev.error === 'canceled' ) ) {
				return;
			}
			self.playing = false;
			self.stopTimer();
			self.emit( 'error' );
		};
		this.utterance = u;
		this.startedAt = Date.now();
		this.highlight( this.index );
		window.speechSynthesis.speak( u );
	};
	SpeechEngine.prototype.startTimer = function () {
		var self = this;
		this.stopTimer();
		this.timer = window.setInterval( function () {
			self.emit( 'time', self.currentTime() );
		}, 250 );
	};
	SpeechEngine.prototype.stopTimer = function () {
		if ( this.timer ) {
			window.clearInterval( this.timer );
			this.timer = null;
		}
	};
	SpeechEngine.prototype.play = function () {
		if ( this.playing ) {
			return;
		}
		window.speechSynthesis.cancel();
		this.playing = true;
		this.startTimer();
		this.emit( 'play' );
		this.speakCurrent();
	};
	SpeechEngine.prototype.pause = function () {
		if ( ! this.playing ) {
			return;
		}
		this.playing = false;
		this.utterance = null;
		window.speechSynthesis.cancel();
		this.stopTimer();
		this.emit( 'pause' );
	};
	SpeechEngine.prototype.seek = function ( seconds ) {
		var target = Math.max( 0, Math.min( this.duration, seconds ) ) * this.cps;
		var chars = 0;
		var idx = 0;
		for ( var i = 0; i < this.sentences.length; i++ ) {
			if ( chars + this.sentences[ i ].chars > target ) {
				idx = i;
				break;
			}
			chars += this.sentences[ i ].chars;
			idx = i + 1;
		}
		var wasPlaying = this.playing;
		if ( wasPlaying ) {
			this.utterance = null;
			window.speechSynthesis.cancel();
		}
		this.index = Math.min( idx, this.sentences.length - 1 );
		this.startedAt = Date.now();
		this.highlight( this.index );
		this.emit( 'time', this.currentTime() );
		if ( wasPlaying ) {
			this.speakCurrent();
		}
	};
	SpeechEngine.prototype.skip = function ( delta ) {
		this.seek( this.currentTime() + delta );
	};
	SpeechEngine.prototype.setRate = function ( r ) {
		this.rate = r;
		if ( this.playing ) {
			var idx = this.index;
			this.utterance = null;
			window.speechSynthesis.cancel();
			this.index = idx;
			this.speakCurrent();
		}
	};
	SpeechEngine.prototype.setVolume = function ( v ) {
		this.volume = v;
	};

	/* ---------------------------------------------------------------- */
	/* Transcript → sentences (wraps text in spans for highlighting)     */
	/* ---------------------------------------------------------------- */
	function prepareSentences( body ) {
		var list = [];
		if ( ! body ) {
			return list;
		}
		var paragraphs = Array.prototype.slice.call( body.querySelectorAll( 'p' ) );
		paragraphs.forEach( function ( p ) {
			var text = p.textContent || '';
			var parts = text.split( /(?<=[.!?…;:])\s+(?=[A-ZÀ-ÝÇ0-9"“(\[])/ ).filter( function ( s ) { return s.trim() !== ''; } );
			if ( ! parts.length ) {
				return;
			}
			while ( p.firstChild ) {
				p.removeChild( p.firstChild );
			}
			parts.forEach( function ( sentence, i ) {
				var span = el( 'span', { 'class': 'tn-sentence', text: sentence } );
				p.appendChild( span );
				if ( i < parts.length - 1 ) {
					p.appendChild( document.createTextNode( ' ' ) );
				}
				list.push( { text: sentence, node: span, chars: Math.max( 1, sentence.length ) } );
			} );
		} );
		return list;
	}

	/* ---------------------------------------------------------------- */
	/* UI                                                                */
	/* ---------------------------------------------------------------- */
	function buildUI( root, cfg, engine ) {
		var controls = root.querySelector( '[data-tn-controls]' );
		var playBtn = el( 'button', { type: 'button', 'class': 'tn-btn tn-btn--play', 'aria-label': t( 'play', 'Reproduzir' ), 'aria-pressed': 'false', html: ICONS.play } );
		var backBtn = el( 'button', { type: 'button', 'class': 'tn-btn', 'aria-label': t( 'back', 'Voltar 10 segundos' ), html: ICONS.back } );
		var fwdBtn = el( 'button', { type: 'button', 'class': 'tn-btn', 'aria-label': t( 'forward', 'Avançar 10 segundos' ), html: ICONS.forward } );
		var range = el( 'input', { type: 'range', 'class': 'tn-range tn-range--progress', min: '0', max: String( Math.max( 1, Math.round( engine.duration ) ) ), step: '1', value: '0', 'aria-label': t( 'progress', 'Posição da reprodução' ) } );
		var time = el( 'span', { 'class': 'tn-time', 'aria-live': 'off', text: fmt( 0 ) + ' / ' + fmt( engine.duration ) } );
		var rateLabel = el( 'label', { 'class': 'tn-rate' } );
		var rateSel = el( 'select', { 'class': 'tn-select', 'aria-label': t( 'speed', 'Velocidade' ) } );
		RATES.forEach( function ( r ) {
			var opt = el( 'option', { value: String( r ), text: r + '×' } );
			if ( r === ( cfg.playback === 'browser' ? ( cfg.rate || 1 ) : 1 ) ) {
				opt.selected = true;
			}
			rateSel.appendChild( opt );
		} );
		rateLabel.appendChild( rateSel );
		var volWrap = el( 'span', { 'class': 'tn-volume' } );
		volWrap.appendChild( el( 'span', { 'class': 'tn-volume__icon', html: ICONS.volume } ) );
		var vol = el( 'input', { type: 'range', 'class': 'tn-range tn-range--volume', min: '0', max: '1', step: '0.05', value: '1', 'aria-label': t( 'volume', 'Volume' ) } );
		volWrap.appendChild( vol );

		var row1 = el( 'div', { 'class': 'tn-controls__row' }, [ playBtn, backBtn, fwdBtn, range, time ] );
		var row2 = el( 'div', { 'class': 'tn-controls__row tn-controls__row--secondary' }, [ rateLabel, volWrap ] );
		controls.appendChild( row1 );
		controls.appendChild( row2 );
		controls.classList.add( 'tn-player__controls--ready' );

		var seeking = false;
		var updateTime = function ( cur ) {
			var d = engine.duration || 0;
			if ( ! seeking ) {
				range.value = String( Math.round( cur ) );
			}
			range.max = String( Math.max( 1, Math.round( d ) ) );
			range.setAttribute( 'aria-valuetext', t( 'timeOf', '%1$s de %2$s' ).replace( '%1$s', fmt( cur ) ).replace( '%2$s', fmt( d ) ) );
			time.textContent = fmt( cur ) + ' / ' + fmt( d );
			var pct = d > 0 ? Math.min( 100, ( cur / d ) * 100 ) : 0;
			range.style.setProperty( '--tn-progress', pct + '%' );
		};
		var setPlaying = function ( playing ) {
			playBtn.innerHTML = playing ? ICONS.pause : ICONS.play;
			playBtn.setAttribute( 'aria-label', playing ? t( 'pause', 'Pausar' ) : ( engine.currentTime() > 0 ? t( 'resume', 'Continuar' ) : t( 'play', 'Reproduzir' ) ) );
			playBtn.setAttribute( 'aria-pressed', playing ? 'true' : 'false' );
			root.classList.toggle( 'tn-player--playing', playing );
		};

		playBtn.addEventListener( 'click', function () {
			if ( engine.isPlaying() ) {
				engine.pause();
			} else {
				engine.play();
			}
		} );
		backBtn.addEventListener( 'click', function () { engine.skip( -10 ); } );
		fwdBtn.addEventListener( 'click', function () { engine.skip( 10 ); } );
		range.addEventListener( 'input', function () {
			seeking = true;
			updateTime( parseFloat( range.value ) );
		} );
		range.addEventListener( 'change', function () {
			seeking = false;
			engine.seek( parseFloat( range.value ) );
		} );
		rateSel.addEventListener( 'change', function () { engine.setRate( parseFloat( rateSel.value ) ); } );
		vol.addEventListener( 'input', function () { engine.setVolume( parseFloat( vol.value ) ); } );

		engine.on( 'ready', function () { updateTime( engine.currentTime() ); } );
		engine.on( 'time', updateTime );
		engine.on( 'play', function () { setPlaying( true ); } );
		engine.on( 'pause', function () { setPlaying( false ); } );
		engine.on( 'end', function () { setPlaying( false ); updateTime( 0 ); } );
		engine.on( 'error', function () {
			setPlaying( false );
			var msg = el( 'p', { 'class': 'tn-player__notice', role: 'alert', text: t( 'error', 'Não foi possível reproduzir o áudio.' ) } );
			controls.appendChild( msg );
		} );
		if ( cfg.playback === 'browser' ) {
			engine.setRate( parseFloat( rateSel.value ) );
		}
		updateTime( 0 );

		// Pause browser speech when leaving the page.
		window.addEventListener( 'pagehide', function () {
			if ( engine.isPlaying() && cfg.playback === 'browser' ) {
				engine.pause();
			}
		} );
	}

	function initTranscript( root ) {
		var details = root.querySelector( '[data-tn-transcript]' );
		if ( ! details ) {
			return;
		}
		var summary = details.querySelector( 'summary' );
		details.addEventListener( 'toggle', function () {
			summary.textContent = details.open ? t( 'hideText', 'Ocultar texto' ) : t( 'showText', 'Ver texto' );
		} );
	}

	function init( root ) {
		var cfg;
		try {
			cfg = JSON.parse( root.getAttribute( 'data-tn-player' ) || '{}' );
		} catch ( e ) {
			return;
		}
		initTranscript( root );
		var engine;
		if ( cfg.playback === 'audio' ) {
			engine = new AudioEngine( root, cfg );
		} else {
			var body = root.querySelector( '[data-tn-transcript-body]' );
			engine = new SpeechEngine( root, cfg, prepareSentences( body ) );
		}
		if ( ! engine.supported ) {
			var controls = root.querySelector( '[data-tn-controls]' );
			controls.appendChild( el( 'p', { 'class': 'tn-player__notice', text: t( 'unsupported', 'Seu navegador não oferece síntese de voz. O texto da narrativa está disponível abaixo.' ) } ) );
			var details = root.querySelector( '[data-tn-transcript]' );
			if ( details ) {
				details.open = true;
			}
			return;
		}
		buildUI( root, cfg, engine );
	}

	function boot() {
		Array.prototype.slice.call( document.querySelectorAll( '[data-tn-player]' ) ).forEach( function ( root ) {
			if ( ! root.classList.contains( 'tn-player--init' ) ) {
				root.classList.add( 'tn-player--init' );
				init( root );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
	window.tnPlayerBoot = boot;
}() );
