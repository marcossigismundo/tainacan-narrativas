<?php
/**
 * Concatenation of audio chunks without external tools.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\TTS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FFmpeg is never assumed. MP3 streams can be joined frame-wise once ID3
 * tags are stripped; PCM WAV files are merged by rewriting the header.
 */
final class AudioConcat {

	/**
	 * Joins audio parts of the same format.
	 *
	 * @param string[] $parts Binary parts.
	 * @param string   $mime  Mime type.
	 * @return string|\WP_Error
	 */
	public static function join( array $parts, string $mime ) {
		$parts = array_values( array_filter( $parts, static fn( $p ) => is_string( $p ) && '' !== $p ) );
		if ( ! $parts ) {
			return new \WP_Error( 'tn_audio_empty', __( 'Nenhum áudio recebido do serviço de voz.', 'tainacan-narrativas' ) );
		}
		if ( 1 === count( $parts ) ) {
			return $parts[0];
		}
		if ( str_contains( $mime, 'mpeg' ) || str_contains( $mime, 'mp3' ) ) {
			return self::join_mp3( $parts );
		}
		if ( str_contains( $mime, 'wav' ) || str_contains( $mime, 'x-wav' ) ) {
			return self::join_wav( $parts );
		}
		return new \WP_Error( 'tn_audio_join', __( 'O formato de áudio não pode ser concatenado sem ffmpeg; reduza o roteiro ou use MP3/WAV.', 'tainacan-narrativas' ) );
	}

	/**
	 * Strips ID3v2 headers from the 2nd+ parts and concatenates frames.
	 *
	 * @param string[] $parts MP3 parts.
	 * @return string
	 */
	public static function join_mp3( array $parts ): string {
		$out = '';
		foreach ( $parts as $i => $part ) {
			if ( $i > 0 ) {
				$part = self::strip_id3v2( $part );
			}
			// Drop trailing ID3v1 tag (128 bytes starting with "TAG").
			if ( strlen( $part ) > 128 && 'TAG' === substr( $part, -128, 3 ) ) {
				$part = substr( $part, 0, -128 );
			}
			$out .= $part;
		}
		return $out;
	}

	/**
	 * Removes a leading ID3v2 container.
	 *
	 * @param string $data MP3 bytes.
	 * @return string
	 */
	private static function strip_id3v2( string $data ): string {
		if ( strlen( $data ) < 10 || 'ID3' !== substr( $data, 0, 3 ) ) {
			return $data;
		}
		$b    = array_values( unpack( 'C4', substr( $data, 6, 4 ) ) );
		$size = ( ( $b[0] & 0x7F ) << 21 ) | ( ( $b[1] & 0x7F ) << 14 ) | ( ( $b[2] & 0x7F ) << 7 ) | ( $b[3] & 0x7F );
		return substr( $data, 10 + $size );
	}

	/**
	 * Merges PCM WAV files with identical formats.
	 *
	 * @param string[] $parts WAV parts.
	 * @return string|\WP_Error
	 */
	public static function join_wav( array $parts ) {
		$fmt = null;
		$pcm = '';
		foreach ( $parts as $part ) {
			$parsed = self::parse_wav( $part );
			if ( null === $parsed ) {
				return new \WP_Error( 'tn_audio_wav', __( 'Trecho WAV inválido recebido do serviço de voz.', 'tainacan-narrativas' ) );
			}
			if ( null === $fmt ) {
				$fmt = $parsed['fmt'];
			} elseif ( $fmt !== $parsed['fmt'] ) {
				return new \WP_Error( 'tn_audio_wav', __( 'Trechos WAV com formatos diferentes não podem ser unidos.', 'tainacan-narrativas' ) );
			}
			$pcm .= $parsed['data'];
		}
		if ( null === $fmt ) {
			return new \WP_Error( 'tn_audio_wav', __( 'Nenhum WAV válido.', 'tainacan-narrativas' ) );
		}
		$header = 'RIFF' . pack( 'V', 36 + strlen( $pcm ) ) . 'WAVE'
			. 'fmt ' . pack( 'V', strlen( $fmt ) ) . $fmt
			. 'data' . pack( 'V', strlen( $pcm ) );
		return $header . $pcm;
	}

	/**
	 * Splits a WAV into its fmt chunk and PCM data.
	 *
	 * @param string $data WAV bytes.
	 * @return array{fmt:string,data:string}|null
	 */
	public static function parse_wav( string $data ): ?array {
		if ( strlen( $data ) < 12 || 'RIFF' !== substr( $data, 0, 4 ) || 'WAVE' !== substr( $data, 8, 4 ) ) {
			return null;
		}
		$pos = 12;
		$len = strlen( $data );
		$fmt = null;
		$pcm = null;
		while ( $pos + 8 <= $len ) {
			$id   = substr( $data, $pos, 4 );
			$size = (int) unpack( 'V', substr( $data, $pos + 4, 4 ) )[1];
			$body = substr( $data, $pos + 8, $size );
			if ( 'fmt ' === $id ) {
				$fmt = $body;
			} elseif ( 'data' === $id ) {
				$pcm = $body;
			}
			$pos += 8 + $size + ( $size % 2 );
		}
		if ( null === $fmt || null === $pcm ) {
			return null;
		}
		return array(
			'fmt'  => $fmt,
			'data' => $pcm,
		);
	}

	/**
	 * Duration of a PCM WAV in seconds (0 when unknown).
	 *
	 * @param string $data WAV bytes.
	 * @return float
	 */
	public static function wav_duration( string $data ): float {
		$parsed = self::parse_wav( $data );
		if ( null === $parsed || strlen( $parsed['fmt'] ) < 16 ) {
			return 0.0;
		}
		$f = unpack( 'vformat/vchannels/Vrate/Vbyterate/valign/vbits', substr( $parsed['fmt'], 0, 16 ) );
		if ( empty( $f['byterate'] ) ) {
			return 0.0;
		}
		return round( strlen( $parsed['data'] ) / (int) $f['byterate'], 2 );
	}
}
