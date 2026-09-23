# Approval by Mail (GLPI)

Plugin GLPI que permite aprovar ou recusar **pedidos de validação** e **soluções de
chamado** por um link enviado por e-mail, **sem login**. A autorização vem inteiramente
de um token de uso único — não é preciso acessar o GLPI para decidir.

Fork modernizado (Padrão SDB) do plugin abandonado "SDB - Ação por e-mail" (GPLv3).

## Funcionalidades

- Aprovação de **validação de chamado** por link (token de uso único)
- Aprovação de **solução de chamado** por link (token de uso único)
- Página pública com **logo configurável** da empresa (PNG/JPG/SVG)
- **Motivo obrigatório** ao reprovar (válido no navegador e servidor)
- Acompanhamento de auditoria via ITILFollowup
- Notificação ao solicitante após a decisão

## Como funciona

1. Um pedido de validação ou solução é criado no GLPI.
2. O plugin gera uma **ação tokenizada** e envia um e-mail ao destinatário com um link.
3. O destinatário abre o link e vê uma **página pública** com o chamado, a mensagem do
   solicitante e os botões **Aprovar** / **Reprovar**.
4. A decisão é aplicada ao chamado, o **token é consumido** (uso único) e o
   **solicitante é notificado**.

## Requisitos

- GLPI **11.0.x**
- PHP **8.2+**
- Notificações por e-mail habilitadas no GLPI (Configurar → Notificações)

## Instalação

```bash
# copie a pasta do plugin para glpi/plugins/approvalbymail e então:
php bin/console plugin:install  -u glpi approvalbymail
php bin/console plugin:activate           approvalbymail
```

Após ativar, acesse **Configurar → Geral → Approval by Mail** para:
- Ativar/desativar os tipos de aprovação (validação, solução)
- Ativar/desativar a **descrição do chamado** na página e no e-mail de aprovação
- Configurar a **URL da logo** da empresa (exibida na página de aprovação)

## Ambiente de desenvolvimento com Docker

```bash
cp .env.example .env
make dc-up
```

| Serviço | Acesso |
|---------|--------|
| GLPI | http://localhost:8080 (`glpi` / `glpi`) |
| MailHog | http://localhost:8025 |
| MySQL | `localhost:3307` (`glpi` / `glpi`) |

O entrypoint da imagem oficial do GLPI já instala o banco automaticamente.
O plugin é instalado e ativado via console após o container subir.

## Configuração do e-mail

O modelo de notificação é um `NotificationTemplate` nativo, **editável pelo admin** em
*Configurar → Notificações → Modelos de notificação → "Approval by mail - request"*.

Tags disponíveis no corpo:

- `##approvalbymail.url##` — link da página de decisão (uso único)
- `##approvalbymail.tickettitle##` — título do chamado
- `##approvalbymail.ticketdescription##` — descrição do chamado (respeita o flag
  *Mostrar descrição do chamado*; use `##IFapprovalbymail.ticketdescription##` /
  `##ENDIFapprovalbymail.ticketdescription##` para omitir o bloco quando vazio)

### Configuração SMTP (desenvolvimento)

No GLPI, acesse *Configurar → Notificações → Configurações de e-mail*:

| Campo | Valor |
|-------|-------|
| Servidor SMTP | `mailhog` |
| Porta | `1025` |
| Modo de conexão | Sem autenticação |

### Nota sobre GLPI 11

O GLPI 11 usa Symfony para roteamento. O plugin registra a rota de aprovação
(`/plugins/approvalbymail/front/approve.php`) como **stateless** (pública, sem sessão)
via `SessionManager::registerPluginStatelessPath()`. Isso é necessário para que a
página funcione sem login.

## Logo da empresa

A página pública de aprovação pode exibir a logo da empresa:

1. Acesse **Configurar → Geral → Approval by Mail**
2. Preencha o campo **Logo URL** com uma URL pública de imagem (PNG, JPG ou SVG)
3. Salve

A logo aparece centralizada no topo do card de aprovação.

## Segurança

- Token forte de **uso único**: `bin2hex(random_bytes(32))`, comparação com `hash_equals`,
  expiração por TTL, consumido (`used_at`) após o primeiro uso válido.
- **GET nunca escreve** — protege contra pré-carregamento de link por antivírus/cliente
  de e-mail; a decisão só é aplicada no **POST** confirmado.
- **Motivo obrigatório ao reprovar** (validado no navegador e no servidor).
- Erros genéricos na página (não revelam se o token é forjado, expirado ou usado).
- CSRF do GLPI no POST; conteúdo do solicitante sanitizado antes da tela.
- Decisão aplicada em transação; criptografia via `GLPIKey`; acesso a dados via query builder.

## CI / Quality

| Workflow | Descrição |
|----------|-----------|
| **CI** | Lint PHP (8.2/8.3/8.4), PHP-CS-Fixer (PER-CS 2.0), PHPStan nível 1, PHPUnit |
| **CS Fix** | Aplica PHP-CS-Fixer automaticamente (manual via workflow_dispatch) |
| **Semantic Release** | Versionamento automático no push para `main` (baseado em conventional commits) |
| **Release Orchestrator** | Orquestra o release no push para `main` |

## Limitações conhecidas

- Textos da página e do template estão em **português**; i18n (PT/EN) planejado.
- `comment_validation` gravado como texto puro (refinamento via `Sanitizer` previsto).
- A página de aprovação não é responsiva para dispositivos muito pequenos (< 360px).

## Licença e autoria

GPLv3 — Carlos Alberto Correa Filho (IPT.br).
