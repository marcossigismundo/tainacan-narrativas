# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/); versionamento semântico.

## [1.0.0] — 2026-09-02

### Adicionado
- Coleta documental de itens Tainacan via repositórios oficiais (título, descrição, metadados públicos, documento principal e anexos) com allowlist/ordem por coleção.
- Extração de texto: reutilização do `document_content_index` do core e do `_tainacan_ocr_text` (tainacan-ocr-search); extratores próprios para PDF (smalot/pdfparser), DOCX, ODT, TXT/HTML/XML/CSV/JSON; detecção de PDF digitalizado (`requires_ocr`); arquivos `.wacz/.warc` reservados ao WACZ Player.
- Normalização, chunking (seção → parágrafo → sentença), hash SHA-256 de fontes + configuração, indicador determinístico de conteúdo (Insuficiente/Básico/Bom/Extenso).
- Roteiro por template configurável (sem IA) e narrativa assistida por IA com redução factual por chunk e consolidação; prompts em `prompts/` com regras de fidelidade documental e proteção contra instruções embutidas nas fontes.
- Provedores de IA: OpenAI-compatible (Ollama, LM Studio, vLLM, LocalAI…), OpenAI, Ollama, Google Gemini e WordPress AI Client (WP 7.0+).
- TTS: voz do navegador (Web Speech API, fallback universal), API compatível com OpenAI (Kokoro-FastAPI, LocalAI, OpenAI), Piper HTTP (WAV) e WordPress AI Client; concatenação MP3/WAV sem ffmpeg; áudio na Media Library.
- Fila própria em WP-Cron (tabela `wp_tn_jobs`) com lock atômico, orçamento de tempo, retentativas com backoff e fallback para template na última tentativa; detecção de alterações (item, metadados, documento, anexos) com verificação debounced e varredura diária.
- Versionamento de narrativas (`wp_tn_narratives`), retenção configurável, fluxo editorial com revisão humana (edição do roteiro nunca sobrescrita), aprovação e regeneração somente do áudio quando só a voz muda.
- Player público acessível (Vanilla JS): play/pause, ±10 s, progresso, tempo, volume, velocidade 0.75–2×, transcript expansível com destaque de sentença, download opcional, nota de proveniência, Media Session; injeção após anexos no tema Tainacan com fallbacks, shortcode `[tainacan_narrativa]` e bloco `tainacan-narrativas/player`.
- Administração em Tainacan → Narrativas (`\Tainacan\Pages`): painel, narrativas, coleções, IA, voz, processamento, configurações, diagnóstico e wizard inicial.
- REST `tainacan-narrativas/v1` com `permission_callback` por capability própria (`manage_`, `generate_`, `review_tainacan_narratives`) e endpoint público mínimo para o player.
- Segurança: SSRF (validação de esquema/host/porta, redes privadas opt-in, sem redirecionamentos), chaves via wp-config, logs com redação de segredos, sem CDN.
- WP-CLI: `wp tainacan-narrativas generate|queue|status|check`.
- Testes unitários (PHPUnit) e CI (phpcs + phpunit + Plugin Check).
