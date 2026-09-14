# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/); versionamento semântico.

## [1.2.1] — 2026-09-14

### Adicionado
- Exclusão em lote na aba Narrativas (capability `manage`): coluna de checkbox + "selecionar todas" (estado indeterminado quando parcial), barra de ações com "Excluir selecionadas", "Excluir todas do filtro atual (N)" quando o total supera a página (o JS repete `POST /narratives/bulk-delete {all, status, collection_id}` em lotes de 200 até `remaining` = 0) e "Limpar seleção". `NarrativeManager::delete_many()` / `delete_by_filter()`; rota `POST /narratives/bulk-delete` (ids explícitos ≤ 500 ou filtro).

## [1.2.0] — 2026-09-14

Resposta ao relato de narrações com fatos que não estavam no item. Auditoria das
fontes de invenção: (1) a IA — prompts que pediam "cena de abertura" e
"documentário de rádio" incentivavam floreio e contextualização; (2) a camada
de fala — expansão de abreviações ambíguas (`R.` → "rua" em iniciais de nome,
`Cap.` → "capítulo", `Sec.` → "século") e algarismos romanos após sobrenome
(`Fulano L.` → "cinquenta"); (3) o fraseador de metadados — frases
interpretativas ("Está situado em", "Vem de"). Todas corrigidas abaixo.

### Adicionado
- `Narrative\FaithfulnessChecker`: verificação determinística frase a frase da narração contra as fontes do item (números com 2+ dígitos, nomes próprios fora do início de frase, sobreposição lexical mínima). `NarrativeGenerator` roda a verificação, pede UMA correção à IA (`prompts/faithfulness-fix.php`), remove o que continuar sem apoio e rejeita o resultado (`tn_ai_unfaithful` → template) se sobrar menos de 25 palavras. Resultado gravado em `stats.faithfulness`; `GET /items/{id}/faithfulness` e painel "Fidelidade" em Narrativas → Fontes e saúde (inclusive após edição humana).
- Opção `max_words` (padrão 280 ≈ 2 min a 150 wpm) com `Modes::target_words_for()`: teto para todos os modos (IA, template, `faithful`, `detailed`); excedente condensado por `ExtractiveSummarizer` (frases originais, nunca reescrita). Fontes com < 1500 caracteres recebem instrução de resumo breve e literal e alvo de 120 palavras.
- Provedores de IA no padrão do Oráculo Tainacan: `ClaudeProvider` (Messages API), `GroqProvider`, `DeepSeekProvider`; `catalog()`/`description()` na interface; `ProviderManager::make()` com overrides não salvos e `ui_providers()`; `POST /providers/models` (lista da conta com chave ainda não salva); `/providers/test` aceita overrides; aba IA com cards de provedor, select de modelos + "(configurado)", botões "Buscar modelos da conta"/"Testar conexão"; chaves próprias `openai_api_key`/`claude_api_key`/`groq_api_key`/`deepseek_api_key` (constantes `TN_*_API_KEY`), `openai_model`/`claude_model`/`groq_model`/`deepseek_model`. OpenAI: `max_completion_tokens` e sem temperature para GPT-5/o-series, com fallback ao erro `unsupported_parameter`.

### Alterado
- Prompts (system + modos) reescritos com a "regra zero — nada de invenção": cada frase rastreável às fontes, sem contexto externo, sem cenas/ambientes/gestos, sem completar lacunas; `{brevity}` para fontes curtas; limite de palavras explícito. Modo "História contextualizada" renomeado "Relato encadeado". `PROMPT_VERSION` = 3.
- `SpeechText`: tabela de abreviações reduzida ao inequívoco; tokens de 1 letra nunca expandem; `DOT_ONLY`, `NUMERIC_CONTEXT` (art./fl./vol./pág. só antes de número) e `STATE_NEEDS_CONTEXT` (SE/AM/TO/PA/… só após "(", "-", "/"); romanos após nome exigem 2+ letras e valor ≤ 40; faixas de anos só 1000–2099; bloco de abreviações roda antes das regras numéricas.
- `MetadataPhraser`: frases neutras que só reafirmam o rótulo ("Data registrada:", "Local registrado:", "Assuntos:", "Tipo de documento:"); campos ambíguos (editora, fonte, dimensões) ficam literais "Rótulo: valor."
- Nota de proveniência pública explicita que a narração usa apenas o que está no item e foi verificada frase a frase.
- Coverage sweep não regenera itens marcados `stats.ai_unfaithful` (o template é a resposta segura para eles).
- `QueueManager::process()` sem `ini_get('safe_mode')` (removido no PHP 5.4).

## [1.1.1] — 2026-09-14

### Alterado
- `assets/css/admin.css` reescrito com tokens de escala tipográfica (`--tn-fs-2xs` … `--tn-fs-3xl`) e de espaçamento (`--tn-sp-1` … `--tn-sp-6`); todo texto do admin passou a resolver para um desses passos em vez de valores px/rem ad hoc (fonte de tamanhos inconsistentes reportada pelo usuário).
- Cards do dashboard (`tn-card`) redesenhados: ícone circular tintado por modificador (`ok`/`warn`/`danger`/`info`/`muted`, cada um com glifo próprio via `::before`), corpo com hierarquia número/rótulo mais clara, hover com elevação e sombra; adicionado o modificador `--info` que faltava no CSS (cards "Pendentes" e "Tempo total de áudio" usavam a classe sem estilo correspondente). `tab-dashboard.php` ganhou o wrapper `tn-card__icon`/`tn-card__body` (cartão vira `<span>` em vez de `<a>` quando não há link, em vez de link vazio).
- `.tn-table th` dividido em `thead th` (cabeçalho de coluna, uppercase discreto) e `tbody th[scope="row"]` (rótulo de linha, peso semibold, caixa normal) — a tabela de Diagnóstico não fica mais inteira em maiúsculas.
- `assets/css/player.css`: mesmos tokens de escala (relativos a `em`, herdando o tamanho do tema hospedeiro) aplicados a título, meta, badge, transcript, tempo e nota de proveniência.
- Validado renderizando as 9 abas do admin via WP-CLI + Chrome headless (screenshots) e reconferindo phpcs/phpunit.

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
