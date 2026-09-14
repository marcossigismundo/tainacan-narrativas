/**
 * Tainacan Narrativas — public player (Vanilla JS, no dependencies).
 *
 * Two engines behind one accessible UI:
 *  - AudioEngine: pre-generated audio file (<audio>), Media Session API.
 *  - SpeechEngine: Web Speech API over the stored transcript. The server
 *    already split the script into sentences and rewrote each one for
 *    speech (data-tn-speech); this engine only picks a pleasant voice and
 *    feeds short utterances, working around the well-known Chrome bugs
 *    (utterances > ~15 s are cut, speech dies after ~15 s unless resumed,
 *    the first syllables vanish when speak() follows cancel() immediately).
 *
 * Nothing is fetched from a CDN and nothing is generated on page view.
 * ES5 on purpose (no lookbehind regex, no arrow functions): older Safari
 * must still render the player.
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

	var RATES = [ 0.75, 0.9, 1, 1.1, 1.25, 1.5, 1.75, 2 ];

	/* Voice names known to be female / male on Windows, macOS, iOS, Android and Chrome. */
	var FEMALE_VOICES = [ 'francisca', 'thalita', 'brenda', 'elza', 'giovanna', 'leila', 'leticia', 'letícia', 'manuela', 'yara', 'maria', 'heloisa', 'heloísa', 'luciana', 'fernanda', 'joana', 'catarina', 'ines', 'inês', 'raquel', 'vitoria', 'vitória', 'camila', 'isabela', 'julia', 'júlia', 'female', 'feminina', 'mulher', 'google português do brasil', 'brasil' ];
	var MALE_VOICES = [ 'daniel', 'antonio', 'antônio', 'fabio', 'fábio', 'donato', 'humberto', 'julio', 'júlio', 'nicolau', 'valerio', 'valério', 'felipe', 'duarte', 'joaquim', 'ricardo', 'alex', 'male', 'masculina', 'homem' ];
	/* Quality markers in voice names (neural/online voices sound far better than legacy SAPI ones). */
	var QUALITY_MARKERS = [ 'natural', 'neural', 'online', 'premium', 'enhanced', 'aprimorad', 'wavenet', 'siri' ];
	var LEGACY_MARKERS = [ 'desktop', 'compact', 'espeak', 'mbrola' ];

	var IS_DESKTOP_CHROMIUM = /Chrome|Chromium|Edg\//.test( navigator.userAgent ) && ! /Mobile|Android|iPhone|iPad/.test( navigator.userAgent );

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
	/* Voice selection                                                   */
	/* ---------------------------------------------------------------- */
	function nameHas( name, list ) {
		for ( var i = 0; i < list.length; i++ ) {
			if ( name.indexOf( list[ i ] ) !== -1 ) {
				return true;
			}
		}
		return false;
	}

	function scoreVoice( v, lang, base, hint, gender ) {
		var name = ( v.name || '' ).toLowerCase();
		var vlang = ( v.lang || '' ).toLowerCase().replace( '_', '-' );
		var score = 0;
		if ( vlang === lang ) {
			score += 100;
		} else if ( vlang.indexOf( base ) === 0 ) {
			score += 40;
		} else {
			return -1;
		}
		if ( hint && name.indexOf( hint ) !== -1 ) {
			score += 500;
		}
		var female = nameHas( name, FEMALE_VOICES );
		var male = nameHas( name, MALE_VOICES );
		if ( gender === 'female' ) {
			score += female ? 60 : ( male ? -60 : 0 );
		} else if ( gender === 'male' ) {
			score += male ? 60 : ( female ? -60 : 0 );
		}
		if ( nameHas( name, QUALITY_MARKERS ) ) {
			score += 30;
		}
		if ( nameHas( name, LEGACY_MARKERS ) ) {
			score -= 25;
		}
		if ( name.indexOf( 'google' ) !== -1 ) {
			score += 10; // Chrome's remote voice is clear and consistent.
		}
		if ( v.default ) {
			score += 1;
		}
		return score;
	}

	function pickVoice( voices, cfg ) {
		var lang = ( cfg.lang || 'pt-BR' ).toLowerCase();
		var base = lang.split( '-' )[ 0 ];
		var hint = ( cfg.voiceHint || '' ).toLowerCase();
		var gender = cfg.voiceGender || 'female';
		var best = null;
		var bestScore = -1;
		voices.forEach( function ( v ) {
			var s = scoreVoice( v, lang, base, hint, gender );
			if ( s > bestScore ) {
				bestScore = s;
				best = v;
			}
		} );
		return best;
	}

	function loadVoices( cb ) {
		var synth = window.speechSynthesis;
		var voices = synth.getVoices() || [];
		if ( voices.length ) {
			cb( voices );
			return;
		}
		var done = false;
		var finish = function () {
			if ( done ) {
				return;
			}
			done = true;
			cb( synth.getVoices() || [] );
		};
		if ( typeof synth.onvoiceschanged !== 'undefined' ) {
			synth.addEventListener( 'voiceschanged', finish, { once: true } );
		}
		window.setTimeout( finish, 1200 ); // Voices may never arrive (Firefox/Linux); do not block playback.
	}

	/* ---------------------------------------------------------------- */
	/* Speech engine (Web Speech API)                                    */
	/* ---------------------------------------------------------------- */
	function SpeechEngine( root, cfg, sentences ) {
		Emitter.call( this );
		this.supported = 'speechSynthesis' in window && 'SpeechSynthesisUtterance' in window && sentences.length > 0;
		this.cfg = cfg;
		this.sentences = sentences; // [{node, parts:[...], chars}]
		this.totalChars = sentences.reduce( function ( a, s ) { return a + s.chars; }, 0 );
		this.duration = cfg.duration > 0 ? cfg.duration : this.totalChars / 15;
		this.cps = this.totalChars / Math.max( 1, this.duration ); // spoken chars per second at rate 1
		this.index = 0;      // sentence
		this.part = 0;       // utterance inside the sentence
		this.playing = false;
		this.rate = cfg.rate || 1;
		this.pitch = cfg.pitch || 1;
		this.volume = 1;
		this.voice = null;
		this.voicesReady = false;
		this.startedAt = 0;
		this.partOffset = 0; // chars spoken in the current sentence before the current part
		this.spokenInPart = 0;
		this.timer = null;
		this.keepAlive = null;
		this.utterance = null;
		this.speakTimer = null;
		this.generation = 0; // Invalidates callbacks from cancelled utterances.
		if ( this.supported ) {
			var self = this;
			loadVoices( function ( voices ) {
				self.voice = pickVoice( voices, cfg );
				self.voicesReady = true;
				self.emit( 'voice', self.voice );
			} );
			if ( typeof window.speechSynthesis.onvoiceschanged !== 'undefined' ) {
				window.speechSynthesis.addEventListener( 'voiceschanged', function () {
					if ( ! self.playing ) {
						self.voice = pickVoice( window.speechSynthesis.getVoices() || [], cfg );
					}
				} );
			}
			this.emit( 'ready' );
		}
	}
	SpeechEngine.prototype = Object.create( Emitter.prototype );
	SpeechEngine.prototype.hasVolume = true;
	SpeechEngine.prototype.timeAt = function ( index, extra ) {
		var chars = 0;
		for ( var i = 0; i < index && i < this.sentences.length; i++ ) {
			chars += this.sentences[ i ].chars;
		}
		return ( chars + ( extra || 0 ) ) / this.cps;
	};
	SpeechEngine.prototype.currentTime = function () {
		var cur = this.sentences[ this.index ];
		var inSentence = this.partOffset + this.spokenInPart;
		if ( this.playing && this.startedAt ) {
			var estimated = ( Date.now() - this.startedAt ) / 1000 * this.rate * this.cps;
			inSentence = this.partOffset + Math.max( this.spokenInPart, estimated );
		}
		if ( cur ) {
			inSentence = Math.min( inSentence, cur.chars );
		}
		return Math.min( this.duration, this.timeAt( this.index, inSentence ) );
	};
	SpeechEngine.prototype.isPlaying = function () { return this.playing; };
	SpeechEngine.prototype.highlight = function ( index ) {
		this.sentences.forEach( function ( s, i ) {
			if ( s.node ) {
				s.node.classList.toggle( 'tn-current', i === index );
			}
		} );
	};
	SpeechEngine.prototype.finish = function () {
		this.stopTimers();
		this.playing = false;
		this.index = 0;
		this.part = 0;
		this.partOffset = 0;
		this.spokenInPart = 0;
		this.highlight( -1 );
		this.emit( 'end' );
	};
	SpeechEngine.prototype.speakCurrent = function () {
		var self = this;
		if ( this.index >= this.sentences.length ) {
			this.finish();
			return;
		}
		var s = this.sentences[ this.index ];
		if ( this.part >= s.parts.length ) {
			this.index += 1;
			this.part = 0;
			this.partOffset = 0;
			this.spokenInPart = 0;
			this.speakCurrent();
			return;
		}
		var text = s.parts[ this.part ];
		var gen = ++this.generation;
		var u = new window.SpeechSynthesisUtterance( text );
		u.lang = this.cfg.lang || 'pt-BR';
		if ( this.voice ) {
			u.voice = this.voice;
		}
		u.rate = this.rate;
		u.pitch = this.pitch;
		u.volume = this.volume;
		u.onboundary = function ( ev ) {
			if ( gen === self.generation && ev && typeof ev.charIndex === 'number' ) {
				self.spokenInPart = ev.charIndex;
			}
		};
		u.onend = function () {
			if ( gen !== self.generation || ! self.playing ) {
				return;
			}
			self.partOffset += text.length;
			self.spokenInPart = 0;
			self.part += 1;
			self.startedAt = Date.now();
			self.speakCurrent();
		};
		u.onerror = function ( ev ) {
			if ( gen !== self.generation ) {
				return;
			}
			if ( ev && ( ev.error === 'interrupted' || ev.error === 'canceled' ) ) {
				return;
			}
			// Some engines reject a voice mid-way; retry once with the default voice.
			if ( self.voice && ev && ( ev.error === 'voice-unavailable' || ev.error === 'synthesis-failed' || ev.error === 'synthesis-unavailable' ) ) {
				self.voice = null;
				self.speakCurrent();
				return;
			}
			self.playing = false;
			self.stopTimers();
			self.emit( 'error' );
		};
		this.utterance = u; // Keep a reference: Chrome garbage-collects utterances and never fires onend.
		this.startedAt = Date.now();
		this.spokenInPart = 0;
		this.highlight( this.index );
		window.speechSynthesis.speak( u );
	};
	/* Chrome stops after ~15 s of continuous speech unless nudged. */
	SpeechEngine.prototype.startTimers = function () {
		var self = this;
		this.stopTimers();
		this.timer = window.setInterval( function () {
			self.emit( 'time', self.currentTime() );
		}, 250 );
		if ( IS_DESKTOP_CHROMIUM ) {
			this.keepAlive = window.setInterval( function () {
				if ( self.playing && window.speechSynthesis.speaking && ! window.speechSynthesis.paused ) {
					window.speechSynthesis.pause();
					window.speechSynthesis.resume();
				}
			}, 10000 );
		}
	};
	SpeechEngine.prototype.stopTimers = function () {
		if ( this.timer ) {
			window.clearInterval( this.timer );
			this.timer = null;
		}
		if ( this.keepAlive ) {
			window.clearInterval( this.keepAlive );
			this.keepAlive = null;
		}
		if ( this.speakTimer ) {
			window.clearTimeout( this.speakTimer );
			this.speakTimer = null;
		}
	};
	/* speak() right after cancel() swallows the first syllables: defer a bit. */
	SpeechEngine.prototype.restart = function () {
		var self = this;
		this.generation += 1;
		window.speechSynthesis.cancel();
		if ( this.speakTimer ) {
			window.clearTimeout( this.speakTimer );
		}
		var go = function () {
			self.speakTimer = null;
			if ( self.playing ) {
				self.speakCurrent();
			}
		};
		if ( this.voicesReady ) {
			this.speakTimer = window.setTimeout( go, 120 );
		} else {
			loadVoices( function ( voices ) {
				self.voice = pickVoice( voices, self.cfg );
				self.voicesReady = true;
				self.speakTimer = window.setTimeout( go, 120 );
			} );
		}
	};
	SpeechEngine.prototype.play = function () {
		if ( this.playing ) {
			return;
		}
		this.playing = true;
		this.startTimers();
		this.emit( 'play' );
		this.restart();
	};
	SpeechEngine.prototype.pause = function () {
		if ( ! this.playing ) {
			return;
		}
		this.playing = false;
		this.generation += 1;
		window.speechSynthesis.cancel();
		this.stopTimers();
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
		this.index = Math.min( idx, this.sentences.length - 1 );
		this.part = 0;
		this.partOffset = 0;
		this.spokenInPart = 0;
		this.startedAt = Date.now();
		this.highlight( this.index );
		this.emit( 'time', this.currentTime() );
		if ( this.playing ) {
			this.restart();
		}
	};
	SpeechEngine.prototype.skip = function ( delta ) {
		this.seek( this.currentTime() + delta );
	};
	SpeechEngine.prototype.setRate = function ( r ) {
		this.rate = r;
		if ( this.playing ) {
			this.restart();
		}
	};
	SpeechEngine.prototype.setVolume = function ( v ) {
		this.volume = v;
		if ( this.utterance ) {
			this.utterance.volume = v;
		}
	};

	/* ---------------------------------------------------------------- */
	/* Transcript → sentences                                            */
	/* ---------------------------------------------------------------- */
	function splitLegacy( text ) {
		// Fallback for markup rendered without server-side sentences (admin test player).
		var out = [];
		var buf = '';
		var chars = text.split( '' );
		for ( var i = 0; i < chars.length; i++ ) {
			buf += chars[ i ];
			if ( /[.!?…]/.test( chars[ i ] ) && ( i + 1 >= chars.length || /\s/.test( chars[ i + 1 ] ) ) ) {
				out.push( buf.trim() );
				buf = '';
			}
		}
		if ( buf.trim() ) {
			out.push( buf.trim() );
		}
		return out;
	}

	function chunkParts( speech, max ) {
		if ( speech.length <= max ) {
			return [ speech ];
		}
		var words = speech.split( /\s+/ );
		var out = [];
		var buf = '';
		words.forEach( function ( w ) {
			if ( ! buf ) {
				buf = w;
			} else if ( buf.length + 1 + w.length <= max ) {
				buf += ' ' + w;
			} else {
				out.push( buf );
				buf = w;
			}
		} );
		if ( buf ) {
			out.push( buf );
		}
		return out;
	}

	function prepareSentences( body ) {
		var list = [];
		if ( ! body ) {
			return list;
		}
		var spans = Array.prototype.slice.call( body.querySelectorAll( '.tn-sentence' ) );
		if ( spans.length ) {
			spans.forEach( function ( span ) {
				var speech = span.getAttribute( 'data-tn-speech' ) || span.textContent || '';
				var parts;
				try {
					parts = JSON.parse( span.getAttribute( 'data-tn-parts' ) || 'null' );
				} catch ( e ) {
					parts = null;
				}
				if ( ! parts || ! parts.length ) {
					parts = chunkParts( speech, 170 );
				}
				list.push( { node: span, parts: parts, chars: Math.max( 1, speech.length ) } );
			} );
			return list;
		}
		Array.prototype.slice.call( body.querySelectorAll( 'p' ) ).forEach( function ( p ) {
			var parts = splitLegacy( p.textContent || '' );
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
				list.push( { node: span, parts: chunkParts( sentence, 170 ), chars: Math.max( 1, sentence.length ) } );
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
		var defaultRate = cfg.playback === 'browser' ? ( cfg.rate || 1 ) : 1;
		var hasDefault = RATES.indexOf( defaultRate ) !== -1;
		RATES.forEach( function ( r ) {
			var opt = el( 'option', { value: String( r ), text: r + '×' } );
			if ( ( hasDefault && r === defaultRate ) || ( ! hasDefault && r === 1 ) ) {
				opt.selected = true;
			}
			rateSel.appendChild( opt );
		} );
		if ( ! hasDefault && cfg.playback === 'browser' ) {
			var custom = el( 'option', { value: String( defaultRate ), text: defaultRate + '×' } );
			custom.selected = true;
			rateSel.insertBefore( custom, rateSel.firstChild );
		}
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

		var voiceNote = null;
		if ( cfg.playback === 'browser' ) {
			voiceNote = el( 'span', { 'class': 'tn-voice-name', text: '' } );
			row2.appendChild( voiceNote );
			engine.on( 'voice', function ( v ) {
				voiceNote.textContent = v ? ( t( 'voice', 'Voz' ) + ': ' + v.name ) : t( 'loadingVoices', 'Preparando a voz…' );
			} );
			if ( engine.voice ) {
				voiceNote.textContent = t( 'voice', 'Voz' ) + ': ' + engine.voice.name;
			}
		}

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
			if ( ! controls.querySelector( '.tn-player__notice' ) ) {
				controls.appendChild( el( 'p', { 'class': 'tn-player__notice', role: 'alert', text: t( 'error', 'Não foi possível reproduzir o áudio.' ) } ) );
			}
		} );
		if ( cfg.playback === 'browser' ) {
			engine.setRate( parseFloat( rateSel.value ) );
		}
		updateTime( 0 );

		// Clicking a sentence in the transcript jumps to it.
		var body = root.querySelector( '[data-tn-transcript-body]' );
		if ( body && engine.sentences ) {
			body.addEventListener( 'click', function ( ev ) {
				var span = ev.target && ev.target.closest ? ev.target.closest( '.tn-sentence' ) : null;
				if ( ! span ) {
					return;
				}
				for ( var i = 0; i < engine.sentences.length; i++ ) {
					if ( engine.sentences[ i ].node === span ) {
						engine.seek( engine.timeAt( i, 0 ) + 0.01 );
						if ( ! engine.isPlaying() ) {
							engine.play();
						}
						break;
					}
				}
			} );
		}

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
