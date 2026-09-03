# Dependências

Tudo o que o plugin precisa em produção é distribuído dentro do pacote. Nenhum recurso é buscado de CDN em tempo de execução (nem JS, nem CSS, nem fontes, nem modelos de voz). O usuário final não executa `composer install` nem `npm install`.

## Runtime (distribuídas em `vendor/`)

| Nome | Versão | URL | Licença | Uso |
|---|---|---|---|---|
| smalot/pdfparser | ^2.12 (travada em `composer.lock`) | https://github.com/smalot/pdfparser | LGPL-3.0-or-later | Extração da camada de texto de PDFs (`Documents\PdfExtractor`). É a mesma biblioteca que o Tainacan core usa para `document_content_index`; vendorizada para não depender do `vendor/` do core. |
| symfony/polyfill-mbstring | transitiva do pdfparser | https://github.com/symfony/polyfill-mbstring | MIT | Polyfill; inerte quando `ext-mbstring` está presente (requisito do plugin). |

Verifique os SHA-256 dos arquivos instalados com `composer show -i` / `composer.lock` (campo `dist.shasum` quando publicado) — a versão exata fica registrada no `composer.lock` commitado.

### Compatibilidade de licenças

O plugin é GPL-2.0-or-later. A LGPL-3.0 do pdfparser é compatível com a distribuição da obra combinada sob GPL-3.0, que a cláusula "or later" do plugin permite. O Tainacan core (GPL-2.0-or-later) adota a mesma combinação.

## Extensões PHP

| Extensão | Obrigatória | Uso |
|---|---|---|
| mbstring | sim | Normalização/UTF-8, contagem de caracteres e chunking. |
| json | sim | Payloads REST, cache de fontes, providers. |
| zip | recomendada | Extratores DOCX/ODT (`ZipArchive`). Sem ela, esses formatos são reportados como `unsupported`. |
| dom / libxml | recomendada | `html_entity_decode`/strip em HTML; usado indiretamente. |
| curl | recomendada | Transporte HTTP do WordPress para IA/TTS. |

## Serviços externos opcionais (nenhum é obrigatório)

| Serviço | Papel | Como é usado | Licença do serviço |
|---|---|---|---|
| Kokoro-FastAPI | TTS neural local (pt-BR: `pf_dora`, `pm_alex`, `pm_santa`) | `TTS\OpenAICompatibleTTSProvider` → `POST {base}/audio/speech`, `GET {base}/audio/voices` | Apache-2.0 (servidor); modelo Kokoro-82M Apache-2.0 |
| Piper (piper1-gpl / rhasspy) | TTS neural local (WAV) | `TTS\PiperHttpProvider` → `POST {url}` JSON `{text,voice}` ou texto puro | GPL-3.0 (piper1-gpl) / MIT (rhasspy legado); vozes com licenças próprias |
| OpenAI / LocalAI / OpenedAI-Speech | TTS via API compatível | mesmo provider acima | conforme fornecedor |
| Ollama / LM Studio / vLLM / gateways institucionais | IA generativa local | `AI\OpenAICompatibleProvider` / `AI\OllamaProvider` → `POST {base}/chat/completions` | conforme software |
| OpenAI | IA generativa | `AI\OpenAIProvider` | termos do fornecedor |
| Google Gemini | IA generativa | `AI\GeminiProvider` (chave em header `x-goog-api-key`, nunca na URL) | termos do fornecedor |
| WordPress AI Client (WP 7.0+) | IA e TTS pelos conectores do próprio site | `AI\WordPressAIProvider`, `TTS\WordPressAITTSProvider` (`wp_ai_client_prompt()`) | parte do WordPress |
| tainacan-ocr-search | OCR já executado sobre anexos | lê o post meta `_tainacan_ocr_text` do anexo, quando existe | GPL-2.0-or-later |

## Navegador (sem dependências)

- **Web Speech API** (`speechSynthesis`) — fallback de voz executado no dispositivo do visitante, sem bytes adicionais no plugin. Vozes pt-BR vêm com Windows, macOS/iOS, Android e Chrome.
- **Media Session API** — melhoria progressiva para controles de mídia do sistema (só no modo com arquivo de áudio).

### eSpeak NG (não vendorizado — decisão)

Foi avaliado distribuir `espeakng.js`/meSpeak em `assets/vendor/`. Decisão: **não incluir na 1.0**. Motivos: os pacotes JS/WASM disponíveis estão sem manutenção (últimas versões 2017–2019), somam 2–3 MB de binário de procedência difícil de auditar, e a Web Speech API entrega voz pt-BR sem nenhum byte extra. A camada `SpeechEngine` do `assets/js/player.js` é isolada; um adaptador eSpeak NG pode ser acrescentado depois sem alterar o PHP (ver AGENTS.md → "Como adicionar um mecanismo de voz").

## Desenvolvimento (não distribuídas — `require-dev`)

squizlabs/php_codesniffer, wp-coding-standards/wpcs, phpcompatibility/phpcompatibility-wp, phpcsstandards/phpcsextra, dealerdirect/phpcodesniffer-composer-installer, phpunit/phpunit. Excluídas do pacote pelo `.gitignore`/`.distignore`.
