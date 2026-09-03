<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TainacanNarrativas\TTS\AudioConcat;

final class AudioConcatTest extends TestCase {

	private function wav( string $pcm, int $rate = 8000 ): string {
		$fmt = pack( 'vvVVvv', 1, 1, $rate, $rate * 2, 2, 16 );
		return 'RIFF' . pack( 'V', 36 + strlen( $pcm ) ) . 'WAVE' . 'fmt ' . pack( 'V', 16 ) . $fmt . 'data' . pack( 'V', strlen( $pcm ) ) . $pcm;
	}

	public function test_wav_parse_join_and_duration(): void {
		$a = $this->wav( str_repeat( "\x00\x01", 8000 ) ); // 1 s
		$b = $this->wav( str_repeat( "\x00\x02", 4000 ) ); // 0.5 s
		$this->assertEqualsWithDelta( 1.0, AudioConcat::wav_duration( $a ), 0.01 );
		$joined = AudioConcat::join( array( $a, $b ), 'audio/wav' );
		$this->assertIsString( $joined );
		$this->assertEqualsWithDelta( 1.5, AudioConcat::wav_duration( $joined ), 0.01 );
		$parsed = AudioConcat::parse_wav( $joined );
		$this->assertSame( 24000, strlen( $parsed['data'] ) );
	}

	public function test_wav_join_rejects_mismatched_formats_and_garbage(): void {
		$a = $this->wav( 'aa', 8000 );
		$b = $this->wav( 'bb', 16000 );
		$this->assertInstanceOf( WP_Error::class, AudioConcat::join( array( $a, $b ), 'audio/wav' ) );
		$this->assertNull( AudioConcat::parse_wav( 'garbage' ) );
		$this->assertInstanceOf( WP_Error::class, AudioConcat::join( array(), 'audio/wav' ) );
	}

	public function test_mp3_join_strips_id3v2_from_subsequent_parts(): void {
		$frame = "\xFF\xFB\x90\x00" . str_repeat( "\x00", 10 );
		$id3   = 'ID3' . "\x03\x00\x00" . "\x00\x00\x00\x0A" . str_repeat( 'T', 10 );
		$part1 = $id3 . $frame;
		$part2 = $id3 . $frame . 'TAG' . str_repeat( 'x', 125 );
		$out   = AudioConcat::join( array( $part1, $part2 ), 'audio/mpeg' );
		$this->assertSame( $id3 . $frame . $frame, $out );
	}

	public function test_single_part_is_returned_unchanged(): void {
		$this->assertSame( 'abc', AudioConcat::join( array( 'abc' ), 'audio/ogg' ) );
		$this->assertInstanceOf( WP_Error::class, AudioConcat::join( array( 'a', 'b' ), 'audio/ogg' ) );
	}
}
