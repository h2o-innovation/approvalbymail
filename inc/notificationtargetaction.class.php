<?php

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Alvo e dados das notificações por e-mail do plugin.
 *
 * Itemtype-alvo: PluginApprovalbymailAction (a ação tokenizada).
 * Dois eventos:
 *   - approvalrequest  (validação) -> destinatário = validador  (users_id da ação)
 *   - solutionapproval (solução)   -> destinatário = requerente (users_id da ação)
 *
 * Em ambos, o destinatário é o `users_id` da própria ação; muda só o rótulo
 * do alvo e o texto do modelo. Padrão SDB-5 + SDB-17.
 */
class PluginApprovalbymailNotificationTargetAction extends NotificationTarget
{
    /** Eventos próprios do plugin. */
    public const EVENT_APPROVAL_REQUEST  = 'approvalrequest';
    public const EVENT_SOLUTION_APPROVAL = 'solutionapproval';

    /** Alvos customizados (id alto para não colidir com alvos do core). */
    public const TARGET_VALIDATOR = 9001; // validação -> validador
    public const TARGET_REQUESTER = 9002; // solução   -> requerente

    public function getEvents()
    {
        return [
            self::EVENT_APPROVAL_REQUEST  => __('Approval request by mail', 'approvalbymail'),
            self::EVENT_SOLUTION_APPROVAL => __('Solution approval request by mail', 'approvalbymail'),
        ];
    }

    /**
     * Declara os alvos customizados (aparecem na config da notificação).
     */
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(
            self::TARGET_VALIDATOR,
            __('Validator of the action', 'approvalbymail'),
            Notification::USER_TYPE
        );
        $this->addTarget(
            self::TARGET_REQUESTER,
            __('Requester of the action', 'approvalbymail'),
            Notification::USER_TYPE
        );
    }

    /**
     * Resolve os alvos customizados em destinatário real.
     * Em ambos os casos o destinatário é o `users_id` da ação
     * (validador na validação; requerente na solução).
     */
    public function addSpecificTargets($data, $options)
    {
        if (
            (int) $data['type'] === Notification::USER_TYPE
            && in_array((int) $data['items_id'], [self::TARGET_VALIDATOR, self::TARGET_REQUESTER], true)
        ) {
            $this->addUserByField('users_id');
        }
    }

    /**
     * Monta os tags do template, incluindo o link tokenizado e o título do chamado.
     * O título é resolvido conforme o itemtype da ação (validação OU solução).
     */
    public function addDataForTemplate($event, $options = [])
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $action = $this->obj; // PluginApprovalbymailAction
        $hash   = method_exists($action, 'getEncryptedHash') ? (string) $action->getEncryptedHash() : '';
        $base   = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/');
        $url    = $base . '/plugins/approvalbymail/front/approve.php?hash=' . rawurlencode($hash);

        // Resolve o chamado conforme o objeto da ação.
        $itemtype   = (string) ($action->fields['itemtype'] ?? '');
        $items_id   = (int) ($action->fields['items_id'] ?? 0);
        $tickets_id = 0;

        if ($itemtype === 'TicketValidation') {
            $tv = new TicketValidation();
            if ($tv->getFromDB($items_id)) {
                $tickets_id = (int) ($tv->fields['tickets_id'] ?? 0);
            }
        } elseif ($itemtype === 'ITILSolution') {
            $sol = new ITILSolution();
            if ($sol->getFromDB($items_id) && ($sol->fields['itemtype'] ?? '') === 'Ticket') {
                $tickets_id = (int) ($sol->fields['items_id'] ?? 0);
            }
        }

        $title       = '';
        $description = '';
        if ($tickets_id > 0) {
            $tkt = new Ticket();
            if ($tkt->getFromDB($tickets_id)) {
                $title = (string) ($tkt->fields['name'] ?? '');
                if (PluginApprovalbymailConfig::isFeatureActive(PluginApprovalbymailConfig::SHOW_DESCRIPTION)) {
                    // Conteúdo cru: o GLPI converte para HTML seguro ou texto
                    // plano conforme o formato do e-mail (getDataForHtml/PlainText).
                    $description = (string) ($tkt->fields['content'] ?? '');
                }
            }
        }

        $this->data['##approvalbymail.url##']               = $url;
        $this->data['##approvalbymail.tickettitle##']       = $title;
        $this->data['##approvalbymail.ticketdescription##'] = $description;

        Toolbox::logInFile(
            'approvalbymail',
            sprintf(
                "op=addDataForTemplate event=%s action_id=%d itemtype=%s url_len=%d title_set=%d desc_len=%d result=ok\n",
                $event,
                (int) ($action->fields['id'] ?? 0),
                $itemtype !== '' ? $itemtype : '-',
                strlen($url),
                $title !== '' ? 1 : 0,
                strlen($description)
            )
        );
    }
}
