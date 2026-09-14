# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/); versionamento semântico.

## [1.1.0] — 2026-09-14

### Adicionado
- `Narrative\SpeechText`: preparação do texto para a fala (datas → palavras, siglas hifenizadas sem "menos", abreviações, ordinais, algarismos romanos, moeda, URLs/e-mails, texto em caixa alta), segmentação em sentenças robusta a abreviações/iniciais/decimais e fragmentação em utterances ≤ 170 caracteres. Aplicada ao player (atributos `data-tn-speech`/`data-tn-parts`) e ao TTS de servidor (`audio_hash` inclui `SpeechText::VERSION`).
- `Narrative\ExtractiveSummarizer` e `Narrative\MetadataPhraser`: roteiro por template condensa documentos longos por saliência (nomes, datas, números, citações, posição) em ordem de leitura e lê metadados como frases; o modo "Leitura fiel" continua literal.
- Passo de análise da IA (`prompts/analysis.php`): dossiê do documento (tipo, pessoas, cronologia, passagens literais, fio condutor) antes da escrita; opção `ai_analysis` (padrão ligado), cache por hash.
- Cobertura automática: cron horário `tn_coverage_sweep` (`auto_coverage`, `coverage_batch`), `POST /collections/generate-all`, `POST /narratives/approve-all`, `GET /coverage`, painel "Cobertura das coleções" no dashboard; job `approve` na fila; fallbacks de template gerados durante queda da IA são regenerados quando a IA volta.
- Voz do navegador: ranking de vozes por idioma, qualidade (Natural/Online/Neural/Premium) e timbre (`browser_voice_gender`, padrão feminino), `browser_pitch`, nome da voz exibido no player, clique na sentença do transcript para pular.

### Alterado
- Prompts reescritos (system + modos + redução/consolidação) para narração oral rica, com abertura concreta, cobertura do documento inteiro, citações literais breves e proibição explícita de estrutura/vocabulário de texto automático; `SourceHasher::PROMPT_VERSION` = 2 (narrativas existentes ficam desatualizadas e são regeneradas pela cobertura automática).
- `NarrativeGenerator::post_process()` remove preâmbulos de chat, blocos `<think>`, linha-título solta, encerramentos e aberturas de frase típicas de IA ("Vale ressaltar que", "Em suma", "Neste registro"…).
- Erros `tn_ai_malformed`/`tn_ai_invalid_json` passam a ser reprocessados com backoff (antes caíam direto para o template).
- Orçamento de tokens de saída proporcional ao alvo de palavras (`ai_max_tokens` vira piso; padrão 4000); `ai_temperature` padrão 0,45; `ai_timeout` 180 s.
- Padrões para novas instalações: `editorial_flow` = `auto`, `trigger_on_save` = `queue`.
- `player.js` reescrito em ES5 estrito (sem lookbehind: o script inteiro falhava em Safari < 16.4), com espera pelas vozes, atraso após `cancel()`, keep-alive do Chrome, `onboundary` para progresso e retomada por trecho.

### Corrigido
- Fala cortada no meio de frases longas e sílabas perdidas ao iniciar/pausar/mudar velocidade (Chrome).
- Voz "Desktop" legada escolhida no Windows mesmo com voz neural disponível.

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
