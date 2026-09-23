<?php

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Modelos de notificação do plugin: cria/remove Notification + Template +
 * tradução (corpo com o link tokenizado) + alvo, para DOIS eventos:
 *   - approvalrequest  (validação) -> validador  (users_id da ação)
 *   - solutionapproval (solução)   -> requerente (users_id da ação)
 *
 * Chamado por hook.php no install()/uninstall(). Idempotente: limpa antes de criar.
 * Padrão SDB-5/SDB-11/SDB-17.
 */
class PluginApprovalbymailNotification
{
    public const ITEMTYPE = 'PluginApprovalbymailAction';

    // Mantidos por compatibilidade/retro (modelo de validação).
    public const EVENT         = 'approvalrequest';
    public const TEMPLATE_NAME = 'Approval by mail - request';
    public const NOTIF_NAME    = 'Approval by mail - approval request';

    /**
     * Cria os DOIS modelos de notificação. Idempotente: limpa resíduo antes.
     */
    public static function installNotificationModels(): bool
    {
        self::uninstallNotificationModels();

        // Modelo 1 — pedido de VALIDAÇÃO -> validador (texto preservado do RC).
        if (!self::createModel(
            PluginApprovalbymailNotificationTargetAction::EVENT_APPROVAL_REQUEST,
            self::TEMPLATE_NAME,
            self::NOTIF_NAME,
            PluginApprovalbymailNotificationTargetAction::TARGET_VALIDATOR,
            'Aprovação pendente: ##approvalbymail.tickettitle##',
            self::validationBodyHtml(),
            self::validationBodyText()
        )) {
            return false;
        }

        // Modelo 2 — SOLUÇÃO proposta -> requerente.
        if (!self::createModel(
            PluginApprovalbymailNotificationTargetAction::EVENT_SOLUTION_APPROVAL,
            'Approval by mail - solution request',
            'Approval by mail - solution approval request',
            PluginApprovalbymailNotificationTargetAction::TARGET_REQUESTER,
            'Solução aguardando sua aprovação: ##approvalbymail.tickettitle##',
            self::solutionBodyHtml(),
            self::solutionBodyText()
        )) {
            return false;
        }

        return true;
    }

    /**
     * Cria um modelo completo: Template + tradução + Notification + link + alvo.
     * Retorna false e loga o passo que falhou (SDB-17).
     */
    private static function createModel(
        string $event,
        string $tpl_name,
        string $notif_name,
        int $target_id,
        string $subject,
        string $html,
        string $text
    ): bool {
        // 1) Template
        $tpl    = new NotificationTemplate();
        $tpl_id = $tpl->add([
            'name'     => $tpl_name,
            'itemtype' => self::ITEMTYPE,
            'comment'  => 'Created by approvalbymail',
            'css'      => '',
        ]);
        if (!$tpl_id) {
            Toolbox::logInFile('approvalbymail', sprintf("op=createModel event=%s step=template result=fail\n", $event));
            return false;
        }

        // 2) Tradução padrão (language='' cobre todos os idiomas)
        $trans = new NotificationTemplateTranslation();
        if (!$trans->add([
            'notificationtemplates_id' => $tpl_id,
            'language'                 => '',
            'subject'                  => $subject,
            'content_text'             => $text,
            'content_html'             => $html,
        ])) {
            Toolbox::logInFile('approvalbymail', sprintf("op=createModel event=%s step=translation result=fail\n", $event));
            return false;
        }

        // 3) Notification
        $notif    = new Notification();
        $notif_id = $notif->add([
            'name'         => $notif_name,
            'entities_id'  => 0,
            'itemtype'     => self::ITEMTYPE,
            'event'        => $event,
            'is_active'    => 1,
            'is_recursive' => 1,
            'comment'      => '',
        ]);
        if (!$notif_id) {
            Toolbox::logInFile('approvalbymail', sprintf("op=createModel event=%s step=notification result=fail\n", $event));
            return false;
        }

        // 4) Liga Notification <-> Template no modo e-mail
        $link = new Notification_NotificationTemplate();
        if (!$link->add([
            'notifications_id'         => $notif_id,
            'notificationtemplates_id' => $tpl_id,
            'mode'                     => 'mailing',
        ])) {
            Toolbox::logInFile('approvalbymail', sprintf("op=createModel event=%s step=link result=fail\n", $event));
            return false;
        }

        // 5) Alvo (resolvido em addSpecificTargets pela classe de alvo)
        $target = new NotificationTarget();
        if (!$target->add([
            'notifications_id' => $notif_id,
            'items_id'         => $target_id,
            'type'             => Notification::USER_TYPE,
        ])) {
            Toolbox::logInFile('approvalbymail', sprintf("op=createModel event=%s step=target result=fail\n", $event));
            return false;
        }

        Toolbox::logInFile('approvalbymail', sprintf(
            "op=createModel event=%s tpl_id=%d notif_id=%d target=%d result=ok\n",
            $event,
            $tpl_id,
            $notif_id,
            $target_id
        ));
        return true;
    }

    /**
     * Remove tudo que o install criou (AMBOS os eventos).
     * Discriminador: itemtype = PluginApprovalbymailAction (sem filtrar por evento).
     */
    public static function uninstallNotificationModels(): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $notif_ids = [];
        foreach ($DB->request([
            'SELECT' => 'id',
            'FROM'   => Notification::getTable(),
            'WHERE'  => ['itemtype' => self::ITEMTYPE],
        ]) as $row) {
            $notif_ids[] = (int) $row['id'];
        }
        foreach ($notif_ids as $nid) {
            $DB->delete(Notification_NotificationTemplate::getTable(), ['notifications_id' => $nid]);
            $DB->delete(NotificationTarget::getTable(), ['notifications_id' => $nid]);
            $DB->delete(Notification::getTable(), ['id' => $nid]);
        }

        $tpl_ids = [];
        foreach ($DB->request([
            'SELECT' => 'id',
            'FROM'   => NotificationTemplate::getTable(),
            'WHERE'  => ['itemtype' => self::ITEMTYPE],
        ]) as $row) {
            $tpl_ids[] = (int) $row['id'];
        }
        foreach ($tpl_ids as $tid) {
            $DB->delete(NotificationTemplateTranslation::getTable(), ['notificationtemplates_id' => $tid]);
            $DB->delete(NotificationTemplate::getTable(), ['id' => $tid]);
        }

        Toolbox::logInFile(
            'approvalbymail',
            sprintf("op=uninstallNotificationModels notifs=%d tpls=%d result=ok\n", count($notif_ids), count($tpl_ids))
        );
        return true;
    }

    // ---------------- Corpos: VALIDAÇÃO (preservados do RC) ----------------
    private static function validationBodyHtml(): string
    {
        return implode("\n", [
            '<p>Olá,</p>',
            '<p>Há um pedido de validação aguardando sua decisão sobre o chamado: '
                . '<strong>##approvalbymail.tickettitle##</strong>.</p>',
            '##IFapprovalbymail.ticketdescription##',
            '<p><strong>Descrição do chamado</strong></p>',
            '<div>##approvalbymail.ticketdescription##</div>',
            '##ENDIFapprovalbymail.ticketdescription##',
            '<p>Você pode aprovar ou recusar diretamente pelo link abaixo, sem precisar fazer login:</p>',
            '<p><a href="##approvalbymail.url##">Abrir aprovação</a></p>',
            '<p style="color:#666;font-size:0.9rem">Este link é de uso único e expira em alguns dias.</p>',
        ]);
    }

    private static function validationBodyText(): string
    {
        return implode("\n", [
            'Olá,',
            '',
            'Há um pedido de validação aguardando sua decisão sobre o chamado: ##approvalbymail.tickettitle##.',
            '',
            '##IFapprovalbymail.ticketdescription##',
            'Descrição do chamado:',
            '##approvalbymail.ticketdescription##',
            '##ENDIFapprovalbymail.ticketdescription##',
            '',
            'Aprove ou recuse diretamente pelo link abaixo, sem precisar fazer login:',
            '',
            '##approvalbymail.url##',
            '',
            'Este link é de uso único e expira em alguns dias.',
        ]);
    }

    // ---------------- Corpos: SOLUÇÃO (novo) ----------------
    private static function solutionBodyHtml(): string
    {
        return implode("\n", [
            '<p>Olá,</p>',
            '<p>Uma solução foi proposta para o seu chamado: '
                . '<strong>##approvalbymail.tickettitle##</strong>.</p>',
            '##IFapprovalbymail.ticketdescription##',
            '<p><strong>Descrição do chamado</strong></p>',
            '<div>##approvalbymail.ticketdescription##</div>',
            '##ENDIFapprovalbymail.ticketdescription##',
            '<p>Você pode <strong>aprovar</strong> (o chamado é encerrado) ou <strong>recusar</strong> '
                . '(o chamado é reaberto para novo atendimento) diretamente pelo link abaixo, sem precisar fazer login:</p>',
            '<p><a href="##approvalbymail.url##">Abrir aprovação da solução</a></p>',
            '<p style="color:#666;font-size:0.9rem">Ao recusar, será necessário informar o motivo. '
                . 'Este link é de uso único e expira em alguns dias.</p>',
        ]);
    }

    private static function solutionBodyText(): string
    {
        return implode("\n", [
            'Olá,',
            '',
            'Uma solução foi proposta para o seu chamado: ##approvalbymail.tickettitle##.',
            '',
            '##IFapprovalbymail.ticketdescription##',
            'Descrição do chamado:',
            '##approvalbymail.ticketdescription##',
            '##ENDIFapprovalbymail.ticketdescription##',
            '',
            'Você pode aprovar (o chamado é encerrado) ou recusar (o chamado é reaberto para novo',
            'atendimento) diretamente pelo link abaixo, sem precisar fazer login:',
            '',
            '##approvalbymail.url##',
            '',
            'Ao recusar, será necessário informar o motivo. Este link é de uso único e expira em alguns dias.',
        ]);
    }
}
