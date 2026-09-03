# AGENTS.md — notas para agentes e mantenedores

Contexto para futuras sessões de Claude Code (e humanos) trabalhando neste
plugin. Leia antes de alterar qualquer coisa. O README descreve o produto; este
arquivo descreve **decisões, invariantes e como estender**.

## Identidade

- Nome: **Tainacan Narrativas**. Slug/pasta/textdomain: `tainacan-narrativas`.
  Namespace PHP `TainacanNarrativas\` (PSR-4 → `includes/`). Prefixos `TN_`
  (constantes) e `tn_` (options, hooks de cron, handles, tabelas
  `wp_tn_narratives` / `wp_tn_jobs`). REST `tainacan-narrativas/v1`.
- Destino: Memorial Digital da Pandemia (`memorialdigitalcovid19.org.br`, tema
  Tainacan Interface/child). Ecossistema: tainacan-dip-importer,
  tainacan-metadata-crowdsource (Colab), tainacan-wacz-player,
  tainacan-ocr-search. **Nenhum é modificado nem é dependência.**
- Licença GPL-2.0-or-later. Requer WP 6.5+, PHP 8.0+, Tainacan 1.0+ (analisado
  com Tainacan 1.1.0 e WordPress 7.1).

## Ambiente de desenvolvimento usado

- Código em `C:\xampp-tainacan\htdocs\wordpress\wp-content\plugins\tainacan-narrativas`
  (WordPress 7.1 local, Tainacan 1.1.0, PHP 8.0.30, tema blocksy). Testes reais
  foram feitos com WP-CLI (`wp-cli.phar` baixado ad hoc; `php wp-cli.phar
  --path=... --skip-plugins=oficinas-do-conhecimento` evita um notice de
  textdomain de outro plugin). Itens de teste: 33511 (coleção 7, PDF com
  texto), 366408 (coleção 314357, PDF quase vazio → `insufficient`).
- `composer install` (dev) traz phpcs/phpunit; `vendor/` commitado contém só
  as dependências de runtime (dev deps excluídas pelo `.gitignore`).
  **Antes de commitar mudanças em `vendor/`, rode `composer install --no-dev -o`**
  para que `vendor/composer/autoload_*.php` não referencie pacotes de dev
  (senão o ZIP de produção fatal-iza no `autoload_files.php`). Depois volte
  com `composer install` para os utilitários de dev — o diff em
  `vendor/composer/` que isso gera não deve ser commitado.
- Comandos: `composer lint` (0 erros/0 avisos esperados), `cd tests &&
  ../vendor/bin/phpunit -c phpunit.xml.dist` (rodar a partir de `tests/` no
  Windows), `php -l` em cada arquivo alterado, `wp i18n make-pot .
  languages/tainacan-narrativas.pot`.
- Armadilha do harness: a ferramenta Bash trunca comandos > ~8 KB e colapsa
  `\\` em `\`. Escreva arquivos com a ferramenta de escrita, não com heredoc.

## Arquitetura (o que não é óbvio pelo código)

```
Core\Plugin (singleton; wiring no init prio 11, só com Tainacan ativo)
 ├─ Narrative\NarrativeManager  ← orquestra tudo (run/approve/save_script/check_stale/enqueue)
 │    ├─ Tainacan\ContentCollector → Documents\ExtractorManager → extractors (+ cache em post meta do anexo)
 │    ├─ Narrative\SourceHasher / ContentScore / ScriptBuilder (template) / NarrativeGenerator (IA)
 │    ├─ AI\ProviderManager (privacy gate por coleção) · TTS\ProviderManager (fallback browser)
 │    └─ Database\NarrativeRepository / JobRepository · TTS\AudioStorage (Media Library)
 ├─ Queue\QueueManager (cron) → Queue\JobRunner → NarrativeManager
 ├─ Tainacan\ChangeListener (hooks → QueueManager::schedule_check)
 ├─ REST\Controller · Frontend\{Player,Shortcode,Block,Assets} · Admin\{AdminPage,SettingsHandler,Diagnostics}
 └─ CLI\Command
```

### Decisões registradas (Fase 0 / 96)

1. **Texto dos documentos** — o core só indexa o **documento principal PDF**
   em `document_content_index` (post meta do item, `\Tainacan\Media`) e só se a
   opção `tainacan_option_index_pdf_content`/`TAINACAN_INDEX_PDF_CONTENT`
   estiver ativa; não há endpoint REST nem indexação de anexos. Por isso
   `ExtractorManager` resolve na ordem: `_tainacan_ocr_text` do anexo
   (tainacan-ocr-search) → `document_content_index` (só para o documento
   principal, com o mesmo teste de "texto legível") → extratores próprios →
   `OcrProviderInterface` registrados. A lib PDF é a mesma do core
   (smalot/pdfparser), vendorizada.
2. **PDF digitalizado** — `PdfExtractor` retorna `requires_ocr` quando há
   páginas mas < 40 letras/página ou razão de letras < 0,35 (texto ilegível).
   `NarrativeManager` transforma isso no status `requires_ocr` quando não há
   outro conteúdo suficiente. OCR não é executado pelo plugin.
3. **TTS básico = Web Speech API** (`TTS\BrowserProvider` + `SpeechEngine` em
   `player.js`), não eSpeak-NG wasm (pacotes sem manutenção, blob de 2–3 MB).
   TTS neural = HTTP para Kokoro-FastAPI/LocalAI/OpenAI (`openai_compatible`),
   Piper (`piper_http`, WAV) e WordPress AI Client (`wp_ai`).
4. **IA** — interface própria; `openai_compatible` é o genérico (Ollama, LM
   Studio, vLLM…); `wp_ai` usa `wp_ai_client_prompt()` (WP 7.0+) e os
   conectores do site — sem chave no plugin. Nenhum SDK de fornecedor.
5. **Fila própria em WP-Cron** (sem Action Scheduler): tabela `wp_tn_jobs`,
   claim atômico por `UPDATE … LIMIT` + token, lock global e por item via
   `Core\Lock` (`add_option`, padrão do `WP_Upgrader`), backoff 1/3/9 min.
6. **Tabelas próprias** (exceção legítima ao `$wpdb`): narrativas têm versões,
   máquina de estados, proveniência e campos editoriais; jobs precisam de
   claim atômico. Tudo com `prepare()` e `phpcs:ignore` justificado; nenhum
   SQL toca tabelas do Tainacan (só Repositories/Entities).
7. **Áudio na Media Library com `post_parent = 0`** — se fosse filho do item,
   `Item::get_attachments()` o listaria como anexo e a narrativa "se
   alimentaria" do próprio áudio. Marcado com `_tn_generated_audio=1`;
   `AudioStorage::delete()` só apaga anexos com essa marca.
8. **Somente metadados públicos** entram no corpus (privados contados em
   `health.skipped`). Core_Title/Core_Description são campos dedicados.
9. **Injeção do player** copia o tainacan-wacz-player: action do tema
   `tainacan-interface-single-item-after-attachments` → filtro
   `tainacan_single_item_content` → `the_content`, com guarda por item.
10. **Chaves**: constantes `TN_AI_API_KEY`, `TN_GEMINI_API_KEY`, `TN_TTS_API_KEY`
    vencem a option e bloqueiam o campo. Formulários: vazio mantém, `__clear__`
    apaga. `Logger::redact*()` remove segredos de tudo que é logado.
11. **SSRF**: `Security::validate_endpoint()` + `wp_safe_remote_request()`;
    redes privadas só com `allow_private_endpoints` (manage) e `redirection=0`.
12. **Prompt injection**: fontes entre `<<<SOURCE id>>>…<<<END_SOURCE>>>`;
    delimitadores que apareçam dentro do texto são neutralizados
    (`ScriptBuilder::source()`); o system prompt manda ignorar instruções nas
    fontes; `NarrativeGenerator::post_process()` remove delimitadores vazados e
    markdown.

## Invariantes (não regredir)

- **Nunca gerar na requisição pública.** `Player`/REST público só leem
  `NarrativeRepository::get_current()`; `public_payload()` só serve `ready` e
  `stale`.
- **Edição humana nunca é sobrescrita**: `save_script()` grava em
  `edited_script`; `final_script()` prefere a edição; uma regeneração de item
  cuja versão atual tem `edited_script` volta para `review` mesmo no fluxo
  automático (`NarrativeManager::run`, `$had_human_edit`).
- **Hash**: `SourceHasher::source_hash()` inclui modo, provedor de IA,
  template e limite de caracteres; mudar só a voz não invalida o roteiro
  (`audio_hash` separado → só o áudio é refeito). Bumpe
  `SourceHasher::PROMPT_VERSION` ao mudar prompts de forma que deva invalidar
  roteiros, e `ExtractorManager::EXTRACTOR_VERSION` ao mudar extratores.
- **CollectionSettings::sanitize_entry()**: formulários HTML enviam
  `tnc[__form]=1` → checkbox ausente = 0; sem `__form` (wizard/CLI/filtros)
  chaves ausentes mantêm os defaults. Não remova o hidden input do
  `tab-collections.php`.
- **Coleção ausente do mapa = desabilitada** (opt-in); `Options::enabled` é o
  interruptor geral. Instalação nova não chama nada externo.
- **Escape no ponto de saída** dentro de `includes/Admin/views/*` e
  `includes/Frontend/views/player.php`; JS só usa `textContent` para dados.
- Capabilities próprias (`Core\Capabilities`); menu admin exige
  `review_tainacan_narratives`; REST público nunca usa `__return_true`.
- `Deactivator` limpa cron e lock; `uninstall.php` só apaga dados se
  `delete_on_uninstall` estiver marcado.

## Máquina de estados (`wp_tn_narratives.status`)

`queued → scripting → (review) → synthesizing → ready`; `stale` quando o hash
muda; `error` com `last_error`; `requires_ocr` / `insufficient` são terminais
até nova geração. Jobs: `queued → running → done|failed` (payload
`action=generate|audio|check`).

## Como adicionar…

### …um provedor de IA
1. Classe em `includes/AI/` implementando `AIProviderInterface` (ou estendendo
   `OpenAICompatibleProvider` quando a API é compatível — ver `OllamaProvider`).
   Use `AbstractHttpProvider::post_json/get_json` (passam pela política SSRF).
   `is_external()` alimenta o privacy gate por coleção.
2. Registre em `AI\ProviderManager::all()` ou via filtro
   `tainacan_narrativas_ai_providers`.
3. Se precisar de opções/segredo: `Options::defaults()`,
   `Options::SECRET_CONSTANTS`, `SettingsHandler::sanitize_settings()` (enums,
   ranges, urls) e o pane `data-tn-provider-pane="ai:<id>"` em
   `views/tab-ai.php`.

### …um mecanismo de voz (TTS)
1. Classe em `includes/TTS/` implementando `TTSProviderInterface`. Devolva
   `array(audio,mime,extension,voice)`; para textos longos use
   `Chunker::chunk()` + `AudioConcat::join()` (MP3/WAV).
2. Registre em `TTS\ProviderManager::all()` ou filtro
   `tainacan_narrativas_tts_providers`; pane `tts:<id>` em `tab-voice.php`.
3. Um mecanismo **no navegador** não precisa de PHP: crie outro engine em
   `assets/js/player.js` com a mesma interface de `SpeechEngine`
   (`play/pause/seek/skip/setRate/setVolume/currentTime/duration/on`) e
   selecione-o em `init()` conforme `cfg.playback`.

### …um extrator de documento
1. Classe implementando `Documents\ExtractorInterface` (`id`, `supports`,
   `extract`). Retorne `ExtractionResult::ok()`/`fail()` com status semântico.
2. Registre via filtro `tainacan_narrativas_extractors` (a ordem importa) e
   bumpe `ExtractorManager::EXTRACTOR_VERSION`.

### …um provedor de OCR
Implemente `Documents\OcrProviderInterface` e registre em
`tainacan_narrativas_ocr_providers`; é chamado quando o resultado é
`requires_ocr`. Alternativa sem código: rodar o tainacan-ocr-search, cujo
`_tainacan_ocr_text` é lido automaticamente.

### …um modo narrativo
1. Adicione a definição em `Narrative\Modes::all()` (label, description,
   target_words, ai) ou via filtro `tainacan_narrativas_modes`.
2. Crie `prompts/<id>.php` retornando string com os placeholders
   `{target_words} {title} {collection} {language} {sources}` (nomes de arquivo
   em minúsculas com hífen). Sem IA, o `ScriptBuilder` aplica o `target_words`.

## APIs Tainacan usadas (confirmadas no código 1.1.0)

`\Tainacan\Repositories\Items::fetch($id)`, `Collections::fetch($id|args,
'OBJECT')`, `Metadata::fetch_by_collection($collection, ['post_status'=>…])`,
`Entities\Item::{get_title,get_description,get_document,get_document_type,
get_attachments,get_collection,get_collection_id,get_status,can_read,
get_edit_url}`, `Entities\Metadatum::{get_id,get_name,get_status,
get_metadata_type}`, `Entities\Item_Metadata_Entity::{has_value,
get_value_as_string}`, `Collection::{get_db_identifier,get_name}`,
`\Tainacan\Media::$content_index_meta`, `\Tainacan\Pages` +
`Traits\Singleton_Instance`, actions `tainacan-insert`,
`tainacan-api-item-updated`, `tainacan-interface-single-item-after-attachments`
(tema), filtro `tainacan_single_item_content`. Post types de item:
`tnc_col_{id}_item`; metadado de item = post meta com `meta_key` = ID do
metadado.

## CI

O workflow do GitHub Actions (phpcs + phpunit em PHP 8.0/8.2/8.3 + Plugin
Check) está em `docs/ci/github-ci.yml` porque o token do primeiro push não
tinha o escopo `workflow`. Ative com `gh auth refresh -s workflow` e
`git mv docs/ci/github-ci.yml .github/workflows/ci.yml` (ver `docs/ci/README.md`).

## Checklist de verificação antes de release

- `composer lint` limpo; `phpunit` verde; `php -l` em tudo; `wp plugin check`.
- `wp plugin activate` cria tabelas, caps, cron; wizard abre.
- Item com PDF: `generate --sync` → `ready`; `check` após alterar metadado →
  `stale`; `generate` → nova versão; `public/items/{id}` anônimo 200; rotas
  admin anônimas 401; página do item contém **um** `data-tn-player`.
- Renderizar as 9 abas do admin em CLI (`AdminPage::render_page_content()`
  com `$_GET['tab']`) sem fatal.
- Bump de versão: header do plugin, `TN_VERSION`, `readme.txt` (Stable tag),
  CHANGELOG. Release ZIP sem `.github`, `tests`, dev deps (ver `.distignore`).

## Pendências / próximos passos sugeridos

- Testar Kokoro-FastAPI e Piper reais (só foram testados os contratos HTTP e a
  concatenação em unit tests) e um provedor OpenAI-compatible com modelo real.
- Compartilhamento (link, QR, RSS/podcast por coleção) — fora do MVP.
- Extrator para `.wacz` (texto das páginas capturadas) — fora do MVP.
- Integração com os papéis do Tainacan na UI de roles (hoje: caps concedidas na
  ativação a `administrator` e a papéis com `manage_tainacan`).
