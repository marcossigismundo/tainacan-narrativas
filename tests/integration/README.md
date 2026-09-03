# Testes de integração (ambiente WordPress real)

Os testes unitários (`tests/unit`) rodam sem WordPress. Os cenários abaixo exigem
uma instalação com Tainacan ativo e foram executados via WP-CLI no ambiente de
desenvolvimento (WordPress 7.1, Tainacan 1.1.0, PHP 8.0) antes da 1.0.0.

```bash
# ativação + smoke
wp plugin activate tainacan-narrativas
wp eval 'echo (int) \TainacanNarrativas\Database\Tables::exist();'

# habilitar coleções e o plugin sem passar pelo wizard
wp eval '\TainacanNarrativas\Core\Options::update(["enabled"=>1,"setup_done"=>1,"editorial_flow"=>"auto"]);
\TainacanNarrativas\Tainacan\CollectionSettings::save(7, ["enabled"=>1]);'

# caso 1 — item só com metadados / caso 2 — item com PDF textual
wp tainacan-narrativas generate 33511 --sync --force
wp tainacan-narrativas status 33511

# caso 4 — PDF sem texto suficiente → insufficient / requires_ocr
wp tainacan-narrativas generate 366408 --sync --force

# caso 8 — item alterado após geração → stale → regeneração
wp post meta update 33511 12 "Autor alterado"
wp tainacan-narrativas check 33511          # status=stale
wp tainacan-narrativas generate 33511 --sync

# fila (caso 5/6/7/12 dependem de IA/TTS configurados)
wp tainacan-narrativas generate --collection=7
wp tainacan-narrativas queue --limit=20

# REST público (anônimo) e player na página
curl -s "$(wp option get siteurl)/wp-json/tainacan-narrativas/v1/public/items/33511" | head -c 400
curl -s "$(wp post url 33511)" | grep -c 'data-tn-player'
```

Cenários de segurança cobertos por testes unitários: SSRF (`SecurityTest`),
redação de segredos (`Logger`), sanitização de configurações e de coleção
(`SettingsSanitizerTest`), nomes de prompt (`PromptLoader`) e delimitadores
neutralizados nas fontes (`ScriptBuilderTest`). Capabilities e
`permission_callback` são verificados manualmente com `rest_do_request()` como
usuário anônimo/sem capability (ver AGENTS.md → "Checklist de verificação").
