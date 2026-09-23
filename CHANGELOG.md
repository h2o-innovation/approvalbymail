# Changelog — approval by mail

Todas as mudanças relevantes deste plugin. Formato baseado em
[Keep a Changelog](https://keepachangelog.com/), versionamento [SemVer](https://semver.org/).

## [0.4.0-dev] - 2026-09-23

### Added
- **Descrição do chamado na aprovação:** a página pública e o e-mail de aprovação
  (validação e solução) passam a exibir a descrição do chamado (`Ticket.content`),
  com sanitização `RichText::getSafeHtml` na página e conversão automática para
  HTML seguro / texto plano no e-mail (tag `##approvalbymail.ticketdescription##`).
- Novo flag de configuração **"Mostrar descrição do chamado"** (padrão: ativo).

### Changed
- Versão de `0.3.0-dev` para `0.4.0-dev`; migração idempotente de configuração e
  recriação dos modelos de notificação ao atualizar a versão.

## [0.1.0-rc] - 2026-06-09

Primeiro release candidate: ciclo de validação completo e fechado nos dois sentidos.

### Added
- **Notificação ao solicitante:** ao aplicar a decisão, dispara a notificação nativa
  `validation_answer`, avisando quem pediu a validação (fecha o ciclo de comunicação).

### Changed
- Versão de `0.0.1-alpha` para `0.1.0-rc`.

### Tested
- Caminhos de **Aprovar** (status ACCEPTED) e **Reprovar** (status REFUSED) validados
  ponta a ponta em homologação, incluindo single-use do token e recálculo do
  `global_validation` do chamado.

### Deferred (pré-1.0)
- i18n (PT/EN) dos textos da página e do template.
- `comment_validation` via `Sanitizer`.

## [0.0.1-alpha] - 2026-06-09

Primeiro ciclo funcional completo: do pedido de validação à decisão aplicada,
via link tokenizado por e-mail, sem login. Fork modernizado (Padrão SDB) do
plugin abandonado "SDB - Ação por e-mail" (GPLv3).

### Added
- **Fundação (S0):** estrutura do plugin, tabelas `config` (feature flags) e
  `actions` (ações tokenizadas), página de configuração em *Configurar > Geral*,
  e pipeline `staging → deploy` (Makefile + minificação + compilação de locales).
- **Token (S1a):** núcleo da ação tokenizada — geração com
  `bin2hex(random_bytes(32))`, expiração (TTL), e `resolve()` com validação
  server-side completa.
- **Gatilho (S1b):** hook `item_add` em `TicketValidation` gera a ação
  tokenizada para o validador designado.
- **Notificação (S2):** modelo de notificação nativo do GLPI
  (`Notification` + `NotificationTemplate` + tradução + alvo do validador) e
  classe de alvo `PluginApprovalbymailNotificationTargetAction` (evento
  `approvalrequest`, tag `##approvalbymail.url##` com o link). Disparo via
  `NotificationEvent::raiseEvent` na criação da validação. O modelo é editável
  pelo admin na UI do GLPI.
- **Página de decisão (S3):** `front/action.php` pública (sem login) — resolve o
  token, exibe o chamado e a mensagem do solicitante, e aplica Aprovar/Reprovar
  no `TicketValidation`, recalculando o `global_validation` do chamado.
- **Logging estruturado (SDB-17):** rastro `chave=valor` (`op=`, `result=`,
  `elapsed`/ids) em todo o caminho — criação, notificação, decisão.

### Security
- Token forte de uso único: `random_bytes(32)`, comparação com `hash_equals`,
  expiração por TTL e consumo (`used_at`) após o primeiro uso válido (SDB-3/4).
- Página pública segura: **GET nunca escreve** (protege contra pré-carregamento
  de link por antivírus/cliente de e-mail); a decisão só é aplicada no **POST**.
- **Motivo obrigatório ao reprovar** (validado no navegador e no servidor).
- Erros genéricos na página (não revelam se o token é forjado, expirado ou usado).
- CSRF do GLPI no POST; conteúdo do solicitante sanitizado via
  `RichText::getSafeHtml` antes de ir à tela (SDB-6).
- Aplicação da decisão em transação (validação + chamado + consumo do token).
- Criptografia via `GLPIKey` (sem cripto caseira) e acesso a dados via query
  builder do GLPI (SDB-1/9).

### Infrastructure
- `deploy.sh` endurecido: guarda de caminho que aborta o `rsync --delete` se o
  alvo não for `.../plugins/approvalbymail`.
- PHP CLI do servidor de homologação alinhado ao 8.2 (faixa do GLPI 10.0.17).

### Known limitations / next (S4)
- Notificação nativa de "resposta de validação" ao solicitante ainda não disparada.
- Textos da página e do template fixos em PT (i18n PT/EN pendente).
- `comment_validation` gravado como texto puro (passar por `Sanitizer`).
