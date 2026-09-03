<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TainacanNarrativas\Documents\DocxExtractor;
use TainacanNarrativas\Documents\ExtractionResult;
use TainacanNarrativas\Documents\ExtractorManager;
use TainacanNarrativas\Documents\OdtExtractor;
use TainacanNarrativas\Documents\PdfExtractor;
use TainacanNarrativas\Documents\TextExtractor;

final class ExtractorsTest extends TestCase {

	private function tmp( string $ext ): string {
		return tempnam( sys_get_temp_dir(), 'tn' ) . '.' . $ext;
	}

	public function test_text_extractor_handles_txt_html_csv(): void {
		$x = new TextExtractor();
		$this->assertTrue( $x->supports( 'text/plain', 'a.txt' ) );
		$this->assertTrue( $x->supports( 'application/octet-stream', 'a.html' ) );

		$txt = $this->tmp( 'txt' );
		file_put_contents( $txt, "Primeira linha\n\n\nSegunda   linha" );
		$r = $x->extract( $txt, 'text/plain', 10000 );
		$this->assertTrue( $r->is_ok() );
		$this->assertSame( "Primeira linha\n\nSegunda linha", $r->text );

		$html = $this->tmp( 'html' );
		file_put_contents( $html, '<html><head><title>x</title><script>1</script></head><body><h1>Título</h1><p>Par&aacute;grafo</p></body></html>' );
		$r = $x->extract( $html, 'text/html', 10000 );
		$this->assertSame( "Título\n\nParágrafo", $r->text );

		$csv = $this->tmp( 'csv' );
		file_put_contents( $csv, "nome,ano\n\"Ana\",2020\nBia,2021\n" );
		$r = $x->extract( $csv, 'text/csv', 10000 );
		$this->assertSame( "nome; ano\nAna; 2020\nBia; 2021", $r->text );

		$r = $x->extract( $txt, 'text/plain', 8 );
		$this->assertLessThanOrEqual( 8, mb_strlen( $r->text ) );
	}

	public function test_docx_and_odt_extractors_read_zip_xml(): void {
		$docx = $this->tmp( 'docx' );
		$zip  = new ZipArchive();
		$zip->open( $docx, ZipArchive::CREATE );
		$zip->addFromString( 'word/document.xml', '<?xml version="1.0"?><w:document><w:body><w:p><w:r><w:t>Olá</w:t></w:r><w:r><w:tab/><w:t>mundo &amp; cia</w:t></w:r></w:p><w:p><w:r><w:t>Segundo</w:t></w:r></w:p></w:body></w:document>' );
		$zip->close();
		$r = ( new DocxExtractor() )->extract( $docx, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 10000 );
		$this->assertTrue( $r->is_ok() );
		$this->assertSame( "Olá mundo & cia\n\nSegundo", $r->text );

		$odt = $this->tmp( 'odt' );
		$zip = new ZipArchive();
		$zip->open( $odt, ZipArchive::CREATE );
		$zip->addFromString( 'content.xml', '<?xml version="1.0"?><office:document-content><office:body><office:text><text:h>Cabeçalho</text:h><text:p>Um<text:s/>dois<text:line-break/>três</text:p></office:text></office:body></office:document-content>' );
		$zip->close();
		$r = ( new OdtExtractor() )->extract( $odt, 'application/vnd.oasis.opendocument.text', 10000 );
		$this->assertSame( "Cabeçalho\n\nUm dois\ntrês", $r->text );

		$bad = $this->tmp( 'docx' );
		file_put_contents( $bad, 'not a zip' );
		$this->assertSame( ExtractionResult::ERROR, ( new DocxExtractor() )->extract( $bad, 'application/octet-stream', 100 )->status );
	}

	public function test_pdf_extractor_reads_text_layer(): void {
		if ( ! PdfExtractor::is_available() ) {
			$this->markTestSkipped( 'smalot/pdfparser not installed' );
		}
		$r = ( new PdfExtractor() )->extract( __DIR__ . '/../fixtures/text-layer.pdf', 'application/pdf', 10000 );
		$this->assertSame( 2, $r->pages );
		$this->assertContains( $r->status, array( ExtractionResult::OK, ExtractionResult::REQUIRES_OCR ) );
		if ( ExtractionResult::OK === $r->status ) {
			$this->assertStringContainsString( 'contains text', $r->text );
		}
	}

	public function test_pdf_without_text_reports_requires_ocr(): void {
		if ( ! PdfExtractor::is_available() ) {
			$this->markTestSkipped( 'smalot/pdfparser not installed' );
		}
		// Minimal valid PDF with one empty page (no text objects).
		$pdf  = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\nxref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000052 00000 n \n0000000101 00000 n \ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n168\n%%EOF";
		$file = $this->tmp( 'pdf' );
		file_put_contents( $file, $pdf );
		$r = ( new PdfExtractor() )->extract( $file, 'application/pdf', 10000 );
		$this->assertContains( $r->status, array( ExtractionResult::REQUIRES_OCR, ExtractionResult::EMPTY, ExtractionResult::ERROR ) );
		$this->assertFalse( $r->is_ok() );
	}

	public function test_web_archive_detection(): void {
		$this->assertTrue( ExtractorManager::is_web_archive( 'application/octet-stream', 'captura.wacz' ) );
		$this->assertTrue( ExtractorManager::is_web_archive( 'application/gzip', 'site.warc.gz' ) );
		$this->assertFalse( ExtractorManager::is_web_archive( 'application/pdf', 'doc.pdf' ) );
	}
}
