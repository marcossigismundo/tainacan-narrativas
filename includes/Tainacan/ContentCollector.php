<?php
/**
 * Builds the normalized documentary corpus of an item.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Tainacan;

use TainacanNarrativas\Documents\ExtractionResult;
use TainacanNarrativas\Documents\ExtractorManager;
use TainacanNarrativas\Narrative\Normalizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Output shape (see AGENTS.md):
 *
 *  [
 *    'item_id', 'collection_id', 'collection_name', 'title', 'description', 'language',
 *    'metadata'    => [ [ 'id', 'label', 'value', 'type' ], ... ],   // public metadata only
 *    'document'    => [ 'id', 'kind', 'mime', 'filename', 'status', 'method', 'chars', 'pages', 'text', 'message', 'signature' ],
 *    'attachments' => [ same shape... ],
 *    'sources'     => [ 'metadata:12', 'document:456', 'attachment:789', ... ],
 *    'health'      => [ 'notes' => [ [ 'level' => ok|warn|info, 'text' => '...' ], ... ], 'skipped' => [...] ],
 *  ]
 *
 * Only Tainacan repositories/entities are used — no SQL against core tables.
 */
final class ContentCollector {

	/**
	 * Per-value cap for metadata (audio narration, not a data dump).
	 */
	private const MAX_META_VALUE_CHARS = 2000;

	/**
	 * Extractor manager.
	 *
	 * @var ExtractorManager
	 */
	private ExtractorManager $extractor;

	/**
	 * Constructor.
	 *
	 * @param ExtractorManager|null $extractor Extractor manager.
	 */
	public function __construct( ?ExtractorManager $extractor = null ) {
		$this->extractor = $extractor ?? new ExtractorManager();
	}

	/**
	 * Collects the corpus of an item.
	 *
	 * @param \Tainacan\Entities\Item $item   Item.
	 * @param array<string,mixed>     $config Effective collection config (CollectionSettings::effective()).
	 * @return array<string,mixed>
	 */
	public function collect( \Tainacan\Entities\Item $item, array $config ): array {
		$item_id    = (int) $item->get_id();
		$collection = $item->get_collection();
		$col_id     = $collection instanceof \Tainacan\Entities\Collection ? (int) $collection->get_id() : (int) $item->get_collection_id();
		$notes      = array();
		$skipped    = array(
			'private_metadata' => 0,
			'web_archives'     => 0,
			'over_limit'       => 0,
		);

		$title       = Normalizer::clean( wp_strip_all_tags( (string) $item->get_title() ) );
		$description = ! empty( $config['include_description'] ) ? Normalizer::html_to_text( (string) $item->get_description() ) : '';

		// --- Metadata (public only) ---
		$metadata = array();
		if ( $collection instanceof \Tainacan\Entities\Collection && class_exists( '\Tainacan\Repositories\Metadata' ) ) {
			$metadata = $this->collect_metadata( $item, $collection, $config, $skipped );
		}

		// --- Main document ---
		$document = array();
		if ( ! empty( $config['include_document'] ) ) {
			$document = $this->collect_document( $item, (int) $config['max_chars_file'], $skipped );
		}

		// --- Attachments ---
		$attachments = array();
		if ( ! empty( $config['include_attachments'] ) ) {
			$attachments = $this->collect_attachments( $item, $config, $skipped );
		}

		// --- Global character budget: document first, then attachments ---
		$budget = (int) $config['max_chars_item'];
		if ( $budget > 0 ) {
			$used = mb_strlen( $title ) + mb_strlen( $description );
			foreach ( $metadata as $m ) {
				$used += mb_strlen( $m['value'] );
			}
			if ( ! empty( $document['text'] ) ) {
				$allowed           = max( 0, $budget - $used );
				$document['text']  = Normalizer::truncate( $document['text'], $allowed );
				$document['chars'] = mb_strlen( $document['text'] );
				$used             += $document['chars'];
			}
			foreach ( $attachments as $i => $att ) {
				if ( empty( $att['text'] ) ) {
					continue;
				}
				$allowed = max( 0, $budget - $used );
				if ( $allowed < 200 ) {
					$attachments[ $i ]['text']    = '';
					$attachments[ $i ]['chars']   = 0;
					$attachments[ $i ]['status']  = ExtractionResult::TOO_LARGE;
					$attachments[ $i ]['message'] = __( 'Ignorado: limite total de caracteres do item atingido.', 'tainacan-narrativas' );
					++$skipped['over_limit'];
					continue;
				}
				$attachments[ $i ]['text']  = Normalizer::truncate( $att['text'], $allowed );
				$attachments[ $i ]['chars'] = mb_strlen( $attachments[ $i ]['text'] );
				$used                      += $attachments[ $i ]['chars'];
			}
		}

		// --- Sources list & health notes ---
		$sources = array();
		foreach ( $metadata as $m ) {
			$sources[] = 'metadata:' . $m['id'];
		}
		/* translators: %d: number of public metadata with values. */
		$notes[] = $this->note( $metadata ? 'ok' : 'warn', sprintf( _n( '%d metadado com valor', '%d metadados com valor', count( $metadata ), 'tainacan-narrativas' ), count( $metadata ) ) );
		$notes[] = $this->note( '' !== $description ? 'ok' : 'info', '' !== $description ? __( 'Descrição presente', 'tainacan-narrativas' ) : __( 'Sem descrição', 'tainacan-narrativas' ) );

		if ( $document ) {
			if ( ! empty( $document['text'] ) ) {
				$sources[] = 'document:' . $document['id'];
			}
			$notes[] = $this->doc_note( __( 'Documento', 'tainacan-narrativas' ), $document );
		} else {
			$notes[] = $this->note( 'info', __( 'Item sem documento principal', 'tainacan-narrativas' ) );
		}
		foreach ( $attachments as $att ) {
			if ( ! empty( $att['text'] ) ) {
				$sources[] = 'attachment:' . $att['id'];
			}
			$notes[] = $this->doc_note( __( 'Anexo', 'tainacan-narrativas' ), $att );
		}
		if ( $skipped['private_metadata'] > 0 ) {
			/* translators: %d: number of private metadata skipped. */
			$notes[] = $this->note( 'info', sprintf( __( '%d metadado(s) privado(s) não utilizado(s)', 'tainacan-narrativas' ), $skipped['private_metadata'] ) );
		}
		if ( $skipped['web_archives'] > 0 ) {
			/* translators: %d: number of web archive files skipped. */
			$notes[] = $this->note( 'info', sprintf( __( '%d arquivo(s) web archive (.wacz/.warc) reservado(s) ao WACZ Player', 'tainacan-narrativas' ), $skipped['web_archives'] ) );
		}

		$corpus = array(
			'item_id'         => $item_id,
			'collection_id'   => $col_id,
			'collection_name' => $collection instanceof \Tainacan\Entities\Collection ? Normalizer::clean( wp_strip_all_tags( (string) $collection->get_name() ) ) : '',
			'title'           => $title,
			'description'     => $description,
			'language'        => (string) ( $config['language'] ?? 'pt-BR' ),
			'metadata'        => $metadata,
			'document'        => $document,
			'attachments'     => $attachments,
			'sources'         => $sources,
			'health'          => array(
				'notes'   => $notes,
				'skipped' => $skipped,
			),
		);

		/**
		 * Filters the documentary corpus of an item before scripting/hashing.
		 *
		 * @param array<string,mixed> $corpus  Corpus.
		 * @param int                 $item_id Item ID.
		 */
		$filtered = apply_filters( 'tainacan_narrativas_source_content', $corpus, $item_id );
		return is_array( $filtered ) ? $filtered : $corpus;
	}

	/**
	 * Public metadata with values, honoring the collection allowlist/order.
	 *
	 * @param \Tainacan\Entities\Item       $item       Item.
	 * @param \Tainacan\Entities\Collection $collection Collection.
	 * @param array<string,mixed>           $config     Config.
	 * @param array<string,int>             $skipped    Counters (by ref).
	 * @return array<int,array<string,mixed>>
	 */
	private function collect_metadata( \Tainacan\Entities\Item $item, \Tainacan\Entities\Collection $collection, array $config, array &$skipped ): array {
		try {
			$all = \Tainacan\Repositories\Metadata::get_instance()->fetch_by_collection(
				$collection,
				array( 'post_status' => array( 'publish', 'private' ) )
			);
		} catch ( \Throwable $e ) {
			return array();
		}

		$allow = array_map( 'intval', (array) ( $config['metadata'] ?? array() ) );
		$order = array_map( 'intval', (array) ( $config['metadata_order'] ?? array() ) );
		$rows  = array();

		foreach ( (array) $all as $metadatum ) {
			if ( ! $metadatum instanceof \Tainacan\Entities\Metadatum ) {
				continue;
			}
			$mid  = (int) $metadatum->get_id();
			$type = (string) $metadatum->get_metadata_type();

			// Title/description are handled as dedicated fields.
			if ( str_ends_with( $type, 'Core_Title' ) || str_ends_with( $type, 'Core_Description' ) ) {
				continue;
			}
			// Never expose private metadata to a narrative (and never to an external AI).
			if ( 'publish' !== (string) $metadatum->get_status() ) {
				++$skipped['private_metadata'];
				continue;
			}
			if ( $allow && ! in_array( $mid, $allow, true ) ) {
				continue;
			}
			try {
				$ime = new \Tainacan\Entities\Item_Metadata_Entity( $item, $metadatum );
				if ( ! $ime->has_value() ) {
					continue;
				}
				$value = (string) $ime->get_value_as_string();
			} catch ( \Throwable $e ) {
				continue;
			}
			$value = Normalizer::clean( wp_strip_all_tags( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
			if ( '' === $value ) {
				continue;
			}
			$rows[] = array(
				'id'    => $mid,
				'label' => Normalizer::clean( wp_strip_all_tags( (string) $metadatum->get_name() ) ),
				'value' => Normalizer::truncate( $value, self::MAX_META_VALUE_CHARS ),
				'type'  => substr( $type, (int) strrpos( $type, '\\' ) + 1 ),
			);
		}

		if ( $order ) {
			$pos = array_flip( $order );
			usort(
				$rows,
				static function ( array $a, array $b ) use ( $pos ): int {
					$pa = $pos[ $a['id'] ] ?? PHP_INT_MAX;
					$pb = $pos[ $b['id'] ] ?? PHP_INT_MAX;
					return $pa <=> $pb;
				}
			);
		}
		return $rows;
	}

	/**
	 * Main document entry.
	 *
	 * @param \Tainacan\Entities\Item $item      Item.
	 * @param int                     $max_chars Per-file cap.
	 * @param array<string,int>       $skipped   Counters (by ref).
	 * @return array<string,mixed>
	 */
	private function collect_document( \Tainacan\Entities\Item $item, int $max_chars, array &$skipped ): array {
		$type = (string) $item->get_document_type();
		$doc  = (string) $item->get_document();
		if ( '' === $type || '' === $doc ) {
			return array();
		}
		if ( 'text' === $type ) {
			$text = Normalizer::truncate( Normalizer::html_to_text( $doc ), $max_chars );
			return array(
				'id'        => 0,
				'kind'      => 'text',
				'mime'      => 'text/html',
				'filename'  => '',
				'status'    => '' !== $text ? ExtractionResult::OK : ExtractionResult::EMPTY,
				'method'    => 'inline_text',
				'chars'     => mb_strlen( $text ),
				'pages'     => 0,
				'text'      => $text,
				'message'   => '',
				'signature' => md5( $doc ),
			);
		}
		if ( 'url' === $type ) {
			return array(
				'id'        => 0,
				'kind'      => 'url',
				'mime'      => '',
				'filename'  => (string) wp_parse_url( $doc, PHP_URL_HOST ),
				'status'    => ExtractionResult::UNSUPPORTED,
				'method'    => 'url',
				'chars'     => 0,
				'pages'     => 0,
				'text'      => '',
				'message'   => __( 'Documento externo (URL): conteúdo remoto não é lido.', 'tainacan-narrativas' ),
				'signature' => md5( $doc ),
			);
		}
		return $this->attachment_entry( (int) $doc, $max_chars, (int) $item->get_id(), $skipped, 'document' );
	}

	/**
	 * Attachments (excluding the document and the thumbnail), limited.
	 *
	 * @param \Tainacan\Entities\Item $item    Item.
	 * @param array<string,mixed>     $config  Config.
	 * @param array<string,int>       $skipped Counters (by ref).
	 * @return array<int,array<string,mixed>>
	 */
	private function collect_attachments( \Tainacan\Entities\Item $item, array $config, array &$skipped ): array {
		$max  = max( 0, (int) $config['max_attachments'] );
		$list = array();
		try {
			$posts = $item->get_attachments();
		} catch ( \Throwable $e ) {
			return array();
		}
		$count = 0;
		foreach ( (array) $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			if ( $count >= $max ) {
				++$skipped['over_limit'];
				continue;
			}
			$entry = $this->attachment_entry( (int) $post->ID, (int) $config['max_chars_file'], (int) $item->get_id(), $skipped, 'attachment' );
			if ( ExtractionResult::WEB_ARCHIVE === $entry['status'] ) {
				continue; // Counted in $skipped, not listed.
			}
			$list[] = $entry;
			++$count;
		}
		return $list;
	}

	/**
	 * Entry for an attachment (document or extra attachment).
	 *
	 * @param int               $attachment_id Attachment ID.
	 * @param int               $max_chars     Per-file cap.
	 * @param int               $item_id       Item ID.
	 * @param array<string,int> $skipped       Counters (by ref).
	 * @param string            $kind          document|attachment.
	 * @return array<string,mixed>
	 */
	private function attachment_entry( int $attachment_id, int $max_chars, int $item_id, array &$skipped, string $kind ): array {
		$post     = get_post( $attachment_id );
		$mime     = $post instanceof \WP_Post ? (string) $post->post_mime_type : '';
		$path     = (string) get_attached_file( $attachment_id );
		$filename = '' !== $path ? basename( $path ) : '';
		$size     = ( '' !== $path && file_exists( $path ) ) ? (int) filesize( $path ) : 0;
		$mtime    = ( '' !== $path && file_exists( $path ) ) ? (int) filemtime( $path ) : 0;

		$result = $this->extractor->extract_attachment( $attachment_id, $max_chars, $item_id );
		if ( ExtractionResult::WEB_ARCHIVE === $result->status ) {
			++$skipped['web_archives'];
		}
		return array(
			'id'        => $attachment_id,
			'kind'      => $kind,
			'mime'      => $mime,
			'filename'  => $filename,
			'status'    => $result->status,
			'method'    => $result->method,
			'chars'     => $result->chars(),
			'pages'     => $result->pages,
			'text'      => $result->text,
			'message'   => $result->message,
			'signature' => md5( $attachment_id . '|' . $size . '|' . $mtime ),
		);
	}

	/**
	 * Health note helper.
	 *
	 * @param string $level ok|warn|info.
	 * @param string $text  Text.
	 * @return array{level:string,text:string}
	 */
	private function note( string $level, string $text ): array {
		return array(
			'level' => $level,
			'text'  => $text,
		);
	}

	/**
	 * Health note for a document/attachment entry.
	 *
	 * @param string              $label Label prefix.
	 * @param array<string,mixed> $doc   Entry.
	 * @return array{level:string,text:string}
	 */
	private function doc_note( string $label, array $doc ): array {
		$name = '' !== (string) $doc['filename'] ? $doc['filename'] : strtoupper( (string) $doc['kind'] );
		if ( ExtractionResult::OK === $doc['status'] && (int) $doc['chars'] > 0 ) {
			/* translators: 1: Document/Attachment, 2: file name, 3: character count. */
			return $this->note( 'ok', sprintf( __( '%1$s %2$s: %3$s caracteres', 'tainacan-narrativas' ), $label, $name, number_format_i18n( (int) $doc['chars'] ) ) );
		}
		$level = ExtractionResult::REQUIRES_OCR === $doc['status'] ? 'warn' : 'info';
		/* translators: 1: Document/Attachment, 2: file name, 3: status message. */
		return $this->note( $level, sprintf( __( '%1$s %2$s: %3$s', 'tainacan-narrativas' ), $label, $name, (string) $doc['message'] ) );
	}
}
