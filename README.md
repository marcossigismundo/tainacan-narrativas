# Tainacan Narrativas

Plugin WordPress que transforma itens [Tainacan](https://tainacan.org) em **experiências narrativas em áudio**: leitura documental dos metadados e documentos, narrativa opcional assistida por IA (sempre a partir das fontes do próprio item) e síntese de voz, com um player acessível na página pública do item.

Parte do ecossistema do [Memorial Digital da Pandemia](http://memorialdigitalcovid19.org.br/tainacan/), ao lado do [tainacan-dip-importer](https://github.com/marcossigismundo/tainacan-dip-importer), [tainacan-metadata-crowdsource](https://github.com/marcossigismundo/tainacan-metadata-crowdsource) (Tainacan Colab) e [tainacan-wacz-player](https://github.com/marcossigismundo/tainacan-wacz-player). É um plugin independente: não modifica o Tainacan, o tema nem os outros plugins.

```
OBJETO DIGITAL → METADADOS + DOCUMENTO + ANEXOS → CONTEÚDO DOCUMENTAL → NARRATIVA → VOZ → EXPERIÊNCIA DE ACESSO
```

## Sumário

- [O que é](#o-que-é)
- [Arquitetura](#arquitetura)
- [Instalação](#instalação)
- [Configuração](#configuração)
- [IA](#ia)
- [TTS](#tts)
- [Privacidade](#privacidade)
- [Segurança](#segurança)
- [Processamento (fila, hash, cache)](#processamento)
- [Player, shortcode e bloco](#player-shortcode-e-bloco)
- [Hooks para extensões](#hooks-para-extensões)
- [WP-CLI](#wp-cli)
- [REST API](#rest-api)
- [Diagnóstico](#diagnóstico)
- [Limitações conhecidas](#limitações-conhecidas)
- [Desenvolvimento](#desenvolvimento)

## O que é

Três conceitos separados de propósito:

| Camada | O que faz | Onde |
|---|---|---|
| **Leitura documental** | Roteiro estritamente montado com título + descrição + metadados públicos + texto do documento e dos anexos. Não interpreta, não acrescenta contexto. | `Narrative\ScriptBuilder` (template configurável) |
| **Narrativa assistida por IA** (opcional) | O modelo recebe **somente** as fontes do item, delimitadas, e produz um roteiro para ser ouvido — sem inventar datas, pessoas, lugares ou acontecimentos. | `Narrative\NarrativeGenerator` + `prompts/` |
| **Síntese de voz** | Camada independente: TTS neural local/institucional (Kokoro, Piper), API compatível com OpenAI, WordPress AI ou a voz do navegador do visitante. | `TTS\*` |

Princípio de desempenho: **gerar → armazenar → servir**. A página pública só reproduz conteúdo previamente gerado; IA e TTS rodam numa fila.

Funciona sem nenhuma chave de IA e em hospedagens simples (roteiro por template + voz do navegador).

## Arquitetura

```
Content Collector  →  Script Builder / Narrative Generator  →  TTS Provider  →  Audio Storage  →  Frontend Player
 (Tainacan APIs)        (template | IA + prompts)              (browser|api)   (Media Library)   (Vanilla JS)
```

```
tainacan-narrativas/
├── tainacan-narrativas.php        bootstrap magro (constantes, autoload, hooks de ativação)
├── includes/
│   ├── Core/        Plugin (wiring no init), Options, Capabilities, Lock, Activator/Deactivator
│   ├── Tainacan/    ItemDetector, ContentCollector, CollectionSettings, ChangeListener
│   ├── Documents/   ExtractorInterface, ExtractorManager (cache), Pdf/Docx/Odt/Text extractors, OcrProviderInterface
│   ├── Narrative/   Normalizer, Chunker, SourceHasher, ContentScore, Modes, PromptLoader, ScriptBuilder, NarrativeGenerator, NarrativeManager
│   ├── AI/          AIProviderInterface, OpenAICompatible, OpenAI, Ollama, Gemini, WordPressAI, ProviderManager
│   ├── TTS/         TTSProviderInterface, Browser, OpenAICompatibleTTS, PiperHttp, WordPressAITTS, AudioConcat, AudioStorage, ProviderManager
│   ├── Queue/       QueueManager (WP-Cron), JobRunner
│   ├── Database/    Tables, NarrativeRepository, JobRepository
│   ├── REST/        Controller (tainacan-narrativas/v1)
│   ├── Frontend/    Player, Shortcode, Block, Assets, views/player.php
│   ├── Admin/       AdminPage (\Tainacan\Pages), SettingsHandler, Diagnostics, views/
│   ├── Security/    Security (SSRF, endpoints, paths)
│   ├── Logging/     Logger (redação de segredos)
│   └── CLI/         Command
├── prompts/         system, faithful, documentary, storytelling, summary, detailed, accessible, children, chunk-summary, consolidate
├── assets/          css/player.css, css/admin.css, js/player.js, js/admin.js, js/block-editor.js
├── vendor/          smalot/pdfparser (distribuído)
├── tests/           PHPUnit (unit) + roteiro de integração
└── AGENTS.md, DEPENDENCIES.md, CHANGELOG.md, readme.txt
```

Banco de dados: `wp_tn_narratives` (uma linha por item × versão, `is_current`, hashes, roteiro gerado/editado, proveniência, status) e `wp_tn_jobs` (fila). Índices em `item_id`, `(item_id,is_current)`, `collection_id`, `status`, `source_hash`, `(status,run_after)`.

## Instalação

1. Envie a pasta (ou o ZIP de release) para `wp-content/plugins/` e ative. Requer WordPress 6.5+, PHP 8.0+ e Tainacan 1.0+.
2. Você será levado a **Tainacan → Narrativas** (grupo "Outros"), onde um wizard de 5 passos pede coleções, modo, voz, IA opcional e um item de teste. Pode ser pulado.
3. Nada é gerado nem enviado a serviços externos até que as narrativas sejam ativadas e pelo menos uma coleção seja habilitada.

Não exige `composer install`, `npm install`, build, CDN ou SSH.

## Configuração

Abas em **Tainacan → Narrativas**:

- **Painel** — cards (prontas, pendentes, revisão, desatualizadas, erro, tempo total de áudio, sem texto, OCR necessário) e geração rápida com "Visualizar fontes".
- **Narrativas** — tabela (item, coleção, status, modo, duração, última geração, IA, TTS) com ações: gerar, regenerar, ouvir, detalhes (roteiro editável, fontes e saúde, rastreabilidade), aprovar, verificar, excluir áudio, excluir.
- **Coleções** — opt-in por coleção; player automático; fontes (descrição, documento, anexos, limites); allowlist e ordem de metadados (somente públicos); modo; fluxo editorial; conteúdo sensível (automática / revisão obrigatória / não gerar); IA/TTS/voz; permissão de IA externa e de envio de anexos; download. "Gerar narrativas dos itens" enfileira a coleção.
- **IA** — provedor, endpoint, modelo, chave (mascarada ou por `wp-config.php`), timeout, temperatura, tokens, chunk; endpoints de rede privada; modo infantil.
- **Voz** — mecanismo (navegador, API compatível com OpenAI/Kokoro, Piper, WordPress AI), voz, formato, velocidade; preferências da voz do navegador; testes e áudio de teste.
- **Processamento** — fila (executar agora, limpar falhas), gatilho ao salvar (marcar desatualizada / enfileirar / nada), WP-Cron, lote, orçamento de tempo, tentativas, limites de caracteres/anexos, versões mantidas (1/3/5/todas).
- **Configurações** — ativar, player automático, download, nota de proveniência, modo padrão, fluxo editorial padrão (revisão humana recomendada), idioma, fontes padrão, template da leitura documental, debug, apagar dados ao desinstalar.
- **Diagnóstico** — WordPress, PHP, extensões, Tainacan, HTTPS, WP-Cron, REST, uploads, tabelas, pdfparser, indexação do core, IA/TTS selecionados e endpoints (só host), fila, último job, limites; botões Testar IA / Testar TTS / Gerar áudio de teste / Testar escrita / Executar fila; log recente sem segredos.

### Modos narrativos

`faithful` (leitura fiel), `documentary` (padrão), `storytelling` (história contextualizada), `summary` (1–3 min), `detailed` (5–10 min), `accessible` (linguagem simples) e `children` (somente se habilitado; nunca infantiliza temas sensíveis). Sem IA, os modos aplicam o template com o limite de palavras de cada modo.

### Fluxo editorial

- **A — automático**: roteiro → áudio.
- **B — revisão humana** (padrão, recomendado para acervos históricos): roteiro pendente → revisor edita/aprova → TTS. Uma edição humana fica em `edited_script`, com autor e data; o texto gerado é preservado em `generated_script`. Regenerações de um item cujo roteiro foi editado voltam para revisão mesmo no fluxo A — nunca substituem a edição silenciosamente.

## IA

`AI\AIProviderInterface` com implementações:

| id | Serviço | Configuração |
|---|---|---|
| `openai_compatible` | Qualquer `POST {base}/chat/completions` (Ollama, LM Studio, vLLM, LocalAI, OpenRouter, gateways institucionais) | URL base, modelo, chave opcional |
| `openai` | OpenAI | modelo, chave (`TN_AI_API_KEY`) |
| `ollama` | Ollama nativo (lista modelos em `/api/tags`) | URL, modelo |
| `gemini` | Google Gemini `generateContent` | modelo, chave (`TN_GEMINI_API_KEY`, enviada em header) |
| `wp_ai` | WordPress AI Client (WP 7.0+) — conectores do próprio site | nenhuma chave no plugin |

Pipeline: fontes normalizadas → (documentos maiores que o chunk) redução factual por trecho → consolidação → prompt do modo → pós-processamento (remove markdown e delimitadores vazados). Reduções intermediárias são cacheadas por hash em transients (7 dias). O painel mostra caracteres, chunks e **tokens estimados** (nunca custo).

Prompts em `prompts/*.php`. O system prompt exige uso exclusivo das fontes, proíbe criar datas/pessoas/lugares/acontecimentos e completar lacunas, preserva nomes e datas, evita sensacionalismo e trata **todo conteúdo entre `<<<SOURCE …>>>` e `<<<END_SOURCE>>>` como documentação, nunca como instrução** (defesa contra prompt injection em documentos do acervo).

## TTS

`TTS\TTSProviderInterface`:

| id | Mecanismo | Saída |
|---|---|---|
| `browser` | Web Speech API no dispositivo do visitante (fallback universal, zero bytes, sem servidor) | roteiro armazenado; áudio local |
| `openai_compatible` | `POST {base}/audio/speech` — **Kokoro-FastAPI** (open source, vozes pt-BR `pf_dora`, `pm_alex`, `pm_santa`, CPU), LocalAI, OpenedAI-Speech, OpenAI | MP3/WAV na Media Library |
| `piper_http` | Servidor HTTP do Piper (JSON `{text,voice}` ou texto puro) | WAV na Media Library |
| `wp_ai` | WordPress AI Client com texto-fala | conforme conector |

Estratégia: TTS neural configurado → gera e armazena; senão → voz do navegador. Roteiros longos são sintetizados em partes e concatenados (MP3 por quadros, WAV por reescrita de cabeçalho) sem ffmpeg. Trocar só a voz regenera apenas o áudio (o roteiro é reutilizado via `script_hash`/`audio_hash`).

Setup sugerido para pt-BR neural: [Kokoro-FastAPI](https://github.com/remsky/Kokoro-FastAPI) em Docker na mesma rede → URL base `http://kokoro:8880/v1`, modelo `kokoro`, voz `pf_dora`, formato `mp3`, e "Permitir endpoints de rede privada" ligado.

## Privacidade

- Somente **metadados públicos** entram na narrativa (privados são contados e ignorados).
- Por coleção: proibir IA externa; não enviar anexos; não gerar (sensível); revisão obrigatória.
- "Visualizar fontes" mostra exatamente o que será usado e enviado.
- Nunca são enviados: dados administrativos, logs, IDs desnecessários, usuários, e-mails, tokens.
- Nota de proveniência discreta no player ("Texto narrativo produzido automaticamente a partir das informações documentais deste registro." quando houver IA).

## Segurança

- Capabilities próprias: `manage_tainacan_narratives` (configurações, chaves, exclusão), `generate_tainacan_narratives`, `review_tainacan_narratives`. Administradores recebem as três na ativação; papéis com `manage_tainacan` recebem gerar + revisar.
- Toda rota REST tem `permission_callback` com capability real; o endpoint público exige item legível, publicado e coleção habilitada.
- Chaves: no banco (mascaradas; nunca em HTML/JS/logs/GET) ou em `wp-config.php` (`TN_AI_API_KEY`, `TN_GEMINI_API_KEY`, `TN_TTS_API_KEY`), que bloqueiam o campo no painel.
- SSRF: `Security::validate_endpoint()` valida esquema (http/https), userinfo, portas e redes privadas; requisições via `wp_safe_remote_request()`; redes privadas só com a opção explícita (manage) e sem redirecionamentos.
- Sem shell, sem caminhos arbitrários (áudio via `wp_upload_bits`, leitura só dentro de uploads), sem CDN.
- Logs redigem `Bearer`, `sk-…`, `AIza…`, `api_key`, `token`, `Authorization`.

## Processamento

- **Fila** `wp_tn_jobs` em WP-Cron (`tn_process_queue` a cada minuto, lote e orçamento de tempo configuráveis, lock `add_option` atômico) — sem Action Scheduler. Botão "Executar fila" e `wp tainacan-narrativas queue` para hosts sem cron confiável.
- **Etapas** extract → script → audio, idempotentes; **retentativas** com backoff 1/3/9 min; na última tentativa uma IA indisponível cai para o roteiro por template.
- **Lock por item** (15 min) impede gerações simultâneas.
- **Hash**: `source_hash = SHA-256(título + descrição + metadados + textos/assinaturas dos documentos + configuração narrativa)`; `script_hash`; `audio_hash = script + provedor + voz + velocidade + formato`.
- **Detecção de alterações**: `tainacan-insert`, `tainacan-api-item-updated`, `save_post`, hooks de anexos → limpeza do cache de extração + verificação debounced (`tn_check_item`, 90 s) → `stale` (ou enfileira, conforme o gatilho). Varredura diária (`tn_stale_sweep`) cobre mudanças fora dos hooks. Alterações via Tainacan Colab ou DIP Importer passam pelos mesmos hooks.
- **Cache**: texto extraído por anexo (post meta, assinatura tamanho+mtime), reduções por chunk (transients), roteiro e áudio (versões).

## Player, shortcode e bloco

- Injeção automática (por coleção) em `tainacan-interface-single-item-after-attachments` (tema Tainacan), `tainacan_single_item_content` (conteúdo padrão do core) e `the_content` (temas clássicos), com guarda anti-duplicação.
- `[tainacan_narrativa]`, `[tainacan_narrativa item_id="123" title="…" transcript="0"]`.
- Bloco dinâmico `tainacan-narrativas/player` (itemId 0 = item atual) para temas de blocos.
- Controles: play/pause/continuar, ±10 s, progresso com `aria-valuetext`, tempo, volume, velocidade 0.75–2×, "Ver texto" (`<details>` com destaque da sentença atual), download opcional, Media Session. Sem autoplay, ícones SVG locais, `system-ui`, tokens CSS espelhando `--tainacan-*`, teclado e foco visível.

## Hooks para extensões

```php
do_action( 'tainacan_narrativas_before_generate', $item_id );
do_action( 'tainacan_narrativas_after_generate', $item_id, $narrative_row );
apply_filters( 'tainacan_narrativas_source_content', $corpus, $item_id );
apply_filters( 'tainacan_narrativas_script', $script, $item_id );
apply_filters( 'tainacan_narrativas_system_prompt', $prompt, $language );
apply_filters( 'tainacan_narrativas_prompt', $prompt, $name );
apply_filters( 'tainacan_narrativas_modes', $modes );
apply_filters( 'tainacan_narrativas_ai_providers', $providers );
apply_filters( 'tainacan_narrativas_tts_providers', $providers );
apply_filters( 'tainacan_narrativas_extractors', $extractors );
apply_filters( 'tainacan_narrativas_ocr_providers', $providers );
apply_filters( 'tainacan_narrativas_extraction_result', $result, $attachment_id, $mime );
```

Como adicionar provedor, extrator ou modo: ver [AGENTS.md](AGENTS.md).

## WP-CLI

```bash
wp tainacan-narrativas generate 123 [--sync] [--force] [--mode=summary] [--skip-review]
wp tainacan-narrativas generate --collection=45 [--sync] [--force]
wp tainacan-narrativas queue [--limit=10] [--budget=300]
wp tainacan-narrativas status 123
wp tainacan-narrativas check 123
```

## REST API

Namespace `tainacan-narrativas/v1`:

| Método | Rota | Capability |
|---|---|---|
| GET | `/public/items/{id}` | pública (item legível + coleção habilitada) — `audio_url`, `duration`, `transcript`, `status`, `mode`, `playback`, `provenance` |
| GET | `/items/{id}` · `/items/{id}/script` · `/narratives` · `/stats` · `/jobs` | review |
| PUT | `/items/{id}/script` · POST `/items/{id}/approve` | review |
| GET | `/items/{id}/preview` | generate |
| POST | `/items/{id}/generate` · `/items/{id}/regenerate` · `/items/{id}/check` · `/collections/{id}/generate` · `/queue/run` | generate |
| DELETE | `/items/{id}/audio` · `/items/{id}` | manage |
| GET/POST | `/providers` · `/providers/test` · `/diagnostics` · `/diagnostics/test-write` · `/diagnostics/test-audio` · `/logs` · `/queue/clear-failed` | manage |

## Diagnóstico

A aba Diagnóstico e `GET /diagnostics` listam ambiente, serviços selecionados (endpoints reduzidos a esquema+host), fila e último job; os botões testam IA/TTS/escrita e geram um áudio de teste. Por item, "Detalhes → Fontes e saúde" mostra o que foi detectado (`✓ 12 metadados`, `✓ PDF: 8.423 caracteres`, `⚠ PDF digitalizado: texto não detectado`) e o indicador **Conteúdo para narrativa** (Insuficiente/Básico/Bom/Extenso — regra fixa, não IA).

## Limitações conhecidas

- OCR não é executado pelo plugin: PDFs digitalizados ficam em `requires_ocr`. Use o [tainacan-ocr-search](https://github.com/marcossigismundo/tainacan-ocr-search) (o texto é reaproveitado automaticamente) ou registre um `OcrProviderInterface`.
- Documentos do tipo URL não são baixados; `.wacz/.warc` são ignorados na narrativa (WACZ Player cuida da reprodução).
- A voz do navegador varia por dispositivo; para áudio idêntico para todos, configure um TTS neural.
- Sem ffmpeg, formatos diferentes de MP3/WAV não são concatenados para roteiros longos.
- Compartilhamento (QR Code, RSS/podcast) não está no MVP; a arquitetura (URLs estáveis por versão, payload público) já permite acrescentar.

## Desenvolvimento

```bash
composer install
composer lint          # phpcs (WordPress-Core/Docs/Extra + Security + PHPCompatibilityWP 8.0-)
composer test          # phpunit tests/unit
wp plugin check tainacan-narrativas   # Plugin Check (autoritativo p/ WordPress.org)
wp i18n make-pot . languages/tainacan-narrativas.pot
```

Padrões: `declare(strict_types=1)`, PSR-4 (`TainacanNarrativas\` → `includes/`), APIs Tainacan (repositórios/entidades) em vez de SQL contra o core, `$wpdb` só nas tabelas próprias com `prepare()`, escape no ponto de saída, textdomain `tainacan-narrativas`.

Licença: GPL-2.0-or-later. Dependências e licenças: [DEPENDENCIES.md](DEPENDENCIES.md).
