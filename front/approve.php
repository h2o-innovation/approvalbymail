<?php

/**
 * Página pública (sem login) de aprovação/reprovação por link tokenizado.
 *
 * Princípios (Padrão SDB-4): a autorização vem inteiramente do token; o GET
 * NUNCA escreve; a escrita só ocorre no POST confirmado; erros são genéricos.
 *
 * Despacho por itemtype da ação:
 *   - TicketValidation -> abm_handle_validation() (fluxo provado, intacto)
 *   - ITILSolution     -> abm_handle_solution()   (S3c)
 *
 * S3c (solução): aprovar => solução ACCEPTED + chamado ENCERRADO (D3);
 * recusar (motivo obrigatório) => solução REFUSED + reabertura (D2: status
 * ATRIBUÍDO, remove o técnico que propôs, mantém o grupo, solvedate=NULL) +
 * acompanhamento público com o motivo. Em ambos: stamp de auditoria (D5) e
 * consumo do token (single-use), tudo em transação.
 */

include('../../../inc/includes.php');

// Página pública: deliberadamente SEM Session::checkLoginUser().

/**
 * Renderiza uma página HTML mínima e autossuficiente, depois encerra.
 */
function abm_html_page(string $title, string $bodyHtml): void
{
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

    // Carrega a logo configurada (se houver)
    $logo_url = '';
    $config = new PluginApprovalbymailConfig();
    if ($config->getFromDB(PluginApprovalbymailConfig::LOGO)) {
        $logo_url = trim((string) ($config->fields['content'] ?? ''));
    }

    echo "<!DOCTYPE html>\n<html lang=\"pt-BR\"><head>";
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo "<title>{$t}</title>";
    echo '<style>'
        . ':root{--abm-fg:#1f2933;--abm-muted:#616e7c;--abm-line:#e4e7eb;'
        . '--abm-ok:#0b8457;--abm-no:#b42318;--abm-bg:#f7f8fa;}'
        . '*{box-sizing:border-box;}'
        . 'body{margin:0;background:var(--abm-bg);color:var(--abm-fg);'
        . "font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;line-height:1.5;}"
        . '.abm-wrap{max-width:34rem;margin:3rem auto;padding:0 1rem;}'
        . '.abm-card{background:#fff;border:1px solid var(--abm-line);border-radius:.75rem;'
        . 'padding:1.75rem;box-shadow:0 1px 3px rgba(0,0,0,.06);}'
        . '.abm-logo{text-align:center;margin-bottom:1.25rem;}'
        . '.abm-logo img{max-height:3.5rem;max-width:100%;height:auto;display:inline-block;}'
        . 'h1{font-size:1.25rem;margin:0 0 1rem;}'
        . '.abm-muted{color:var(--abm-muted);font-size:.95rem;}'
        . '.abm-ticket{background:var(--abm-bg);border:1px solid var(--abm-line);'
        . 'border-radius:.5rem;padding:.75rem 1rem;margin:1rem 0;}'
        . '.abm-submission{background:var(--abm-bg);border-left:3px solid #9aa5b1;'
        . 'padding:.6rem .9rem;border-radius:.4rem;margin:.25rem 0 .5rem;}'
        . 'label{display:block;font-weight:600;margin:1rem 0 .4rem;}'
        . 'textarea{width:100%;min-height:5rem;border:1px solid var(--abm-line);'
        . 'border-radius:.5rem;padding:.6rem;font:inherit;resize:vertical;}'
        . '.abm-err{color:var(--abm-no);font-weight:600;margin:.5rem 0 0;}'
        . '.abm-actions{display:flex;gap:.75rem;margin-top:1.25rem;flex-wrap:wrap;}'
        . 'button{flex:1 1 8rem;border:0;border-radius:.5rem;padding:.7rem 1rem;'
        . 'font:inherit;font-weight:600;color:#fff;cursor:pointer;}'
        . '.abm-approve{background:var(--abm-ok);}.abm-reject{background:var(--abm-no);}'
        . '</style></head><body><div class="abm-wrap"><div class="abm-card">';
    if ($logo_url !== '') {
        echo '<div class="abm-logo"><img src="' . htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8') . '" alt="Logo"></div>';
    }
    echo $bodyHtml;
    echo '</div><p class="abm-muted" style="text-align:center;margin-top:1rem">'
        . 'GLPI &middot; approval by mail</p></div></body></html>';
}

/** Mensagem de erro genérica (não revela expirado/usado/forjado). */
function abm_error_page(): void
{
    abm_html_page(
        'Link inválido',
        '<h1>Link inválido</h1>'
        . '<p class="abm-muted">Este link é inválido, já foi utilizado ou expirou. '
        . 'Se precisar, acesse o sistema para tratar a solicitação.</p>'
    );
}

/** Bloco comum: campo de comentário + ações + JS que exige motivo ao recusar. */
function abm_form_tail(string $reject_label, string $jserr_msg): string
{
    $b  = '<label for="comment">Comentário '
        . '<span class="abm-muted">(obrigatório ao ' . htmlspecialchars($reject_label, ENT_QUOTES, 'UTF-8') . ')</span></label>';
    $b .= '<textarea id="comment" name="comment"></textarea>';
    $b .= '<p id="abm-jserr" class="abm-err" style="display:none">'
        . htmlspecialchars($jserr_msg, ENT_QUOTES, 'UTF-8') . '</p>';
    $b .= '<div class="abm-actions">';
    $b .= '<button class="abm-approve" type="submit" name="decision" value="approve">Aprovar</button>';
    $b .= '<button class="abm-reject" type="submit" name="decision" value="reject">'
        . ($reject_label === 'recusar' ? 'Recusar' : 'Reprovar') . '</button>';
    $b .= '</div></form>';
    $b .= '<script>(function(){'
        . 'var f=document.getElementById("abm-form"),'
        . 'c=document.getElementById("comment"),e=document.getElementById("abm-jserr");'
        . 'f.addEventListener("submit",function(ev){var b=ev.submitter;'
        . 'if(b&&b.value==="reject"&&c.value.trim()===""){'
        . 'ev.preventDefault();e.style.display="block";c.focus();}});'
        . '})();</script>';
    return $b;
}

/** Abre o <form> com os hidden (csrf + hash). */
function abm_form_open(string $post_url, string $csrf, string $hash): string
{
    $b  = '<form id="abm-form" method="post" action="'
        . htmlspecialchars($post_url, ENT_QUOTES, 'UTF-8') . '">';
    $b .= '<input type="hidden" name="_glpi_csrf_token" value="'
        . htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') . '">';
    $b .= '<input type="hidden" name="hash" value="'
        . htmlspecialchars($hash, ENT_QUOTES, 'UTF-8') . '">';
    return $b;
}

/** Formulário de VALIDAÇÃO (inalterado em conteúdo; usa os helpers comuns). */
function abm_render_form(
    string $post_url,
    string $csrf,
    string $hash,
    int $ticket_id,
    string $ticket_title,
    string $submission_html,
    string $ticket_description_html = '',
    string $error = ''
): void {
    $title = $ticket_title !== '' ? $ticket_title : '(sem título)';

    $b  = '<h1>Validação de chamado</h1>';
    $b .= '<p class="abm-muted">Confirme sua decisão sobre o chamado abaixo. '
        . 'Você não precisa fazer login.</p>';
    $b .= '<div class="abm-ticket"><strong>#' . $ticket_id . '</strong> &mdash; '
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>';

    if ($ticket_description_html !== '') {
        $b .= '<label>Descrição do chamado</label>';
        $b .= '<div class="abm-submission">' . $ticket_description_html . '</div>';
    }
    if ($submission_html !== '') {
        $b .= '<label>Mensagem do solicitante</label>';
        $b .= '<div class="abm-submission">' . $submission_html . '</div>';
    }
    if ($error !== '') {
        $b .= '<p class="abm-err">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    $b .= abm_form_open($post_url, $csrf, $hash);
    $b .= abm_form_tail('reprovar', 'Para reprovar, informe o motivo.');

    abm_html_page('Validação de chamado', $b);
}

/** Formulário de SOLUÇÃO (S3c). */
function abm_render_solution_form(
    string $post_url,
    string $csrf,
    string $hash,
    int $ticket_id,
    string $ticket_title,
    string $solution_html,
    string $ticket_description_html = '',
    string $error = ''
): void {
    $title = $ticket_title !== '' ? $ticket_title : '(sem título)';

    $b  = '<h1>Aprovação de solução</h1>';
    $b .= '<p class="abm-muted">Uma solução foi proposta para o seu chamado. '
        . 'Ao <strong>aprovar</strong>, o chamado é encerrado; ao <strong>recusar</strong>, '
        . 'ele é reaberto para novo atendimento. Você não precisa fazer login.</p>';
    $b .= '<div class="abm-ticket"><strong>#' . $ticket_id . '</strong> &mdash; '
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>';

    if ($ticket_description_html !== '') {
        $b .= '<label>Descrição do chamado</label>';
        $b .= '<div class="abm-submission">' . $ticket_description_html . '</div>';
    }
    if ($solution_html !== '') {
        $b .= '<label>Solução proposta</label>';
        $b .= '<div class="abm-submission">' . $solution_html . '</div>';
    }
    if ($error !== '') {
        $b .= '<p class="abm-err">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    $b .= abm_form_open($post_url, $csrf, $hash);
    $b .= abm_form_tail('recusar', 'Para recusar a solução, informe o motivo.');

    abm_html_page('Aprovação de solução', $b);
}

/* ============================================================================
 *  HANDLER: VALIDAÇÃO  (lógica preservada do RC; apenas encapsulada)
 * ========================================================================== */
function abm_handle_validation(PluginApprovalbymailAction $action, string $hash): void
{
    /** @var \DBmysql $DB */
    /** @var array $CFG_GLPI */
    global $DB, $CFG_GLPI;

    $tv_id            = (int) ($action->fields['items_id'] ?? 0);
    $tv               = new TicketValidation();
    $tv_loaded        = $tv->getFromDB($tv_id);
    $tickets_id       = 0;
    $ticket_id        = 0;
    $ticket_title     = '';
    $submission_html  = '';
    $description_html = '';
    $tv_status        = 0;
    $show_description = PluginApprovalbymailConfig::isFeatureActive(PluginApprovalbymailConfig::SHOW_DESCRIPTION);

    if ($tv_loaded) {
        $tickets_id = (int) $tv->fields['tickets_id'];
        $tv_status  = (int) $tv->fields['status'];
        $sub        = (string) ($tv->fields['comment_submission'] ?? '');
        if ($sub !== '') {
            // SDB-6: conteúdo de usuário sempre sanitizado antes de ir para tela.
            $submission_html = \Glpi\RichText\RichText::getSafeHtml($sub);
        }
        $tkt = new Ticket();
        if ($tkt->getFromDB($tickets_id)) {
            $ticket_id    = (int) $tkt->fields['id'];
            $ticket_title = (string) $tkt->fields['name'];
            $desc         = (string) ($tkt->fields['content'] ?? '');
            if ($show_description && $desc !== '') {
                // SDB-6: descrição do chamado sanitizada antes de ir para tela.
                $description_html = \Glpi\RichText\RichText::getSafeHtml($desc);
            }
        }
    }

    $post_url = ($CFG_GLPI['root_doc'] ?? '') . '/plugins/approvalbymail/front/approve.php';
    $csrf     = Session::getNewCSRFToken();

    // =========================== POST: aplica a decisão ===========================
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $raw      = (string) ($_POST['decision'] ?? '');
        $decision = in_array($raw, ['approve', 'reject'], true) ? $raw : '';
        $comment  = trim((string) ($_POST['comment'] ?? ''));

        if ($decision === '') {
            abm_error_page();
            return;
        }

        if ($decision === 'reject' && $comment === '') {
            abm_render_form(
                $post_url,
                $csrf,
                $hash,
                $ticket_id,
                $ticket_title,
                $submission_html,
                $description_html,
                'Para reprovar, é obrigatório informar o motivo.'
            );
            return;
        }

        if (!$tv_loaded) {
            abm_error_page();
            return;
        }

        // Idempotência: se já não está aguardando, foi decidido em outro lugar.
        if ($tv_status !== (int) TicketValidation::WAITING) {
            $DB->update(
                PluginApprovalbymailAction::getTable(),
                ['used_at' => ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'))],
                ['id' => (int) $action->fields['id']]
            );
            Toolbox::logInFile(
                'approvalbymail',
                sprintf("op=action_apply action_id=%d result=already_decided\n", (int) $action->fields['id'])
            );
            abm_html_page(
                'Já validado',
                '<h1>Chamado já validado</h1>'
                . '<p>Este pedido de validação já havia sido respondido. Nenhuma alteração foi feita.</p>'
            );
            return;
        }

        $status = $decision === 'approve' ? (int) TicketValidation::ACCEPTED : (int) TicketValidation::REFUSED;
        $now    = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        $DB->beginTransaction();
        try {
            $DB->update(TicketValidation::getTable(), [
                'status'             => $status,
                'comment_validation' => $comment,
                'validation_date'    => $now,
            ], ['id' => $tv_id]);

            $ticket = new Ticket();
            if ($ticket->getFromDB($tickets_id)) {
                $global = TicketValidation::computeValidationStatus($ticket);
                $DB->update(Ticket::getTable(), ['global_validation' => $global], ['id' => $tickets_id]);
            }

            $DB->update(PluginApprovalbymailAction::getTable(), [
                'used_at' => $now,
            ], ['id' => (int) $action->fields['id']]);

            $DB->commit();
        } catch (\Throwable $e) {
            $DB->rollBack();
            Toolbox::logInFile(
                'approvalbymail',
                sprintf("op=action_apply action_id=%d result=fail_exception\n", (int) $action->fields['id'])
            );
            abm_error_page();
            return;
        }

        Toolbox::logInFile(
            'approvalbymail',
            sprintf(
                "op=action_apply action_id=%d validation_id=%d decision=%s status=%d result=ok\n",
                (int) $action->fields['id'],
                $tv_id,
                $decision,
                $status
            )
        );

        PluginApprovalbymailAudit::recordDecision(
            $tickets_id,
            (int) ($action->fields['users_id'] ?? 0),
            'Validação',
            $decision,
            (string) ($_SERVER['REMOTE_ADDR'] ?? '')
        );

        $ticket_notif = new Ticket();
        if ($ticket_notif->getFromDB($tickets_id)) {
            NotificationEvent::raiseEvent('validation_answer', $ticket_notif, ['validation_id' => $tv_id]);
            Toolbox::logInFile(
                'approvalbymail',
                sprintf("op=notify_answer validation_id=%d result=queued\n", $tv_id)
            );
        }

        $label = $decision === 'approve' ? 'aprovado' : 'reprovado';
        abm_html_page(
            'Concluído',
            '<h1>Decisão registrada</h1>'
            . '<p>O chamado #' . $ticket_id . ' foi <strong>' . $label . '</strong> com sucesso.</p>'
            . '<p class="abm-muted">Obrigado. Você já pode fechar esta página.</p>'
        );
        return;
    }

    // =============================== GET: confirmação ==============================
    Toolbox::logInFile(
        'approvalbymail',
        sprintf("op=action_get action_id=%d result=shown\n", (int) $action->fields['id'])
    );

    abm_render_form($post_url, $csrf, $hash, $ticket_id, $ticket_title, $submission_html, $description_html);
}

/* ============================================================================
 *  HANDLER: SOLUÇÃO  (S3c)
 * ========================================================================== */
function abm_handle_solution(PluginApprovalbymailAction $action, string $hash): void
{
    /** @var \DBmysql $DB */
    /** @var array $CFG_GLPI */
    global $DB, $CFG_GLPI;

    $sol_id           = (int) ($action->fields['items_id'] ?? 0);
    $sol              = new ITILSolution();
    $sol_loaded       = $sol->getFromDB($sol_id) && (string) ($sol->fields['itemtype'] ?? '') === 'Ticket';
    $tickets_id       = 0;
    $ticket_id        = 0;
    $ticket_title     = '';
    $solution_html    = '';
    $sol_status       = 0;
    $tech_id          = 0;
    $description_html = '';
    $show_description = PluginApprovalbymailConfig::isFeatureActive(PluginApprovalbymailConfig::SHOW_DESCRIPTION);

    if ($sol_loaded) {
        $tickets_id = (int) $sol->fields['items_id'];
        $sol_status = (int) $sol->fields['status'];
        $tech_id    = (int) ($sol->fields['users_id'] ?? 0);
        $content    = (string) ($sol->fields['content'] ?? '');
        if ($content !== '') {
            $solution_html = \Glpi\RichText\RichText::getSafeHtml($content);
        }
        $tkt = new Ticket();
        if ($tkt->getFromDB($tickets_id)) {
            $ticket_id    = (int) $tkt->fields['id'];
            $ticket_title = (string) $tkt->fields['name'];
            $desc         = (string) ($tkt->fields['content'] ?? '');
            if ($show_description && $desc !== '') {
                // SDB-6: descrição do chamado sanitizada antes de ir para tela.
                $description_html = \Glpi\RichText\RichText::getSafeHtml($desc);
            }
        }
    }

    $post_url = ($CFG_GLPI['root_doc'] ?? '') . '/plugins/approvalbymail/front/approve.php';
    $csrf     = Session::getNewCSRFToken();

    // =========================== POST: aplica a decisão ===========================
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $raw      = (string) ($_POST['decision'] ?? '');
        $decision = in_array($raw, ['approve', 'reject'], true) ? $raw : '';
        $comment  = trim((string) ($_POST['comment'] ?? ''));

        if ($decision === '') {
            abm_error_page();
            return;
        }

        // Recusar exige motivo (servidor, além do bloqueio no navegador).
        if ($decision === 'reject' && $comment === '') {
            abm_render_solution_form(
                $post_url,
                $csrf,
                $hash,
                $ticket_id,
                $ticket_title,
                $solution_html,
                $description_html,
                'Para recusar a solução, é obrigatório informar o motivo.'
            );
            return;
        }

        if (!$sol_loaded) {
            abm_error_page();
            return;
        }

        // Idempotência: solução só é decidível enquanto aguardando aprovação.
        if ($sol_status !== (int) CommonITILValidation::WAITING) {
            $DB->update(
                PluginApprovalbymailAction::getTable(),
                ['used_at' => ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'))],
                ['id' => (int) $action->fields['id']]
            );
            Toolbox::logInFile(
                'approvalbymail',
                sprintf("op=solution_apply action_id=%d result=already_decided\n", (int) $action->fields['id'])
            );
            abm_html_page(
                'Já avaliada',
                '<h1>Solução já avaliada</h1>'
                . '<p>Esta solução já havia sido avaliada. Nenhuma alteração foi feita.</p>'
            );
            return;
        }

        $now            = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $decider        = (int) ($action->fields['users_id'] ?? 0);
        $sol_status_new = $decision === 'approve'
            ? (int) CommonITILValidation::ACCEPTED
            : (int) CommonITILValidation::REFUSED;

        $DB->beginTransaction();
        try {
            if ($decision === 'approve') {
                // D3: solução aceita -> registra aprovação e ENCERRA o chamado.
                $DB->update(ITILSolution::getTable(), [
                    'status'            => $sol_status_new,
                    'date_approval'     => $now,
                    'users_id_approval' => $decider,
                ], ['id' => $sol_id]);

                $DB->update(Ticket::getTable(), [
                    'status'    => (int) Ticket::CLOSED, // 6
                    'closedate' => $now,
                ], ['id' => $tickets_id]);
            } else {
                // D2: solução recusada -> REABRE para 2º atendimento.
                $DB->update(ITILSolution::getTable(), [
                    'status' => $sol_status_new,
                ], ['id' => $sol_id]);

                // Reabre: ATRIBUÍDO(2) e zera a data de solução (NULL cru via QueryExpression).
                $DB->update(Ticket::getTable(), [
                    'status'    => (int) Ticket::ASSIGNED, // 2
                    'solvedate' => new \QueryExpression('NULL'),
                ], ['id' => $tickets_id]);

                // Remove o técnico que propôs a solução; mantém o grupo atribuído.
                if ($tech_id > 0) {
                    $DB->delete(Ticket_User::getTable(), [
                        'tickets_id' => $tickets_id,
                        'users_id'   => $tech_id,
                        'type'       => (int) CommonITILActor::ASSIGN, // 2
                    ]);
                }

                // Motivo visível para o próximo técnico (acompanhamento público).
                $fup = new ITILFollowup();
                $fup->add([
                    'itemtype'   => 'Ticket',
                    'items_id'   => $tickets_id,
                    'users_id'   => $decider,
                    'content'    => "Solução recusada pelo solicitante.\nMotivo: " . $comment,
                    'is_private' => 0,
                ]);
            }

            // Consome o token (single-use).
            $DB->update(PluginApprovalbymailAction::getTable(), [
                'used_at' => $now,
            ], ['id' => (int) $action->fields['id']]);

            $DB->commit();
        } catch (\Throwable $e) {
            $DB->rollBack();
            Toolbox::logInFile(
                'approvalbymail',
                sprintf("op=solution_apply action_id=%d result=fail_exception\n", (int) $action->fields['id'])
            );
            abm_error_page();
            return;
        }

        Toolbox::logInFile(
            'approvalbymail',
            sprintf(
                "op=solution_apply action_id=%d solution_id=%d decision=%s sol_status=%d ticket=%d result=ok\n",
                (int) $action->fields['id'],
                $sol_id,
                $decision,
                $sol_status_new,
                $tickets_id
            )
        );

        // Transparência (D5): stamp de auditoria atribuído ao decisor.
        PluginApprovalbymailAudit::recordDecision(
            $tickets_id,
            $decider,
            'Solução',
            $decision,
            (string) ($_SERVER['REMOTE_ADDR'] ?? '')
        );

        $label = $decision === 'approve'
            ? 'aprovada, e o chamado foi encerrado'
            : 'recusada, e o chamado foi reaberto para novo atendimento';
        abm_html_page(
            'Concluído',
            '<h1>Decisão registrada</h1>'
            . '<p>A solução do chamado #' . $ticket_id . ' foi <strong>' . $label . '</strong> com sucesso.</p>'
            . '<p class="abm-muted">Obrigado. Você já pode fechar esta página.</p>'
        );
        return;
    }

    // =============================== GET: confirmação ==============================
    Toolbox::logInFile(
        'approvalbymail',
        sprintf("op=solution_get action_id=%d result=shown\n", (int) $action->fields['id'])
    );

    abm_render_solution_form($post_url, $csrf, $hash, $ticket_id, $ticket_title, $solution_html, $description_html);
}

/* ============================================================================
 *  EXECUTÁVEL: plugin ativo -> resolve token -> despacha por itemtype
 * ========================================================================== */
$plugin = new Plugin();
if (!$plugin->isActivated('approvalbymail')) {
    abm_error_page();
    return;
}

$hash   = (string) ($_REQUEST['hash'] ?? '');
$action = $hash !== '' ? PluginApprovalbymailAction::resolve($hash) : null;

if ($action === null) {
    Toolbox::logInFile('approvalbymail', "op=action_get result=invalid_or_used\n");
    abm_error_page();
    return;
}

$itemtype = (string) ($action->fields['itemtype'] ?? '');

if ($itemtype === 'TicketValidation') {
    abm_handle_validation($action, $hash);
    return;
}
if ($itemtype === 'ITILSolution') {
    abm_handle_solution($action, $hash);
    return;
}

Toolbox::logInFile(
    'approvalbymail',
    sprintf(
        "op=dispatch action_id=%d itemtype=%s result=unknown_itemtype\n",
        (int) $action->fields['id'],
        $itemtype !== '' ? $itemtype : '-'
    )
);
abm_error_page();
return;
