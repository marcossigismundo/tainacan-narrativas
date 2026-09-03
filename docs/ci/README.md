# CI (GitHub Actions)

`github-ci.yml` roda, em PHP 8.0/8.2/8.3: `php -l`, phpcs (ruleset estrito) e
PHPUnit; e, em job separado, o **WordPress Plugin Check** oficial.

O arquivo fica aqui (e não em `.github/workflows/`) porque o token OAuth usado
no primeiro push não tinha o escopo `workflow`, que o GitHub exige para criar
ou alterar workflows. Para ativar:

```bash
gh auth refresh -h github.com -s workflow     # uma vez, no seu terminal
mkdir -p .github/workflows
git mv docs/ci/github-ci.yml .github/workflows/ci.yml
git commit -m "ci: enable GitHub Actions workflow"
git push
```
