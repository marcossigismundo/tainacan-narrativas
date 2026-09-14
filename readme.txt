=== Tainacan Narrativas ===
Contributors: marcossigismundo
Tags: tainacan, audio, acessibilidade, narrativa, text-to-speech
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Transforma itens Tainacan em experiências narrativas em áudio: leitura documental, narrativa opcional assistida por IA e síntese de voz, com player acessível.

== Description ==

O **Tainacan Narrativas** cria uma camada de mediação e acessibilidade sobre acervos Tainacan. Para cada item ele:

1. coleta título, descrição, metadados públicos e o texto do documento principal e dos anexos (PDF, DOCX, ODT, TXT, HTML…);
2. monta um roteiro documental fiel (sem IA) ou, opcionalmente, uma narrativa mais natural gerada por IA **exclusivamente a partir dessas fontes**;
3. sintetiza a voz (TTS neural local/institucional, API compatível com OpenAI, WordPress AI ou a voz do navegador do visitante);
4. armazena roteiro e áudio e exibe um player acessível na página pública do item, com transcript e nota de proveniência;
5. detecta quando o item ou seus documentos mudam e marca a narrativa como desatualizada.

Princípios: **gerar → armazenar → servir** (nunca IA/TTS a cada visita), fidelidade documental (a IA não inventa datas, pessoas, lugares nem acontecimentos), privacidade (só metadados públicos; envio a IA externa e de anexos configurável por coleção), zero CDN e revisão humana opcional antes do áudio.

Funciona sem nenhuma chave de IA: o roteiro por template + a voz do navegador cobrem qualquer hospedagem.

= Requisitos =

* WordPress 6.5+, PHP 8.0+ (extensões mbstring e json; zip recomendada para DOCX/ODT).
* Plugin Tainacan 1.0+ ativo.

= Integrações =

* Tema Tainacan Interface / tainacan-theme (`tainacan-interface-single-item-after-attachments`), conteúdo padrão do Tainacan e `the_content` como fallbacks; shortcode `[tainacan_narrativa]` e bloco `tainacan-narrativas/player`.
* Reutiliza o texto indexado pelo Tainacan (`document_content_index`) e o OCR do plugin tainacan-ocr-search quando existirem.
* Reconhece itens importados pelo Tainacan DIP Importer e ignora capturas web (.wacz/.warc) reproduzidas pelo Tainacan WACZ Player.

== Installation ==

1. Envie a pasta `tainacan-narrativas` para `/wp-content/plugins/` (ou instale o ZIP).
2. Ative o plugin. Você será levado a **Tainacan → Narrativas** para a configuração inicial (coleções, modo, voz, IA opcional, item de teste). É possível pular.
3. Habilite as coleções desejadas em **Coleções**, gere as narrativas (por item ou em lote) e revise/aprove os roteiros quando o fluxo editorial estiver ativo.

Não é necessário `composer install`, `npm install`, build, CDN ou SSH.

== Frequently Asked Questions ==

= Preciso de uma chave de IA? =
Não. Sem IA o plugin monta um roteiro documental por template. A IA (Ollama, LM Studio, OpenAI, Gemini, WordPress AI…) é opcional e pode ser desativada por coleção.

= O áudio é gerado a cada acesso? =
Não. Roteiro e áudio são gerados por uma fila (WP-Cron ou botão "Executar fila"), armazenados e apenas reproduzidos na página. Com a voz do navegador, o roteiro armazenado é lido pelo dispositivo do visitante.

= Como funciona a detecção de alterações? =
Um hash SHA-256 das fontes e da configuração é guardado com cada narrativa. Ao salvar o item, metadados, documento ou anexos, uma verificação recalcula o hash e marca a narrativa como desatualizada (ou coloca a regeneração na fila, conforme a configuração).

= O que é enviado à IA externa? =
Somente título, descrição, nome da coleção, metadados públicos e o texto dos documentos autorizados, delimitados como fontes não confiáveis. Nunca dados administrativos, usuários, e-mails, logs ou tokens. A tela "Visualizar fontes" mostra exatamente o que entra.

= Onde ficam as chaves de API? =
No banco (mascaradas no painel, nunca enviadas ao navegador) ou, preferencialmente, em `wp-config.php` via `TN_AI_API_KEY`, `TN_GEMINI_API_KEY` e `TN_TTS_API_KEY`.

== Changelog ==

= 1.1.1 =
* CSS do admin e do player reescritos com escala tipográfica e de espaçamento consistente (fim dos textos ora grandes demais, ora pequenos demais).
* Cards do painel redesenhados: ícone circular colorido por status, hierarquia clara entre número e rótulo, hover com elevação, modificador "info" que faltava (cards "Pendentes" e "Tempo total de áudio" agora têm cor própria).
* Tabelas: cabeçalhos de coluna diferenciados dos rótulos de linha (a tabela de Diagnóstico não fica mais toda em maiúsculas).

= 1.1.0 =
* Voz do navegador: escolha automática da melhor voz (neural/natural, timbre feminino suave por padrão, configurável), texto preparado para a fala (datas, siglas, abreviações, números, romanos, moeda) e frases enviadas em trechos curtos — sem cortes nem sílabas perdidas no Chrome; player compatível com Safari antigo; clique na frase do texto para ouvir dali.
* Narrativa por IA: leitura prévia do documento inteiro (dossiê) antes da escrita, prompts reescritos para prosa narrativa rica e sem estrutura de texto automático, limpeza determinística de fórmulas de IA, orçamento de tokens proporcional ao tamanho da narração; respostas malformadas são reprocessadas em vez de cair para o template.
* Roteiro sem IA: sumarizador extrativo (mantém as frases com nomes, datas, lugares, números e citações, na ordem) em vez de cortar o documento após N palavras; metadados lidos como frases naturais.
* Cobertura automática: varredura horária enfileira itens sem narrativa, desatualizados ou com erro; botão "Gerar todas as narrativas pendentes" e "Aprovar todas em revisão"; painel de cobertura por coleção. Fluxo padrão passa a ser automático (roteiro → áudio), com regeneração na fila ao salvar itens.

= 1.0.0 =
* Primeira versão: coleta documental, extração de texto, roteiro por template e por IA, TTS (navegador, API compatível com OpenAI/Kokoro, Piper, WordPress AI), fila com detecção de alterações, player acessível, administração integrada ao Tainacan, REST, WP-CLI.
